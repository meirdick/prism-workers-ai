<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use PrismWorkersAi\Gateway\WorkersAiGateway;

beforeEach(function () {
    config()->set('ai.providers.workers-ai', [
        'driver' => 'workers-ai',
        'key' => 'test-api-key',
        'name' => 'workers-ai',
        'account_id' => 'test-account-123',
    ]);
});

function resolveProvider(array $overrides = []): \PrismWorkersAi\LaravelAi\WorkersAiProvider
{
    foreach ($overrides as $key => $value) {
        config()->set("ai.providers.workers-ai.{$key}", $value);
    }

    // Force fresh instance
    app()->forgetInstance(\Laravel\Ai\AiManager::class);

    return app(\Laravel\Ai\AiManager::class)->instance('workers-ai');
}

// --- URL Construction ---

it('auto-builds direct URL from account_id when no url or gateway set', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response($this->fixture('text-response.json'))]);

    $provider = resolveProvider();

    $provider->textGateway()->generateText(
        $provider,
        '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
        null,
        [new \Laravel\Ai\Messages\UserMessage('Hello!')],
    );

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.cloudflare.com/client/v4/accounts/test-account-123/ai/v1/chat/completions';
    });
});

it('auto-builds AI Gateway URL when gateway config is set', function () {
    Http::fake(['gateway.ai.cloudflare.com/*' => Http::response($this->fixture('text-response.json'))]);

    $provider = resolveProvider(['gateway' => 'my-gateway']);

    $provider->textGateway()->generateText(
        $provider,
        '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
        null,
        [new \Laravel\Ai\Messages\UserMessage('Hello!')],
    );

    Http::assertSent(function ($request) {
        return $request->url() === 'https://gateway.ai.cloudflare.com/v1/test-account-123/my-gateway/workers-ai/v1/chat/completions';
    });
});

it('uses explicit url when set, overriding account_id and gateway', function () {
    Http::fake(['custom.example.com/*' => Http::response($this->fixture('text-response.json'))]);

    $provider = resolveProvider([
        'gateway' => 'my-gateway',
        'url' => 'https://custom.example.com/ai/v1',
    ]);

    $provider->textGateway()->generateText(
        $provider,
        '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
        null,
        [new \Laravel\Ai\Messages\UserMessage('Hello!')],
    );

    Http::assertSent(function ($request) {
        return $request->url() === 'https://custom.example.com/ai/v1/chat/completions';
    });
});

it('uses same model names for direct and gateway paths', function () {
    $model = '@cf/meta/llama-3.3-70b-instruct-fp8-fast';

    // Direct path
    Http::fake(['api.cloudflare.com/*' => Http::response($this->fixture('text-response.json'))]);

    $provider = resolveProvider();

    $provider->textGateway()->generateText(
        $provider,
        $model,
        null,
        [new \Laravel\Ai\Messages\UserMessage('Hello!')],
    );

    Http::assertSent(function ($request) use ($model) {
        $body = json_decode($request->body(), true);

        return $body['model'] === $model;
    });

    // Gateway path — same model name
    Http::fake(['gateway.ai.cloudflare.com/*' => Http::response($this->fixture('text-response.json'))]);

    $provider = resolveProvider(['gateway' => 'my-gw']);

    $provider->textGateway()->generateText(
        $provider,
        $model,
        null,
        [new \Laravel\Ai\Messages\UserMessage('Hello!')],
    );

    Http::assertSent(function ($request) use ($model) {
        $body = json_decode($request->body(), true);

        return $body['model'] === $model;
    });
});

// --- /compat Model Validation ---

it('throws helpful error when /compat URL is used without workers-ai/ prefix', function () {
    $provider = resolveProvider([
        'url' => 'https://gateway.ai.cloudflare.com/v1/abc123/my-gw/compat',
    ]);

    $provider->textGateway()->generateText(
        $provider,
        '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
        null,
        [new \Laravel\Ai\Messages\UserMessage('Hello!')],
    );
})->throws(
    \Laravel\Ai\Exceptions\AiException::class,
    "requires the 'workers-ai/' prefix"
);

it('allows correctly prefixed model names on /compat', function () {
    Http::fake(['gateway.ai.cloudflare.com/*' => Http::response($this->fixture('text-response.json'))]);

    $provider = resolveProvider([
        'url' => 'https://gateway.ai.cloudflare.com/v1/abc123/my-gw/compat',
    ]);

    $provider->textGateway()->generateText(
        $provider,
        'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast',
        null,
        [new \Laravel\Ai\Messages\UserMessage('Hello!')],
    );

    Http::assertSent(function ($request) {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast';
    });
});

it('does not throw on non-compat URL without workers-ai/ prefix', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response($this->fixture('text-response.json'))]);

    $provider = resolveProvider();

    $provider->textGateway()->generateText(
        $provider,
        '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
        null,
        [new \Laravel\Ai\Messages\UserMessage('Hello!')],
    );

    // Should not throw — direct endpoint doesn't need prefix
    expect(true)->toBeTrue();
});

it('throws /compat error for embeddings too', function () {
    $provider = resolveProvider([
        'url' => 'https://gateway.ai.cloudflare.com/v1/abc123/my-gw/compat',
    ]);

    $provider->embeddingGateway()->generateEmbeddings(
        $provider,
        '@cf/baai/bge-large-en-v1.5',
        ['Hello world'],
        1024,
    );
})->throws(
    \Laravel\Ai\Exceptions\AiException::class,
    "requires the 'workers-ai/' prefix"
);

it('error message suggests using gateway config instead', function () {
    $provider = resolveProvider([
        'url' => 'https://gateway.ai.cloudflare.com/v1/abc123/my-gw/compat',
    ]);

    try {
        $provider->textGateway()->generateText(
            $provider,
            '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
            null,
            [new \Laravel\Ai\Messages\UserMessage('Hello!')],
        );

        $this->fail('Expected AiException to be thrown');
    } catch (\Laravel\Ai\Exceptions\AiException $e) {
        expect($e->getMessage())->toContain("'gateway' config option");
        expect($e->getMessage())->toContain('workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast');
    }
});

// --- Gateway config avoids /compat entirely ---

it('gateway config uses workers-ai/v1 path which needs no model prefix', function () {
    Http::fake(['gateway.ai.cloudflare.com/*' => Http::response($this->fixture('text-response.json'))]);

    $provider = resolveProvider(['gateway' => 'my-gateway']);

    $provider->textGateway()->generateText(
        $provider,
        '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
        null,
        [new \Laravel\Ai\Messages\UserMessage('Hello!')],
    );

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/workers-ai/v1/')
            && ! str_contains($request->url(), '/compat');
    });
});

// --- Empty account_id validation ---

it('throws when account_id is empty and no url or gateway configured', function () {
    $provider = resolveProvider(['account_id' => '']);

    $provider->textGateway()->generateText(
        $provider,
        '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
        null,
        [new \Laravel\Ai\Messages\UserMessage('Hello!')],
    );
})->throws(
    \Laravel\Ai\Exceptions\AiException::class,
    'account_id'
);

it('throws when account_id is missing entirely', function () {
    config()->set('ai.providers.workers-ai', [
        'driver' => 'workers-ai',
        'key' => 'test-api-key',
        'name' => 'workers-ai',
    ]);

    app()->forgetInstance(\Laravel\Ai\AiManager::class);
    $provider = app(\Laravel\Ai\AiManager::class)->instance('workers-ai');

    $provider->textGateway()->generateText(
        $provider,
        '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
        null,
        [new \Laravel\Ai\Messages\UserMessage('Hello!')],
    );
})->throws(
    \Laravel\Ai\Exceptions\AiException::class,
    'account_id'
);

// --- Response validation ---

it('throws when response has no choices', function () {
    Http::fake([
        'api.cloudflare.com/*' => Http::response([
            'model' => '@cf/meta/llama-3.1-8b-instruct',
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ]),
    ]);

    $provider = resolveProvider();

    $provider->textGateway()->generateText(
        $provider,
        '@cf/meta/llama-3.1-8b-instruct',
        null,
        [new \Laravel\Ai\Messages\UserMessage('Hello!')],
    );
})->throws(
    \Laravel\Ai\Exceptions\AiException::class,
    'did not contain any choices'
);

// --- max_completion_tokens ---

it('sends max_completion_tokens not max_tokens', function () {
    Http::fake([
        'api.cloudflare.com/*' => Http::response(
            $this->fixture('text-response.json'),
        ),
    ]);

    $provider = resolveProvider();

    $provider->textGateway()->generateText(
        $provider,
        '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
        null,
        [new \Laravel\Ai\Messages\UserMessage('Hello!')],
        [],
        null,
        new \Laravel\Ai\Gateway\TextGenerationOptions(maxTokens: 500),
    );

    Http::assertSent(function ($request) {
        $body = json_decode($request->body(), true);

        return isset($body['max_completion_tokens'])
            && $body['max_completion_tokens'] === 500
            && ! isset($body['max_tokens']);
    });
});

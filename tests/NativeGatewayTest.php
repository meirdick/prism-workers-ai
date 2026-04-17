<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use PrismWorkersAi\Gateway\WorkersAiGateway;

beforeEach(function () {
    config()->set('ai.providers.workers-ai', [
        'driver' => 'workers-ai',
        'key' => 'test-api-key',
        'url' => 'https://gateway.ai.cloudflare.com/v1/test/gateway/compat',
        'name' => 'workers-ai',
    ]);
});

function resolveGatewayProvider(): \PrismWorkersAi\LaravelAi\WorkersAiProvider
{
    $manager = app(\Laravel\Ai\AiManager::class);

    return $manager->instance('workers-ai');
}

it('uses native WorkersAiGateway when laravel/ai supports it', function () {
    $provider = resolveGatewayProvider();

    $reflection = new ReflectionProperty(\Laravel\Ai\Providers\Provider::class, 'gateway');
    $gateway = $reflection->getValue($provider);

    expect($gateway)->toBeInstanceOf(WorkersAiGateway::class);
});

it('generates text via native gateway', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            $this->fixture('text-response.json'),
        ),
    ]);

    $provider = resolveGatewayProvider();

    $response = $provider->textGateway()->generateText(
        $provider,
        'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast',
        'You are helpful.',
        [new \Laravel\Ai\Messages\UserMessage('Hello!')],
    );

    expect($response->text)->toBe('Hello! How can I help you today?');
    expect($response->usage->promptTokens)->toBe(10);
    expect($response->usage->completionTokens)->toBe(8);

    Http::assertSent(function ($request) {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://gateway.ai.cloudflare.com/v1/test/gateway/compat/chat/completions'
            && $body['model'] === 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast'
            && $body['messages'][0]['role'] === 'system'
            && $body['messages'][0]['content'] === 'You are helpful.'
            && $body['messages'][1]['role'] === 'user'
            && is_string($body['messages'][1]['content']);
    });
});

it('sends Bearer token in authorization header', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            $this->fixture('text-response.json'),
        ),
    ]);

    $provider = resolveGatewayProvider();

    $provider->textGateway()->generateText(
        $provider,
        'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast',
        null,
        [new \Laravel\Ai\Messages\UserMessage('Hello!')],
    );

    Http::assertSent(function ($request) {
        return $request->hasHeader('Authorization', 'Bearer test-api-key');
    });
});

it('sends session affinity header when configured', function () {
    config()->set('ai.providers.workers-ai.session_affinity', 'ses_test-session-123');

    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            $this->fixture('text-response.json'),
        ),
    ]);

    $provider = resolveGatewayProvider();

    $provider->textGateway()->generateText(
        $provider,
        'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast',
        null,
        [new \Laravel\Ai\Messages\UserMessage('Hello!')],
    );

    Http::assertSent(function ($request) {
        return $request->hasHeader('x-session-affinity', 'ses_test-session-123');
    });
});

it('coerces user message content to string', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            $this->fixture('text-response.json'),
        ),
    ]);

    $provider = resolveGatewayProvider();

    $provider->textGateway()->generateText(
        $provider,
        'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast',
        null,
        [new \Laravel\Ai\Messages\UserMessage('Hello!')],
    );

    Http::assertSent(function ($request) {
        $body = json_decode($request->body(), true);
        $userMessage = collect($body['messages'])->firstWhere('role', 'user');

        return is_string($userMessage['content']);
    });
});

it('streams text and emits correct events', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            file_get_contents(__DIR__.'/Fixtures/stream-response.txt'),
            200,
            ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $provider = resolveGatewayProvider();

    $events = iterator_to_array($provider->textGateway()->streamText(
        'inv-1',
        $provider,
        'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast',
        null,
        [new \Laravel\Ai\Messages\UserMessage('Hello!')],
    ));

    $eventTypes = array_map(fn ($e) => get_class($e), $events);

    expect($eventTypes)->toContain(StreamStart::class);
    expect($eventTypes)->toContain(TextStart::class);
    expect($eventTypes)->toContain(TextDelta::class);
    expect($eventTypes)->toContain(TextEnd::class);
    expect($eventTypes)->toContain(StreamEnd::class);

    // Check text content
    $textDeltas = array_filter($events, fn ($e) => $e instanceof TextDelta);
    $fullText = implode('', array_map(fn ($e) => $e->delta, $textDeltas));
    expect($fullText)->toBe('Hello!');
});

it('streams reasoning events for thinking models', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            file_get_contents(__DIR__.'/Fixtures/reasoning-stream-response.txt'),
            200,
            ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $provider = resolveGatewayProvider();

    $events = iterator_to_array($provider->textGateway()->streamText(
        'inv-2',
        $provider,
        'workers-ai/@cf/moonshotai/kimi-k2.5',
        null,
        [new \Laravel\Ai\Messages\UserMessage('Say hello in one sentence.')],
    ));

    $eventTypes = array_map(fn ($e) => get_class($e), $events);

    expect($eventTypes)->toContain(ReasoningStart::class);
    expect($eventTypes)->toContain(ReasoningDelta::class);
    expect($eventTypes)->toContain(ReasoningEnd::class);
    expect($eventTypes)->toContain(TextStart::class);
    expect($eventTypes)->toContain(TextDelta::class);

    // Reasoning comes before text
    $reasoningStartIdx = array_search(ReasoningStart::class, $eventTypes);
    $textStartIdx = array_search(TextStart::class, $eventTypes);
    expect($reasoningStartIdx)->toBeLessThan($textStartIdx);

    // Check reasoning content
    $reasoningDeltas = array_filter($events, fn ($e) => $e instanceof ReasoningDelta);
    $reasoning = implode('', array_map(fn ($e) => $e->delta, $reasoningDeltas));
    expect($reasoning)->toContain('The user wants');
});

it('generates embeddings via native gateway', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            $this->fixture('embeddings-response.json'),
        ),
    ]);

    $provider = resolveGatewayProvider();

    $response = $provider->embeddingGateway()->generateEmbeddings(
        $provider,
        'workers-ai/@cf/baai/bge-large-en-v1.5',
        ['Hello world'],
        1024,
    );

    expect($response->embeddings)->toBeArray();
    expect($response->embeddings)->not->toBeEmpty();

    Http::assertSent(function ($request) {
        $body = json_decode($request->body(), true);

        return str_contains($request->url(), '/embeddings')
            && $body['model'] === 'workers-ai/@cf/baai/bge-large-en-v1.5'
            && $body['input'] === ['Hello world'];
    });
});

it('builds structured output requests with json_schema', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            $this->fixture('structured-response.json'),
        ),
    ]);

    $provider = resolveGatewayProvider();

    $provider->textGateway()->generateText(
        $provider,
        'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast',
        null,
        [new \Laravel\Ai\Messages\UserMessage('What is the weather?')],
        [],
        ['temperature' => (new \Illuminate\JsonSchema\JsonSchemaTypeFactory)->string()],
    );

    Http::assertSent(function ($request) {
        $body = json_decode($request->body(), true);

        return isset($body['response_format']['type'])
            && $body['response_format']['type'] === 'json_schema';
    });
});

it('throws LogicException for unsupported operations', function () {
    $gateway = new WorkersAiGateway(app('events'));

    expect(fn () => $gateway->generateImage(
        Mockery::mock(\Laravel\Ai\Contracts\Providers\ImageProvider::class),
        'model', 'prompt'
    ))->toThrow(LogicException::class);

    expect(fn () => $gateway->generateAudio(
        Mockery::mock(\Laravel\Ai\Contracts\Providers\AudioProvider::class),
        'model', 'text', 'voice'
    ))->toThrow(LogicException::class);

    expect(fn () => $gateway->generateTranscription(
        Mockery::mock(\Laravel\Ai\Contracts\Providers\TranscriptionProvider::class),
        'model',
        Mockery::mock(\Laravel\Ai\Contracts\Files\TranscribableAudio::class),
    ))->toThrow(LogicException::class);
});

it('handles error responses', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            $this->fixture('error-response.json'),
        ),
    ]);

    $provider = resolveGatewayProvider();

    expect(fn () => $provider->textGateway()->generateText(
        $provider,
        'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast',
        null,
        [new \Laravel\Ai\Messages\UserMessage('Hello!')],
    ))->toThrow(\Laravel\Ai\Exceptions\AiException::class);
});

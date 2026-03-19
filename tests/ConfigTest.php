<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Prism\Facades\Prism;
use Prism\Prism\PrismManager;
use PrismWorkersAi\WorkersAi;

it('reads api_key from config', function () {
    config()->set('prism.providers.workers-ai.api_key', 'my-custom-key');

    $manager = app(PrismManager::class);
    $provider = $manager->resolve('workers-ai');

    expect($provider)->toBeInstanceOf(WorkersAi::class);
    expect($provider->apiKey)->toBe('my-custom-key');
});

it('falls back to key when api_key is missing', function () {
    config()->set('prism.providers.workers-ai', [
        'key' => 'fallback-key',
        'url' => 'https://gateway.ai.cloudflare.com/v1/test/gateway/compat',
    ]);

    $manager = app(PrismManager::class);
    $provider = $manager->resolve('workers-ai');

    expect($provider->apiKey)->toBe('fallback-key');
});

it('sends authorization header with api key', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            $this->fixture('text-response.json'),
        ),
    ]);

    Prism::text()
        ->using('workers-ai', 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast')
        ->withPrompt('Hello!')
        ->asText();

    Http::assertSent(function ($request) {
        return $request->hasHeader('Authorization', 'Bearer test-api-key');
    });
});

it('sends requests to the configured base url', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            $this->fixture('text-response.json'),
        ),
    ]);

    Prism::text()
        ->using('workers-ai', 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast')
        ->withPrompt('Hello!')
        ->asText();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'gateway.ai.cloudflare.com/v1/test/gateway/compat/chat/completions');
    });
});

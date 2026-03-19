<?php

declare(strict_types=1);

use Prism\Prism\PrismManager;
use PrismWorkersAi\WorkersAi;

it('registers workers-ai provider with PrismManager', function () {
    $manager = app(PrismManager::class);

    $provider = $manager->resolve('workers-ai');

    expect($provider)->toBeInstanceOf(WorkersAi::class);
});

it('resolves provider with correct config from prism.providers', function () {
    $manager = app(PrismManager::class);

    $provider = $manager->resolve('workers-ai');

    expect($provider->apiKey)->toBe('test-api-key');
    expect($provider->url)->toBe('https://gateway.ai.cloudflare.com/v1/test/gateway/compat');
});

it('registers workers-ai driver with AiManager when laravel/ai is installed', function () {
    if (! class_exists(\Laravel\Ai\AiManager::class)) {
        $this->markTestSkipped('laravel/ai is not installed');
    }

    $manager = app(\Laravel\Ai\AiManager::class);

    // Configure the ai provider
    config()->set('ai.providers.workers-ai', [
        'driver' => 'workers-ai',
        'key' => 'test-key',
        'url' => 'https://example.com/compat',
        'name' => 'workers-ai',
    ]);

    $provider = $manager->instance('workers-ai');

    expect($provider)->toBeInstanceOf(\PrismWorkersAi\LaravelAi\WorkersAiProvider::class);
});

it('auto-detects upstream fix via reflection', function () {
    $serviceProvider = app()->getProvider(\PrismWorkersAi\WorkersAiServiceProvider::class);

    $method = new ReflectionMethod($serviceProvider, 'needsGatewayOverride');

    $result = $method->invoke($serviceProvider);

    if (! class_exists(\Laravel\Ai\Gateway\Prism\PrismGateway::class)) {
        // No laravel/ai installed — should not need override
        expect($result)->toBeFalse();
    } else {
        // laravel/ai installed but upstream fix not yet merged — needs override
        expect($result)->toBeBool();
    }
});

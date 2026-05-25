<?php

declare(strict_types=1);

use PrismWorkersAi\WorkersAi;

// Helper so each Prism-specific test self-skips when prism-php/prism is
// genuinely absent from vendor/. Lets the same file run under the default,
// prism-only, and no-prism CI jobs.
function skipIfPrismMissing(\PHPUnit\Framework\TestCase $test): void
{
    if (! class_exists(\Prism\Prism\PrismManager::class)) {
        $test->markTestSkipped('prism-php/prism is not installed in this matrix job');
    }
}

it('registers workers-ai provider with PrismManager', function () {
    skipIfPrismMissing($this);

    $manager = app(\Prism\Prism\PrismManager::class);

    $provider = $manager->resolve('workers-ai');

    expect($provider)->toBeInstanceOf(WorkersAi::class);
});

it('resolves provider with correct config from prism.providers', function () {
    skipIfPrismMissing($this);

    $manager = app(\Prism\Prism\PrismManager::class);

    $provider = $manager->resolve('workers-ai');

    expect($provider->apiKey)->toBe('test-api-key');
    expect($provider->url)->toBe('https://gateway.ai.cloudflare.com/v1/test/gateway/compat');
});

it('registers workers-ai driver with AiManager when laravel/ai is installed', function () {
    if (! class_exists(\Laravel\Ai\AiManager::class)) {
        $this->markTestSkipped('laravel/ai is not installed');
    }

    config()->set('ai.providers.workers-ai', [
        'driver' => 'workers-ai',
        'key' => 'test-key',
        'url' => 'https://example.com/compat',
        'name' => 'workers-ai',
    ]);

    $manager = app(\Laravel\Ai\AiManager::class);

    $provider = $manager->instance('workers-ai');

    expect($provider)->toBeInstanceOf(\PrismWorkersAi\LaravelAi\WorkersAiProvider::class);
});

it('registers dashless "workersai" alias with PrismManager', function () {
    skipIfPrismMissing($this);

    $manager = app(\Prism\Prism\PrismManager::class);

    $provider = $manager->resolve('workersai');

    expect($provider)->toBeInstanceOf(WorkersAi::class);
});

it('registers dashless "workersai" alias with AiManager when laravel/ai is installed', function () {
    if (! class_exists(\Laravel\Ai\AiManager::class)) {
        $this->markTestSkipped('laravel/ai is not installed');
    }

    config()->set('ai.providers.workersai', [
        'driver' => 'workersai',
        'key' => 'test-key',
        'url' => 'https://example.com/compat',
        'name' => 'workersai',
    ]);

    $manager = app(\Laravel\Ai\AiManager::class);

    $provider = $manager->instance('workersai');

    expect($provider)->toBeInstanceOf(\PrismWorkersAi\LaravelAi\WorkersAiProvider::class);
});

it('lazy-instantiates a native gateway on the WorkersAiProvider', function () {
    if (! class_exists(\Laravel\Ai\AiManager::class)) {
        $this->markTestSkipped('laravel/ai is not installed');
    }

    config()->set('ai.providers.workers-ai', [
        'driver' => 'workers-ai',
        'key' => 'test-key',
        'account_id' => 'test-account',
        'name' => 'workers-ai',
    ]);

    $provider = app(\Laravel\Ai\AiManager::class)->instance('workers-ai');

    expect($provider->textGateway())->toBeInstanceOf(\PrismWorkersAi\LaravelAi\Gateway\WorkersAiGateway::class);
    expect($provider->embeddingGateway())->toBeInstanceOf(\PrismWorkersAi\LaravelAi\Gateway\WorkersAiGateway::class);
    expect($provider->textGateway())->toBe($provider->embeddingGateway());
});

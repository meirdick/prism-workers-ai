<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Prism;
use Prism\Prism\PrismManager;
use PrismWorkersAi\WorkersAi;

/**
 * Graceful-degradation guard for laravel/ai ^0.6+, which removed the
 * `PrismGateway` class this package's bridge extends. Under 0.6+:
 *
 *   - `AiManager` still exists
 *   - `Laravel\Ai\Gateway\Prism\PrismGateway` does NOT
 *
 * The service provider must: (a) log a warning, (b) not register the AI
 * bridge, (c) leave Prism-side registration (including `workersai` alias)
 * fully intact so `Prism::text()->using('workers-ai', ...)` keeps working.
 *
 * These tests run on every laravel/ai version but only do real work when
 * PrismGateway is missing (v0.6+). On v0.3–v0.5 they're no-ops via skip.
 */

it('does not throw class-not-found or TypeError when booting on laravel/ai v0.6+', function () {
    if (class_exists(\Laravel\Ai\Gateway\Prism\PrismGateway::class)) {
        $this->markTestSkipped('PrismGateway still present — not v0.6+');
    }

    // Boot already happened in the test harness. If it had thrown, we wouldn't
    // be here. This is an explicit pin that the service provider is tolerant.
    expect(app()->getProvider(\PrismWorkersAi\WorkersAiServiceProvider::class))
        ->not->toBeNull();
});

it('keeps Prism provider registration intact on laravel/ai v0.6+', function () {
    if (class_exists(\Laravel\Ai\Gateway\Prism\PrismGateway::class)) {
        $this->markTestSkipped('PrismGateway still present — not v0.6+');
    }

    $manager = app(PrismManager::class);

    expect($manager->resolve(WorkersAi::KEY))->toBeInstanceOf(WorkersAi::class);
    expect($manager->resolve(WorkersAi::KEY_ALIAS))->toBeInstanceOf(WorkersAi::class);
});

it('logs a graceful-degradation warning on laravel/ai v0.6+ boot', function () {
    if (class_exists(\Laravel\Ai\Gateway\Prism\PrismGateway::class)) {
        $this->markTestSkipped('PrismGateway still present — not v0.6+');
    }

    Log::spy();

    // Re-run registerWithLaravelAi by booting a fresh service provider.
    $provider = new \PrismWorkersAi\WorkersAiServiceProvider(app());
    $method = new ReflectionMethod($provider, 'registerWithLaravelAi');
    $method->invoke($provider);

    Log::shouldHaveReceived('warning')->once();
});

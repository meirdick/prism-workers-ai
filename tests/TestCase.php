<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as BaseTestCase;
use PrismWorkersAi\WorkersAiServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Fail loudly if any test accidentally hits a real HTTP endpoint —
        // every request must go through Http::fake().
        Http::preventStrayRequests();
    }

    /**
     * Register the package providers in scope. Prism's and Laravel AI's
     * service providers are only added when their packages are installed,
     * so the same test harness works under three composer configurations:
     *
     *   - Both installed (default dev pin)
     *   - Prism only (the `prism-only` CI job)
     *   - laravel/ai only (the `no-prism` CI job — proves the package
     *     genuinely doesn't need Prism for the agent() integration)
     */
    protected function getPackageProviders($app): array
    {
        $providers = [WorkersAiServiceProvider::class];

        if (class_exists(\Prism\Prism\PrismServiceProvider::class)) {
            array_unshift($providers, \Prism\Prism\PrismServiceProvider::class);
        }

        if (class_exists(\Laravel\Ai\AiServiceProvider::class)) {
            $providers[] = \Laravel\Ai\AiServiceProvider::class;
        }

        return $providers;
    }

    protected function defineEnvironment($app): void
    {
        // Prism-side config is only meaningful when Prism is installed;
        // setting it is harmless either way.
        $app['config']->set('prism.providers.workers-ai', [
            'api_key' => 'test-api-key',
            'url' => 'https://gateway.ai.cloudflare.com/v1/test/gateway/compat',
        ]);

        // Default config for the Laravel AI bridge tests. Individual tests can
        // override (e.g. swapping in `gateway` or explicit `url`) via config().
        $app['config']->set('ai.providers.workersai', [
            'driver' => 'workersai',
            'name' => 'workersai',
            'key' => 'test-key',
            'account_id' => 'test-account',
        ]);
    }

    protected function fixture(string $name): array
    {
        return json_decode(
            file_get_contents(__DIR__.'/Fixtures/'.$name),
            true
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Prism\Prism\PrismServiceProvider;
use PrismWorkersAi\WorkersAiServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        $providers = [
            PrismServiceProvider::class,
            WorkersAiServiceProvider::class,
        ];

        if (class_exists(\Laravel\Ai\AiServiceProvider::class)) {
            $providers[] = \Laravel\Ai\AiServiceProvider::class;
        }

        return $providers;
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('prism.providers.workers-ai', [
            'api_key' => 'test-api-key',
            'url' => 'https://gateway.ai.cloudflare.com/v1/test/gateway/compat',
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

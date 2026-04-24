<?php

declare(strict_types=1);

namespace PrismWorkersAi;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Prism\Prism\PrismManager;
use ReflectionMethod;
use ReflectionUnionType;

class WorkersAiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->registerWithPrism();
        $this->registerWithLaravelAi();
    }

    /**
     * Register the workers-ai provider with Prism's PrismManager.
     *
     * This enables: Prism::text()->using('workers-ai', $model)->asText()
     * Also registered under the dashless alias "workersai".
     */
    protected function registerWithPrism(): void
    {
        $this->app->extend(PrismManager::class, function (PrismManager $manager) {
            $creator = function ($app, array $config): WorkersAi {
                // Alias resolution: fall back to the primary `workers-ai` config
                // block when the alias block is missing `url`, so users don't
                // duplicate config. Caller-provided values win on key collision.
                if (empty($config['url'])) {
                    $primary = $app['config']->get('prism.providers.'.WorkersAi::KEY, []);
                    $config = array_merge($primary, $config);
                }

                return new WorkersAi(
                    apiKey: $config['api_key'] ?? $config['key'] ?? '',
                    url: $config['url'] ?? '',
                    retryEnabled: (bool) ($config['retry'] ?? true),
                );
            };

            $manager->extend(WorkersAi::KEY, $creator);
            $manager->extend(WorkersAi::KEY_ALIAS, $creator);

            return $manager;
        });
    }

    /**
     * Register the workers-ai driver with Laravel AI SDK's AiManager.
     *
     * This enables: agent()->prompt('Hello', provider: 'workers-ai')
     * Also registered under the dashless alias "workersai".
     *
     * When laravel/ai natively supports custom Prism providers (i.e.
     * toPrismProvider() returns string|PrismProvider), we skip the
     * gateway override and use the standard PrismGateway directly.
     *
     * @see https://github.com/laravel/ai/issues/283
     */
    protected function registerWithLaravelAi(): void
    {
        if (! class_exists(\Laravel\Ai\AiManager::class)) {
            return;
        }

        if (! class_exists(\Laravel\Ai\Gateway\Prism\PrismGateway::class)) {
            Log::warning(
                'meirdick/prism-workers-ai: laravel/ai v0.6+ removed PrismGateway. '
                . 'The agent()->prompt(provider: "workers-ai") integration is disabled in this release. '
                . 'Prism standalone (Prism::text()->using("workers-ai", ...)) continues to work. '
                . 'prism-workers-ai v0.5.0 (upcoming) will ship a native Laravel AI gateway — '
                . 'pin to v0.5.0+ when it lands, or stay on laravel/ai v0.5 to keep agent() working today. '
                . 'See https://github.com/meirdick/prism-workers-ai/blob/main/UPGRADING.md'
            );

            return;
        }

        $useOverride = $this->needsGatewayOverride();

        $this->app->afterResolving(\Laravel\Ai\AiManager::class, function ($manager) use ($useOverride) {
            $creator = function ($app, array $config) use ($useOverride) {
                $gateway = $useOverride
                    ? new LaravelAi\WorkersAiGateway($app['events'])
                    : new \Laravel\Ai\Gateway\Prism\PrismGateway($app['events']);

                return new LaravelAi\WorkersAiProvider(
                    $gateway,
                    $config,
                    $app->make(\Illuminate\Contracts\Events\Dispatcher::class),
                );
            };

            $manager->extend(WorkersAi::KEY, $creator);
            $manager->extend(WorkersAi::KEY_ALIAS, $creator);
        });
    }

    /**
     * Check whether PrismGateway::toPrismProvider() still requires the
     * gateway override. Returns false once laravel/ai merges support
     * for custom providers (return type becomes PrismProvider|string).
     */
    protected function needsGatewayOverride(): bool
    {
        if (! class_exists(\Laravel\Ai\Gateway\Prism\PrismGateway::class)) {
            return false;
        }

        $method = new ReflectionMethod(
            \Laravel\Ai\Gateway\Prism\PrismGateway::class,
            'toPrismProvider'
        );

        // If the return type is a union (PrismProvider|string), the
        // upstream fix landed — no override needed.
        return ! ($method->getReturnType() instanceof ReflectionUnionType);
    }
}

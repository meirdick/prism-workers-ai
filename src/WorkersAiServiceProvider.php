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
     */
    protected function registerWithPrism(): void
    {
        $this->app->extend(PrismManager::class, function (PrismManager $manager) {
            $manager->extend(WorkersAi::KEY, fn ($app, array $config): WorkersAi => new WorkersAi(
                apiKey: $config['api_key'] ?? $config['key'] ?? '',
                url: $config['url'] ?? '',
            ));

            return $manager;
        });
    }

    /**
     * Register the workers-ai driver with Laravel AI SDK's AiManager.
     *
     * This enables: agent()->prompt('Hello', provider: 'workers-ai')
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
                'meirdick/prism-workers-ai: PrismGateway has been removed from laravel/ai. '
                . 'The workers-ai Laravel AI integration is disabled until a direct gateway is available. '
                . 'Prism standalone (Prism::text()->using("workers-ai", ...)) still works. '
                . 'Update meirdick/prism-workers-ai for a compatible version.'
            );

            return;
        }

        $useOverride = $this->needsGatewayOverride();

        $this->app->afterResolving(\Laravel\Ai\AiManager::class, function ($manager) use ($useOverride) {
            $manager->extend(WorkersAi::KEY, function ($app, array $config) use ($useOverride) {
                $gateway = $useOverride
                    ? new LaravelAi\WorkersAiGateway($app['events'])
                    : new \Laravel\Ai\Gateway\Prism\PrismGateway($app['events']);

                return new LaravelAi\WorkersAiProvider(
                    $gateway,
                    $config,
                    $app->make(\Illuminate\Contracts\Events\Dispatcher::class),
                );
            });
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

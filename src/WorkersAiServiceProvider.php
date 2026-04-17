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
     * Gateway selection priority:
     * 1. Native WorkersAiGateway — when laravel/ai has InvokesTools trait (v0.5+)
     * 2. Custom PrismGateway override — when toPrismProvider() doesn't support strings
     * 3. Standard PrismGateway — when laravel/ai natively supports custom providers
     */
    protected function registerWithLaravelAi(): void
    {
        if (! class_exists(\Laravel\Ai\AiManager::class)) {
            return;
        }

        $useNative = $this->supportsNativeGateway();

        if (! $useNative && ! class_exists(\Laravel\Ai\Gateway\Prism\PrismGateway::class)) {
            Log::warning(
                'meirdick/prism-workers-ai: PrismGateway has been removed from laravel/ai '
                . 'but native gateway prerequisites are missing. '
                . 'The workers-ai Laravel AI integration is disabled. '
                . 'Update meirdick/prism-workers-ai for a compatible version.'
            );

            return;
        }

        $useOverride = ! $useNative && $this->needsGatewayOverride();

        $this->app->afterResolving(\Laravel\Ai\AiManager::class, function ($manager) use ($useNative, $useOverride) {
            $manager->extend(WorkersAi::KEY, function ($app, array $config) use ($useNative, $useOverride) {
                $dispatcher = $app->make(\Illuminate\Contracts\Events\Dispatcher::class);

                if ($useNative) {
                    $gateway = new Gateway\WorkersAiGateway($dispatcher);

                    return new LaravelAi\WorkersAiProvider(
                        $gateway,
                        $config,
                        $dispatcher,
                    );
                }

                $gateway = $useOverride
                    ? new LaravelAi\WorkersAiGateway($app['events'])
                    : new \Laravel\Ai\Gateway\Prism\PrismGateway($app['events']);

                return new LaravelAi\WorkersAiProvider(
                    $gateway,
                    $config,
                    $dispatcher,
                );
            });
        });
    }

    /**
     * Check whether laravel/ai supports the native gateway pattern.
     *
     * The InvokesTools trait was introduced alongside native gateways in v0.5.
     * Its presence indicates laravel/ai has the required interfaces and
     * shared traits (HandlesFailoverErrors, ParsesServerSentEvents, etc.).
     */
    protected function supportsNativeGateway(): bool
    {
        return trait_exists(\Laravel\Ai\Gateway\Concerns\InvokesTools::class);
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

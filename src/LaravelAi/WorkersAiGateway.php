<?php

declare(strict_types=1);

namespace PrismWorkersAi\LaravelAi;

use Laravel\Ai\Gateway\Prism\PrismGateway;
use Laravel\Ai\Providers\Provider;
use Prism\Prism\Enums\Provider as PrismProvider;
use PrismWorkersAi\WorkersAi;

/**
 * Extends PrismGateway to support the workers-ai driver.
 *
 * Overrides configure() to pass the driver name as a string to Prism's
 * using() method when the driver is 'workers-ai', bypassing the
 * toPrismProvider() enum restriction. For all other drivers, delegates
 * to the parent implementation.
 *
 * This class becomes unnecessary once laravel/ai supports extensible
 * provider mapping (see https://github.com/laravel/ai/issues/283).
 */
class WorkersAiGateway extends PrismGateway
{
    /**
     * Configure the given pending Prism request for the provider.
     *
     * For workers-ai, pass the driver name as a string directly to
     * Prism's using() method (which accepts string|ProviderEnum).
     * PrismManager resolves it via the custom creator registered
     * by WorkersAiServiceProvider.
     */
    protected function configure($prism, Provider $provider, string $model): mixed
    {
        if ($provider->driver() === WorkersAi::KEY) {
            return $prism->using(
                WorkersAi::KEY,
                $model,
                array_filter([
                    ...$provider->additionalConfiguration(),
                    'api_key' => $provider->providerCredentials()['key'],
                ]),
            );
        }

        return parent::configure($prism, $provider, $model);
    }
}

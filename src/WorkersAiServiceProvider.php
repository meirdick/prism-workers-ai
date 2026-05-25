<?php

declare(strict_types=1);

namespace PrismWorkersAi;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use PrismWorkersAi\Cloudflare\BaseUrl;

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
     * Enables: Prism::text()->using('workers-ai', $model)->asText()
     * Also registered under the dashless alias "workersai".
     */
    protected function registerWithPrism(): void
    {
        // prism-php/prism is an optional dependency as of v0.5.0. Skip the
        // Prism manager registration entirely when Prism isn't installed —
        // the Laravel AI bridge still wires up via its own path. Users who
        // want `Prism::text()->using('workers-ai', ...)` need to add
        // prism-php/prism to their own composer.json.
        if (! class_exists(\Prism\Prism\PrismManager::class)) {
            return;
        }

        $this->app->extend(\Prism\Prism\PrismManager::class, function (\Prism\Prism\PrismManager $manager) {
            $creator = function ($app, array $config): WorkersAi {
                // Alias resolution: when the alias block is missing config keys,
                // fall back to the primary `workers-ai` block so users don't
                // duplicate config. Caller-provided values win on key collision.
                if (empty($config['url']) && empty($config['account_id'])) {
                    $primary = $app['config']->get('prism.providers.'.WorkersAiKey::PRIMARY, []);
                    $config = array_merge($primary, $config);
                }

                // BaseUrl handles all three shapes (url | account_id+gateway |
                // account_id) with the same validation as the Laravel AI path.
                // Empty config returns '' for backward compat with prior versions
                // that allowed instantiation before any request.
                $url = (! empty($config['url']) || ! empty($config['account_id']))
                    ? BaseUrl::build($config)
                    : '';

                return new WorkersAi(
                    apiKey: $config['api_key'] ?? $config['key'] ?? '',
                    url: $url,
                    retryEnabled: (bool) ($config['retry'] ?? true),
                );
            };

            $manager->extend(WorkersAiKey::PRIMARY, $creator);
            $manager->extend(WorkersAiKey::ALIAS, $creator);

            return $manager;
        });
    }

    /**
     * Register the workers-ai driver with Laravel AI SDK's AiManager.
     *
     * Enables: agent()->prompt('Hello', provider: 'workers-ai')
     *
     * Uses our own native gateway (PrismWorkersAi\LaravelAi\Gateway\WorkersAiGateway)
     * — no longer extends laravel/ai's PrismGateway, which was removed upstream
     * in v0.6.1. Works on laravel/ai v0.5 and v0.6+ via the stable TextGateway
     * and EmbeddingGateway contracts.
     */
    protected function registerWithLaravelAi(): void
    {
        if (! class_exists(\Laravel\Ai\AiManager::class)) {
            return;
        }

        $this->app->afterResolving(\Laravel\Ai\AiManager::class, function ($manager) {
            $creator = fn ($app, array $config) => new LaravelAi\WorkersAiProvider(
                $config,
                $app->make(Dispatcher::class),
            );

            $manager->extend(WorkersAiKey::PRIMARY, $creator);
            $manager->extend(WorkersAiKey::ALIAS, $creator);
        });
    }
}

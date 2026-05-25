<?php

namespace PrismWorkersAi\LaravelAi\Gateway\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Providers\Provider;
use PrismWorkersAi\Cloudflare\BaseUrl;
use PrismWorkersAi\Cloudflare\ModelPrefix;
use PrismWorkersAi\Cloudflare\RetryPolicy;

trait CreatesWorkersAiClient
{
    /**
     * Get an HTTP client for the Workers AI API.
     *
     * Retries transient gateway failures (cURL 6/7/28/56, HTTP 502/503/504) per
     * Cloudflare\RetryPolicy — the same policy the Prism path uses, so both
     * integrations share a single retry surface. Set `retry => false` in the
     * provider config to disable.
     */
    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        $additionalConfig = $provider->additionalConfiguration();

        $client = Http::baseUrl($this->baseUrl($provider))
            ->withToken($provider->providerCredentials()['key'])
            ->timeout($timeout ?? 60)
            ->throw();

        if (($additionalConfig['retry'] ?? true) !== false) {
            $client = $client->retry(...RetryPolicy::defaults());
        }

        if (! empty($additionalConfig['session_affinity'])) {
            $client->withHeaders(['x-session-affinity' => $additionalConfig['session_affinity']]);
        }

        return $client;
    }

    /**
     * Get the base URL for the Workers AI API.
     *
     * Delegates to PrismWorkersAi\Cloudflare\BaseUrl so the Prism path and
     * the Laravel AI path go through the same resolver and surface the same
     * validation errors. Wraps InvalidArgumentException as AiException so
     * laravel/ai's normal error path handles it.
     */
    protected function baseUrl(Provider $provider): string
    {
        try {
            return BaseUrl::build($provider->additionalConfiguration());
        } catch (InvalidArgumentException $e) {
            throw new AiException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Validate model-name/endpoint pairing in both directions.
     *
     * Delegates to ModelPrefix so both integrations enforce the same rule.
     */
    protected function validateModelName(Provider $provider, string $model): void
    {
        try {
            ModelPrefix::validate($this->baseUrl($provider), $model);
        } catch (InvalidArgumentException $e) {
            throw new AiException($e->getMessage(), 0, $e);
        }
    }
}

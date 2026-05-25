<?php

declare(strict_types=1);

namespace PrismWorkersAi;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Prism\Prism\Concerns\InitializesClient;
use Prism\Prism\Embeddings\Request as EmbeddingsRequest;
use Prism\Prism\Embeddings\Response as EmbeddingsResponse;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use PrismWorkersAi\Cloudflare\ModelPrefix;
use PrismWorkersAi\Cloudflare\RetryPolicy;
use PrismWorkersAi\Concerns\ExtractsErrorMessage;
use PrismWorkersAi\Handlers\Embeddings;
use PrismWorkersAi\Handlers\Stream;
use PrismWorkersAi\Handlers\Structured;
use PrismWorkersAi\Handlers\Text;

class WorkersAi extends Provider
{
    use ExtractsErrorMessage, InitializesClient;

    /**
     * Driver-key constants — kept as aliases to {@see WorkersAiKey} for
     * backward compatibility with code that imported them off this class
     * before v0.5.0.
     *
     * Reading either constant autoloads this class, which extends a
     * Prism-backed parent — so referencing them transitively requires
     * `prism-php/prism` to be installed. That defeats the no-Prism promise
     * for Laravel-AI-only consumers. Migrate to
     * `PrismWorkersAi\WorkersAiKey::PRIMARY` / `::ALIAS` for a Prism-free
     * reference. These aliases will be removed in v1.0.
     *
     * @deprecated 0.5.0 Use {@see WorkersAiKey::PRIMARY} instead. Reading
     *             this constant pulls in Prism via autoload.
     */
    public const KEY = WorkersAiKey::PRIMARY;

    /**
     * @deprecated 0.5.0 Use {@see WorkersAiKey::ALIAS} instead.
     */
    public const KEY_ALIAS = WorkersAiKey::ALIAS;

    /**
     * Resolve the wire-format model ID for the configured endpoint.
     *
     * On `/compat` URLs we auto-prefix bare model IDs with `workers-ai/` so
     * application code can stay endpoint-agnostic. On `/v1` URLs we reject
     * IDs that came in with the prefix already — Cloudflare 404s those
     * silently and the upstream error doesn't help the user understand why.
     */
    public function normalizeModel(string $model): string
    {
        ModelPrefix::validate($this->url, $model);

        return ModelPrefix::normalize($this->url, $model);
    }

    public function __construct(
        #[\SensitiveParameter] public readonly string $apiKey,
        public readonly string $url,
        public readonly bool $retryEnabled = true,
    ) {}

    #[\Override]
    public function text(TextRequest $request): TextResponse
    {
        return (new Text($this, $this->client($request->clientOptions(), $request->clientRetry())))->handle($request);
    }

    #[\Override]
    public function stream(TextRequest $request): Generator
    {
        return (new Stream($this, $this->client($request->clientOptions(), $request->clientRetry())))->handle($request);
    }

    #[\Override]
    public function structured(StructuredRequest $request): StructuredResponse
    {
        return (new Structured($this, $this->client($request->clientOptions(), $request->clientRetry())))->handle($request);
    }

    #[\Override]
    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        return (new Embeddings($this, $this->client($request->clientOptions(), $request->clientRetry())))->handle($request);
    }

    #[\Override]
    public function handleRequestException(string $model, RequestException $e): never
    {
        match ($e->response->getStatusCode()) {
            429 => throw PrismRateLimitedException::make([]),
            default => $this->handleResponseErrors($e),
        };
    }

    protected function handleResponseErrors(RequestException $e): never
    {
        $data = $e->response->json() ?? [];

        throw PrismException::providerRequestErrorWithDetails(
            provider: 'WorkersAI',
            statusCode: $e->response->getStatusCode(),
            errorType: data_get($data, 'error.type'),
            errorMessage: $this->extractErrorMessage($data),
            previous: $e
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<mixed>  $retry
     */
    protected function client(array $options = [], array $retry = []): PendingRequest
    {
        $effectiveRetry = $retry !== [] ? $retry : ($this->retryEnabled ? self::defaultRetry() : null);

        return $this->baseClient()
            ->when($this->apiKey, fn ($client) => $client->withToken($this->apiKey))
            ->withOptions($options)
            ->when($effectiveRetry !== null, fn ($client) => $client->retry(...$effectiveRetry))
            ->baseUrl($this->url);
    }

    /**
     * Retries transient network/gateway errors (cURL 6/7/28/56, HTTP 502/503/504).
     * Delegates to the shared Cloudflare\RetryPolicy so the Laravel AI gateway
     * applies the same defaults. Override per-request via `withClientRetry(...)`.
     *
     * @return array<mixed>
     */
    protected static function defaultRetry(): array
    {
        return RetryPolicy::defaults();
    }
}

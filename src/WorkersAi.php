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
use PrismWorkersAi\Concerns\ExtractsErrorMessage;
use PrismWorkersAi\Handlers\Embeddings;
use PrismWorkersAi\Handlers\Stream;
use PrismWorkersAi\Handlers\Structured;
use PrismWorkersAi\Handlers\Text;

class WorkersAi extends Provider
{
    use ExtractsErrorMessage, InitializesClient;

    const KEY = 'workers-ai';

    /**
     * Dashless alias for KEY. Every other provider in the Laravel AI ecosystem
     * (openai, anthropic, xai, gemini, groq, mistral, deepseek) is a single
     * lowercase token, so users naturally reach for "workersai" — especially
     * inside defensive try/catch blocks that would swallow "driver not
     * supported" errors and silently degrade a feature.
     */
    const KEY_ALIAS = 'workersai';

    /**
     * Ensure the model string includes the workers-ai/ prefix required by
     * the Cloudflare AI Gateway /compat endpoint.
     */
    public function normalizeModel(string $model): string
    {
        if ($this->modelPrefix !== '' && ! str_starts_with($model, $this->modelPrefix)) {
            return $this->modelPrefix.$model;
        }

        return $model;
    }

    public function __construct(
        #[\SensitiveParameter] public readonly string $apiKey,
        public readonly string $url,
        public readonly bool $retryEnabled = true,
        public string $modelPrefix = '',
    ) {
        if ($this->modelPrefix === '') {
            // Auto-detect prefix based on endpoint: /compat needs workers-ai/
            $this->modelPrefix = str_ends_with($this->url, '/compat') ? self::KEY.'/' : '';
        }
    }

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
     * Users can override by passing their own config via `withClientRetry(...)` on
     * the Prism request.
     *
     * @return array<mixed>
     */
    protected static function defaultRetry(): array
    {
        return [
            3,
            500,
            function (\Throwable $exception): bool {
                if ($exception instanceof \Illuminate\Http\Client\ConnectionException) {
                    return true;
                }

                if ($exception instanceof RequestException) {
                    return in_array($exception->response->getStatusCode(), [502, 503, 504], true);
                }

                return false;
            },
            true,
        ];
    }
}

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
use PrismWorkersAi\Handlers\Embeddings;
use PrismWorkersAi\Handlers\Stream;
use PrismWorkersAi\Handlers\Structured;
use PrismWorkersAi\Handlers\Text;

class WorkersAi extends Provider
{
    use InitializesClient;

    const KEY = 'workers-ai';

    public function __construct(
        #[\SensitiveParameter] public readonly string $apiKey,
        public readonly string $url,
    ) {}

    #[\Override]
    public function text(TextRequest $request): TextResponse
    {
        return (new Text($this->client($request->clientOptions(), $request->clientRetry())))->handle($request);
    }

    #[\Override]
    public function stream(TextRequest $request): Generator
    {
        return (new Stream($this->client($request->clientOptions(), $request->clientRetry())))->handle($request);
    }

    #[\Override]
    public function structured(StructuredRequest $request): StructuredResponse
    {
        return (new Structured($this->client($request->clientOptions(), $request->clientRetry())))->handle($request);
    }

    #[\Override]
    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        return (new Embeddings($this->client($request->clientOptions(), $request->clientRetry())))->handle($request);
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
            errorMessage: data_get($data, 'error.message'),
            previous: $e
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<mixed>  $retry
     */
    protected function client(array $options = [], array $retry = []): PendingRequest
    {
        return $this->baseClient()
            ->when($this->apiKey, fn ($client) => $client->withToken($this->apiKey))
            ->withOptions($options)
            ->when($retry !== [], fn ($client) => $client->retry(...$retry))
            ->baseUrl($this->url);
    }
}

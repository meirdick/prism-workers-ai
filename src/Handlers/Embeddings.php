<?php

declare(strict_types=1);

namespace PrismWorkersAi\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Prism\Prism\Embeddings\Request;
use Prism\Prism\Embeddings\Response as EmbeddingsResponse;
use Prism\Prism\ValueObjects\Embedding;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\Meta;
use PrismWorkersAi\Concerns\ValidatesResponses;

class Embeddings
{
    use ValidatesResponses;

    public function __construct(
        protected \PrismWorkersAi\WorkersAi $provider,
        protected PendingRequest $client
    ) {}

    public function handle(Request $request): EmbeddingsResponse
    {
        $response = $this->sendRequest($request);

        $this->validateResponse($response);

        $data = $response->json();

        return new EmbeddingsResponse(
            embeddings: array_map(
                fn (array $item): Embedding => Embedding::fromArray($item['embedding']),
                data_get($data, 'data', [])
            ),
            usage: new EmbeddingsUsage(data_get($data, 'usage.total_tokens') ?? 0),
            meta: new Meta(
                id: '',
                model: data_get($data, 'model') ?? '',
            ),
            raw: $data,
        );
    }

    protected function sendRequest(Request $request): Response
    {
        /** @var Response $response */
        $response = $this->client->post(
            'embeddings',
            [
                'model' => $this->provider->normalizeModel($request->model()),
                'input' => $request->inputs(),
            ]
        );

        return $response;
    }
}

<?php

declare(strict_types=1);

namespace PrismWorkersAi\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Arr;
use Prism\Prism\Structured\Request;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Structured\ResponseBuilder;
use Prism\Prism\Structured\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use PrismWorkersAi\Concerns\MapsFinishReason;
use PrismWorkersAi\Concerns\ValidatesResponses;
use PrismWorkersAi\Maps\MessageMap;

class Structured
{
    use MapsFinishReason;
    use ValidatesResponses;

    protected ResponseBuilder $responseBuilder;

    public function __construct(protected PendingRequest $client)
    {
        $this->responseBuilder = new ResponseBuilder;
    }

    public function handle(Request $request): StructuredResponse
    {
        $response = $this->sendRequest($request);

        $this->validateResponse($response);

        $data = $response->json();

        $content = data_get($data, 'choices.0.message.content') ?? '';

        // Workers AI /compat may return content as an object/array instead of a JSON string
        if (is_array($content) || is_object($content)) {
            $content = json_encode($content);
        }

        $parsed = data_get($data, 'choices.0.message.parsed');

        // If no parsed field, try to decode content as JSON for structured data
        if ($parsed === null && is_string($content) && $content !== '') {
            $decoded = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $parsed = $decoded;
            }
        }

        $responseMessage = new AssistantMessage($content);

        $request->addMessage($responseMessage);

        $this->addStep($data, $request, $content, $parsed);

        return $this->responseBuilder->toResponse();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $parsed
     */
    protected function addStep(array $data, Request $request, string $content, ?array $parsed): void
    {
        $this->responseBuilder->addStep(new Step(
            text: $content,
            finishReason: $this->mapFinishReason($data),
            usage: new Usage(
                promptTokens: data_get($data, 'usage.prompt_tokens', 0),
                completionTokens: data_get($data, 'usage.completion_tokens', 0),
            ),
            meta: new Meta(
                id: data_get($data, 'id', ''),
                model: data_get($data, 'model', ''),
            ),
            messages: $request->messages(),
            systemPrompts: $request->systemPrompts(),
            additionalContent: [],
            structured: $parsed ?? [],
            raw: $data,
        ));
    }

    protected function sendRequest(Request $request): ClientResponse
    {
        $responseFormat = $this->buildResponseFormat($request);

        /** @var ClientResponse $response */
        $response = $this->client->post(
            'chat/completions',
            array_merge([
                'model' => $request->model(),
                'messages' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
                'max_tokens' => $request->maxTokens() ?? 2048,
                'response_format' => $responseFormat,
            ], Arr::whereNotNull([
                'temperature' => $request->temperature(),
                'top_p' => $request->topP(),
            ]))
        );

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildResponseFormat(Request $request): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => Arr::whereNotNull([
                'name' => $request->schema()->name(),
                'schema' => $request->schema()->toArray(),
                'strict' => $request->providerOptions('schema.strict') ? true : null,
            ]),
        ];
    }
}

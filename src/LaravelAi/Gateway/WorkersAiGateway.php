<?php

namespace PrismWorkersAi\LaravelAi\Gateway;

use Generator;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Gateway\Concerns\InvokesTools;
use Laravel\Ai\Gateway\Concerns\ParsesServerSentEvents;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\TextResponse;
use PrismWorkersAi\Cloudflare\ErrorEnvelope;
use PrismWorkersAi\Cloudflare\UsageTokens;

class WorkersAiGateway implements EmbeddingGateway, TextGateway
{
    use Concerns\BuildsTextRequests;
    use Concerns\CreatesWorkersAiClient;
    use Concerns\HandlesTextStreaming;
    use Concerns\MapsAttachments;
    use Concerns\MapsMessages;
    use Concerns\MapsTools;
    use Concerns\ParsesTextResponses;
    use HandlesFailoverErrors;
    use InvokesTools;
    use ParsesServerSentEvents;

    public function __construct(protected Dispatcher $events)
    {
        $this->initializeToolCallbacks();
    }

    /**
     * {@inheritdoc}
     */
    public function generateText(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
    ): TextResponse {
        $this->validateModelName($provider, $model);

        $body = $this->buildTextRequestBody(
            $provider,
            $model,
            $instructions,
            $messages,
            $tools,
            $schema,
            $options,
        );

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('chat/completions', $body),
        );

        $data = $response->json();

        $this->validateTextResponse($data);

        return $this->parseTextResponse(
            $data,
            $provider,
            filled($schema),
            $tools,
            $schema,
            $options,
            $instructions,
            $messages,
            $timeout,
        );
    }

    /**
     * {@inheritdoc}
     */
    public function streamText(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
    ): Generator {
        $this->validateModelName($provider, $model);

        $body = $this->buildTextRequestBody(
            $provider,
            $model,
            $instructions,
            $messages,
            $tools,
            $schema,
            $options,
        );

        $body['stream'] = true;
        $body['stream_options'] = ['include_usage' => true];

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)
                ->withOptions(['stream' => true])
                ->post('chat/completions', $body),
        );

        yield from $this->processTextStream(
            $invocationId,
            $provider,
            $model,
            $tools,
            $schema,
            $options,
            $response->getBody(),
            $instructions,
            $messages,
            timeout: $timeout,
        );
    }

    /**
     * {@inheritdoc}
     *
     * `$providerOptions` was added to the contract in laravel/ai v0.6.8. We
     * forward any caller-supplied keys into the Workers AI request body so
     * users can pass through Cloudflare-specific or future OpenAI-compatible
     * fields (e.g. `encoding_format`) without a package change. Reserved keys
     * (`model`, `input`) are stripped to keep the gateway in charge of the
     * request shape it built.
     */
    public function generateEmbeddings(
        EmbeddingProvider $provider,
        string $model,
        array $inputs,
        int $dimensions,
        int $timeout = 30,
        array $providerOptions = [],
    ): EmbeddingsResponse {
        $this->validateModelName($provider, $model);

        $body = array_merge(
            $this->sanitizeEmbeddingProviderOptions($providerOptions),
            ['model' => $model, 'input' => $inputs],
        );

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('embeddings', $body),
        );

        $data = $response->json();

        $this->validateEmbeddingsResponse($data);

        return new EmbeddingsResponse(
            collect($data['data'] ?? [])->pluck('embedding')->all(),
            UsageTokens::totalTokens($data['usage'] ?? []),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Strip reserved keys from caller-supplied embedding provider options.
     * `model` and `input` are owned by the gateway — letting callers override
     * them would break the contract the public method built.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function sanitizeEmbeddingProviderOptions(array $options): array
    {
        unset($options['model'], $options['input']);

        return $options;
    }

    /**
     * Surface gateway-level errors on the embeddings path under both the
     * OpenAI and Cloudflare AI Gateway envelope shapes — same rule the text
     * path applies in ParsesTextResponses::validateTextResponse.
     *
     * @throws AiException
     */
    protected function validateEmbeddingsResponse(?array $data): void
    {
        if (ErrorEnvelope::isErrorPayload($data)) {
            throw new AiException(sprintf(
                'Workers AI Error: [%s] %s',
                ErrorEnvelope::extractType($data),
                ErrorEnvelope::extract($data),
            ));
        }
    }
}

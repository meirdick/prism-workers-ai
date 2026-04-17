<?php

namespace PrismWorkersAi\Gateway;

use Closure;
use Generator;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Contracts\Gateway\Gateway;
use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Gateway\Concerns\InvokesTools;
use Laravel\Ai\Gateway\Concerns\ParsesServerSentEvents;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Responses\AudioResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\ImageResponse;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Responses\TranscriptionResponse;
use LogicException;

class WorkersAiGateway implements Gateway
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
     */
    public function onToolInvocation(Closure $invoking, Closure $invoked): self
    {
        $this->invokingToolCallback = $invoking;
        $this->toolInvokedCallback = $invoked;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function generateEmbeddings(
        EmbeddingProvider $provider,
        string $model,
        array $inputs,
        int $dimensions,
        int $timeout = 30,
    ): EmbeddingsResponse {
        $this->validateModelName($provider, $model);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('embeddings', [
                'model' => $model,
                'input' => $inputs,
            ]),
        );

        $data = $response->json();

        return new EmbeddingsResponse(
            collect($data['data'] ?? [])->pluck('embedding')->all(),
            $data['usage']['total_tokens'] ?? 0,
            new Meta($provider->name(), $model),
        );
    }

    /**
     * @throws LogicException
     */
    public function generateImage(
        ImageProvider $provider,
        string $model,
        string $prompt,
        array $attachments = [],
        ?string $size = null,
        ?string $quality = null,
        ?int $timeout = null,
    ): ImageResponse {
        throw new LogicException('Workers AI does not support image generation through the /compat endpoint.');
    }

    /**
     * @throws LogicException
     */
    public function generateAudio(
        AudioProvider $provider,
        string $model,
        string $text,
        string $voice,
        ?string $instructions = null,
        int $timeout = 30,
    ): AudioResponse {
        throw new LogicException('Workers AI does not support audio generation through the /compat endpoint.');
    }

    /**
     * @throws LogicException
     */
    public function generateTranscription(
        TranscriptionProvider $provider,
        string $model,
        TranscribableAudio $audio,
        ?string $language = null,
        bool $diarize = false,
        int $timeout = 30,
    ): TranscriptionResponse {
        throw new LogicException('Workers AI does not support transcription through the /compat endpoint.');
    }
}

<?php

namespace PrismWorkersAi\LaravelAi\Gateway\Concerns;

use Generator;
use Illuminate\Support\Str;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use PrismWorkersAi\Cloudflare\ErrorEnvelope;
use PrismWorkersAi\Cloudflare\StreamToolCallAccumulator;
use PrismWorkersAi\Cloudflare\ToolCallList;
use PrismWorkersAi\Cloudflare\UsageTokens;

trait HandlesTextStreaming
{
    /**
     * Process a Chat Completions streaming response and yield Laravel stream events.
     */
    protected function processTextStream(
        string $invocationId,
        Provider $provider,
        string $model,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        $streamBody,
        ?string $instructions = null,
        array $originalMessages = [],
        int $depth = 0,
        ?int $maxSteps = null,
        array $priorChatMessages = [],
        ?int $timeout = null,
    ): Generator {
        $maxSteps ??= $options?->maxSteps;

        $messageId = $this->generateEventId();
        $reasoningId = '';
        $streamStartEmitted = false;
        $textStartEmitted = false;
        $reasoningStartEmitted = false;
        $currentText = '';
        // Accumulated reasoning so the streaming tool-call follow-up can replay
        // it on the next request (matches DeepSeek native gateway behavior).
        // Without replay, multi-turn tool conversations with reasoning models
        // lose chain-of-thought across the tool boundary.
        $currentReasoning = '';
        $pendingToolCalls = [];
        $usage = null;
        $finishReason = null;

        foreach ($this->parseServerSentEvents($streamBody) as $data) {
            // ErrorEnvelope handles both `{"error": {...}}` (OpenAI shape) and
            // `{"errors": [...]}` (Cloudflare AI Gateway shape) so streaming
            // errors don't collapse to "Unknown error".
            if (ErrorEnvelope::isErrorPayload($data)) {
                yield (new Error(
                    $this->generateEventId(),
                    (string) ErrorEnvelope::extractType($data),
                    ErrorEnvelope::extract($data),
                    false,
                    time(),
                ))->withInvocationId($invocationId);

                return;
            }

            $choice = $data['choices'][0] ?? null;

            if (! $choice) {
                if (isset($data['usage'])) {
                    $usage = $this->buildStreamUsage($data['usage']);
                }

                continue;
            }

            $delta = $choice['delta'] ?? [];

            if (! $streamStartEmitted) {
                $streamStartEmitted = true;

                yield (new StreamStart(
                    $this->generateEventId(),
                    $provider->name(),
                    $data['model'] ?? $model,
                    time(),
                ))->withInvocationId($invocationId);
            }

            $reasoningDelta = $delta['reasoning_content']
                ?? $delta['reasoning']
                ?? $delta['thinking']
                ?? null;

            if (is_string($reasoningDelta) && $reasoningDelta !== '') {
                if (! $reasoningStartEmitted) {
                    $reasoningStartEmitted = true;
                    $reasoningId = $this->generateEventId();

                    yield (new ReasoningStart(
                        $this->generateEventId(),
                        $reasoningId,
                        time(),
                    ))->withInvocationId($invocationId);
                }

                $currentReasoning .= $reasoningDelta;

                yield (new ReasoningDelta(
                    $this->generateEventId(),
                    $reasoningId,
                    $reasoningDelta,
                    time(),
                ))->withInvocationId($invocationId);
            }

            if ($reasoningStartEmitted && isset($delta['content']) && $delta['content'] !== '' && $reasoningDelta === null) {
                $reasoningStartEmitted = false;

                yield (new ReasoningEnd(
                    $this->generateEventId(),
                    $reasoningId,
                    time(),
                ))->withInvocationId($invocationId);

                $reasoningId = '';
            }

            if (isset($delta['content']) && $delta['content'] !== '') {
                if (! $textStartEmitted) {
                    $textStartEmitted = true;

                    yield (new TextStart(
                        $this->generateEventId(),
                        $messageId,
                        time(),
                    ))->withInvocationId($invocationId);
                }

                $currentText .= $delta['content'];

                yield (new TextDelta(
                    $this->generateEventId(),
                    $messageId,
                    $delta['content'],
                    time(),
                ))->withInvocationId($invocationId);
            }

            // StreamToolCallAccumulator owns the null-tolerance rules: it
            // distinguishes explicit-null deltas (skip) from falsy strings
            // like "0" or "" (preserve), which a naive truthy check would
            // drop and corrupt the accumulated JSON arguments.
            $deltaToolCalls = ToolCallList::fromStreamDelta(['choices' => [['delta' => $delta]]]);
            if ($deltaToolCalls !== []) {
                $pendingToolCalls = StreamToolCallAccumulator::append($pendingToolCalls, $deltaToolCalls);
            }

            if (isset($choice['finish_reason']) && $choice['finish_reason'] !== null) {
                $finishReason = $choice['finish_reason'];
            }

            if (isset($data['usage'])) {
                $usage = $this->buildStreamUsage($data['usage']);
            }
        }

        if ($reasoningStartEmitted) {
            yield (new ReasoningEnd(
                $this->generateEventId(),
                $reasoningId,
                time(),
            ))->withInvocationId($invocationId);
        }

        if ($textStartEmitted) {
            yield (new TextEnd(
                $this->generateEventId(),
                $messageId,
                time(),
            ))->withInvocationId($invocationId);
        }

        if (filled($pendingToolCalls) && $finishReason === 'tool_calls') {
            $mappedToolCalls = $this->mapStreamToolCalls($pendingToolCalls);

            foreach ($mappedToolCalls as $toolCall) {
                yield (new ToolCallEvent(
                    $this->generateEventId(),
                    $toolCall,
                    time(),
                ))->withInvocationId($invocationId);
            }

            yield from $this->handleStreamingToolCalls(
                $invocationId,
                $provider,
                $model,
                $tools,
                $schema,
                $options,
                $mappedToolCalls,
                $currentText,
                $instructions,
                $originalMessages,
                $depth,
                $maxSteps,
                $priorChatMessages,
                $timeout,
                $currentReasoning,
            );

            return;
        }

        yield (new StreamEnd(
            $this->generateEventId(),
            $this->extractFinishReason(['finish_reason' => $finishReason ?? ''])->value,
            $usage ?? new Usage(0, 0),
            time(),
        ))->withInvocationId($invocationId);
    }

    /**
     * Build a Usage instance from a streamed `usage` chunk, applying the same
     * null-tolerance + cache-metric rules as the non-streaming path.
     *
     * @param  array<string, mixed>  $usage
     */
    protected function buildStreamUsage(array $usage): Usage
    {
        return new Usage(
            promptTokens: UsageTokens::promptTokens($usage),
            completionTokens: UsageTokens::completionTokens($usage),
            cacheWriteInputTokens: 0,
            cacheReadInputTokens: UsageTokens::cachedTokens($usage) ?? 0,
            reasoningTokens: UsageTokens::reasoningTokens($usage) ?? 0,
        );
    }

    /**
     * Handle tool calls detected during streaming.
     */
    protected function handleStreamingToolCalls(
        string $invocationId,
        Provider $provider,
        string $model,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        array $mappedToolCalls,
        string $currentText,
        ?string $instructions,
        array $originalMessages,
        int $depth,
        ?int $maxSteps,
        array $priorChatMessages,
        ?int $timeout = null,
        string $currentReasoning = '',
    ): Generator {
        $toolResults = [];

        foreach ($mappedToolCalls as $toolCall) {
            $tool = $this->findTool($toolCall->name, $tools);

            if ($tool === null) {
                continue;
            }

            $result = $this->executeTool($tool, $toolCall->arguments);

            $toolResult = new ToolResult(
                $toolCall->id,
                $toolCall->name,
                $toolCall->arguments,
                $result,
                $toolCall->resultId,
            );

            $toolResults[] = $toolResult;

            yield (new ToolResultEvent(
                $this->generateEventId(),
                $toolResult,
                true,
                null,
                time(),
            ))->withInvocationId($invocationId);
        }

        if ($depth + 1 < ($maxSteps ?? round(count($tools) * 1.5))) {
            // Workers AI rejects assistant messages without a `content` field
            // (even when only tool_calls are present); emit an empty string as
            // the floor so streaming tool-call follow-ups don't 400. Matches
            // MapsMessages::mapAssistantMessage on the non-streaming path.
            $assistantMsg = [
                'role' => 'assistant',
                'content' => filled($currentText) ? $currentText : '',
            ];

            // Replay reasoning so multi-turn tool conversations preserve the
            // model's chain of thought across the tool boundary.
            if (filled($currentReasoning)) {
                $assistantMsg['reasoning_content'] = $currentReasoning;
            }

            $assistantMsg['tool_calls'] = array_map(
                fn (ToolCall $toolCall) => $this->serializeToolCallToChat($toolCall), $mappedToolCalls
            );

            $toolResultMessages = [];

            foreach ($toolResults as $toolResult) {
                $toolResultMessages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolResult->resultId ?? $toolResult->id,
                    'content' => $this->serializeToolResultOutput($toolResult->result),
                ];
            }

            $updatedPriorMessages = [...$priorChatMessages, $assistantMsg, ...$toolResultMessages];

            $chatMessages = [
                ...$this->mapMessagesToChat($originalMessages, $instructions),
                ...$updatedPriorMessages,
            ];

            $body = [
                'model' => $model,
                'messages' => $chatMessages,
                'stream' => true,
                'stream_options' => ['include_usage' => true],
            ];

            if (filled($tools)) {
                $mappedTools = $this->mapTools($tools);

                if (filled($mappedTools)) {
                    $body['tool_choice'] = 'auto';
                    $body['tools'] = $mappedTools;
                }
            }

            if (filled($schema)) {
                $body['response_format'] = $this->buildResponseFormat(
                    $schema,
                    \PrismWorkersAi\Cloudflare\StrictAttributeGuard::isAppliedTo($options?->agent),
                );
            }

            if (! is_null($options?->maxTokens)) {
                $body['max_completion_tokens'] = $options->maxTokens;
            }

            $body = array_merge($body, \Illuminate\Support\Arr::whereNotNull([
                'temperature' => $options?->temperature,
                'top_p' => \PrismWorkersAi\Cloudflare\TextOptionsCompat::topP($options),
            ]));

            $providerOptions = $options?->providerOptions($provider->driver());

            if (filled($providerOptions)) {
                $body = array_merge($body, $providerOptions);
            }

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
                $originalMessages,
                $depth + 1,
                $maxSteps,
                $updatedPriorMessages,
                $timeout,
            );
        } else {
            yield (new StreamEnd(
                $this->generateEventId(),
                'stop',
                new Usage(0, 0),
                time(),
            ))->withInvocationId($invocationId);
        }
    }

    /**
     * Map raw streaming tool call data to ToolCall DTOs.
     *
     * @return array<ToolCall>
     */
    protected function mapStreamToolCalls(array $toolCalls): array
    {
        return array_map(fn (array $toolCall) => new ToolCall(
            $toolCall['id'] ?? '',
            $toolCall['name'] ?? '',
            json_decode($toolCall['arguments'] ?? '{}', true) ?? [],
            $toolCall['id'] ?? null,
        ), array_values($toolCalls));
    }

    /**
     * Generate a lowercase UUID v7 for use as a stream event ID.
     */
    protected function generateEventId(): string
    {
        return strtolower((string) Str::uuid7());
    }
}

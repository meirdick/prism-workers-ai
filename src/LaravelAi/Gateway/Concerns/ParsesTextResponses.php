<?php

namespace PrismWorkersAi\LaravelAi\Gateway\Concerns;

use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;
use PrismWorkersAi\Cloudflare\AssistantMessageFactory;
use PrismWorkersAi\Cloudflare\ErrorEnvelope;
use PrismWorkersAi\Cloudflare\ToolCallList;
use PrismWorkersAi\Cloudflare\UsageTokens;

trait ParsesTextResponses
{
    /**
     * Validate the Workers AI response data.
     *
     * Cloudflare's AI Gateway returns errors under both the OpenAI-style
     * `{"error": {...}}` shape and the gateway-native `{"errors": [...]}`
     * envelope; ErrorEnvelope handles both. Without that layered lookup,
     * gateway-level errors collapse to "Unknown error" and operators lose the
     * actual reason (rate limit, billing, model unavailable, malformed
     * request).
     *
     * @throws AiException
     */
    protected function validateTextResponse(array $data): void
    {
        if (ErrorEnvelope::isErrorPayload($data)) {
            throw new AiException(sprintf(
                'Workers AI Error: [%s] %s',
                ErrorEnvelope::extractType($data),
                ErrorEnvelope::extract($data),
            ));
        }

        if (empty($data['choices'][0])) {
            throw new AiException(
                'Workers AI Error: Response did not contain any choices.',
            );
        }
    }

    /**
     * Parse the Workers AI response data into a TextResponse.
     */
    protected function parseTextResponse(
        array $data,
        Provider $provider,
        bool $structured,
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?string $instructions = null,
        array $originalMessages = [],
        ?int $timeout = null,
    ): TextResponse {
        return $this->processResponse(
            $data,
            $provider,
            $structured,
            $tools,
            $schema,
            new Collection,
            new Collection,
            instructions: $instructions,
            originalMessages: $originalMessages,
            maxSteps: $options?->maxSteps,
            options: $options,
            timeout: $timeout,
        );
    }

    /**
     * Process a single response, handling tool loops recursively.
     */
    protected function processResponse(
        array $data,
        Provider $provider,
        bool $structured,
        array $tools,
        ?array $schema,
        Collection $steps,
        Collection $messages,
        ?string $instructions = null,
        array $originalMessages = [],
        int $depth = 0,
        ?int $maxSteps = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
    ): TextResponse {
        $choice = $data['choices'][0] ?? [];
        $message = $choice['message'] ?? [];
        $model = $data['model'] ?? '';

        $text = $this->extractTextContent($message);
        // ToolCallList tolerates explicit-null `tool_calls` from /compat reasoning
        // models (Kimi K2.5/K2.6) — they emit literal nulls when finish_reason
        // is "stop" rather than omitting the key.
        $rawToolCalls = ToolCallList::fromResponse($data);
        $usage = $this->extractUsage($data);
        $finishReason = $this->extractFinishReason($choice);

        $mappedToolCalls = array_map(fn (array $toolCall) => new ToolCall(
            $toolCall['id'] ?? '',
            $toolCall['function']['name'] ?? '',
            json_decode($toolCall['function']['arguments'] ?? '{}', true) ?? [],
            $toolCall['id'] ?? null,
        ), $rawToolCalls);

        $step = new Step(
            $text,
            $mappedToolCalls,
            [],
            $finishReason,
            $usage,
            new Meta($provider->name(), $model),
        );

        $steps->push($step);

        // Capture reasoning_content into providerContentBlocks so it round-trips
        // through the tool-call follow-up. Reasoning models (Kimi K2.5, Gemma 4,
        // QwQ on Workers AI; DeepSeek upstream) lose multi-turn coherence if the
        // thinking that led to the first tool call isn't replayed on the next
        // request. Pattern matches laravel/ai's DeepSeek native gateway.
        // AssistantMessageFactory handles the v0.5 ↔ v0.6+ constructor diff:
        // older laravel/ai accepts only (content, toolCalls); the factory
        // drops the providerContentBlocks arg there and reasoning replay
        // silently no-ops (the feature is v0.6+ anyway).
        $providerContentBlocks = [];
        if (filled($message['reasoning_content'] ?? null)) {
            $providerContentBlocks['reasoning_content'] = $message['reasoning_content'];
        }

        $assistantMessage = AssistantMessageFactory::make($text, collect($mappedToolCalls), $providerContentBlocks);

        $messages->push($assistantMessage);

        if ($finishReason === FinishReason::ToolCalls &&
            filled($mappedToolCalls) &&
            $steps->count() < ($maxSteps ?? round(count($tools) * 1.5))) {
            $toolResults = $this->executeToolCalls($mappedToolCalls, $tools);

            $steps->pop();

            $steps->push(new Step(
                $text,
                $mappedToolCalls,
                $toolResults,
                $finishReason,
                $usage,
                new Meta($provider->name(), $model),
            ));

            $toolResultMessage = new ToolResultMessage(collect($toolResults));

            $messages->push($toolResultMessage);

            return $this->continueWithToolResults(
                $model,
                $provider,
                $structured,
                $tools,
                $schema,
                $steps,
                $messages,
                $instructions,
                $originalMessages,
                $depth + 1,
                $maxSteps,
                $options,
                $timeout,
            );
        }

        $allToolCalls = $steps->flatMap(fn (Step $s) => $s->toolCalls);
        $allToolResults = $steps->flatMap(fn (Step $s) => $s->toolResults);

        if ($structured) {
            $structuredData = json_decode($text, true) ?? [];

            return (new StructuredTextResponse(
                $structuredData,
                $text,
                $this->combineUsage($steps),
                new Meta($provider->name(), $model),
            ))->withToolCallsAndResults(
                toolCalls: $allToolCalls,
                toolResults: $allToolResults,
            )->withSteps($steps);
        }

        return (new TextResponse(
            $text,
            $this->combineUsage($steps),
            new Meta($provider->name(), $model),
        ))->withMessages($messages)->withSteps($steps);
    }

    /**
     * Execute tool calls and return tool results.
     *
     * @param  array<ToolCall>  $toolCalls
     * @param  array<Tool>  $tools
     * @return array<ToolResult>
     */
    protected function executeToolCalls(array $toolCalls, array $tools): array
    {
        $results = [];

        foreach ($toolCalls as $toolCall) {
            $tool = $this->findTool($toolCall->name, $tools);

            if ($tool === null) {
                continue;
            }

            $result = $this->executeTool($tool, $toolCall->arguments);

            $results[] = new ToolResult(
                $toolCall->id,
                $toolCall->name,
                $toolCall->arguments,
                $result,
                $toolCall->resultId,
            );
        }

        return $results;
    }

    /**
     * Continue the conversation with tool results by making a follow-up request.
     */
    protected function continueWithToolResults(
        string $model,
        Provider $provider,
        bool $structured,
        array $tools,
        ?array $schema,
        Collection $steps,
        Collection $messages,
        ?string $instructions,
        array $originalMessages,
        int $depth,
        ?int $maxSteps,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
    ): TextResponse {
        $chatMessages = $this->mapMessagesToChat($originalMessages, $instructions);

        foreach ($messages as $msg) {
            match (true) {
                $msg instanceof AssistantMessage => $this->mapAssistantMessage($msg, $chatMessages),
                $msg instanceof ToolResultMessage => $this->mapToolResultMessage($msg, $chatMessages),
                default => null,
            };
        }

        $body = [
            'model' => $model,
            'messages' => $chatMessages,
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
            fn () => $this->client($provider, $timeout)->post('chat/completions', $body),
        );

        $data = $response->json();

        $this->validateTextResponse($data);

        return $this->processResponse(
            $data,
            $provider,
            $structured,
            $tools,
            $schema,
            $steps,
            $messages,
            $instructions,
            $originalMessages,
            $depth,
            $maxSteps,
            $options,
            $timeout,
        );
    }

    /**
     * Extract text content from a message.
     */
    protected function extractTextContent(array $message): string
    {
        $content = $message['content'] ?? '';

        if (is_string($content)) {
            return $content;
        }

        if (is_array($content) || is_object($content)) {
            return json_encode($content);
        }

        return strval($content);
    }

    /**
     * Extract usage data from the response.
     *
     * UsageTokens centralizes the explicit-null tolerance and the
     * `prompt_tokens_details.cached_tokens` mapping for prefix-cache metrics
     * (paired with session affinity for multi-turn conversations).
     */
    protected function extractUsage(array $data): Usage
    {
        $usage = $data['usage'] ?? [];

        return new Usage(
            promptTokens: UsageTokens::promptTokens($usage),
            completionTokens: UsageTokens::completionTokens($usage),
            cacheWriteInputTokens: 0,
            cacheReadInputTokens: UsageTokens::cachedTokens($usage) ?? 0,
            reasoningTokens: UsageTokens::reasoningTokens($usage) ?? 0,
        );
    }

    /**
     * Extract and map the finish reason from the response.
     */
    protected function extractFinishReason(array $choice): FinishReason
    {
        return match ($choice['finish_reason'] ?? '') {
            'stop' => FinishReason::Stop,
            'tool_calls' => FinishReason::ToolCalls,
            'length' => FinishReason::Length,
            'content_filter' => FinishReason::ContentFilter,
            default => FinishReason::Unknown,
        };
    }

    /**
     * Combine usage across all steps.
     */
    protected function combineUsage(Collection $steps): Usage
    {
        return $steps->reduce(
            fn (Usage $carry, Step $step) => $carry->add($step->usage),
            new Usage(0, 0)
        );
    }
}

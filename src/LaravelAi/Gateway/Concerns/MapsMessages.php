<?php

namespace PrismWorkersAi\LaravelAi\Gateway\Concerns;

use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\ToolCall;

trait MapsMessages
{
    /**
     * Map the given Laravel messages to Chat Completions messages format.
     */
    protected function mapMessagesToChat(array $messages, ?string $instructions = null): array
    {
        $chatMessages = [];

        if (filled($instructions)) {
            $chatMessages[] = [
                'role' => 'system',
                'content' => $instructions,
            ];
        }

        foreach ($messages as $message) {
            $message = Message::tryFrom($message);

            match ($message->role) {
                MessageRole::User => $this->mapUserMessage($message, $chatMessages),
                MessageRole::Assistant => $this->mapAssistantMessage($message, $chatMessages),
                MessageRole::ToolResult => $this->mapToolResultMessage($message, $chatMessages),
            };
        }

        return $chatMessages;
    }

    /**
     * Map a user message to Chat Completions format.
     */
    protected function mapUserMessage(UserMessage|Message $message, array &$chatMessages): void
    {
        if (! $message instanceof UserMessage || $message->attachments->isEmpty()) {
            $chatMessages[] = [
                'role' => 'user',
                'content' => $this->coerceContentToString($message->content),
            ];

            return;
        }

        $chatMessages[] = [
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => $message->content],
                ...$this->mapAttachments($message->attachments),
            ],
        ];
    }

    /**
     * Map an assistant message to Chat Completions format.
     *
     * Replays `reasoning_content` from `providerContentBlocks` when present so
     * thinking models (Kimi K2.5, Gemma 4, QwQ, DeepSeek-style) keep their
     * chain of thought across tool-call turns. Without the replay, second-turn
     * models lose the context that led to the first tool call and produce
     * incoherent follow-ups. Pattern matches laravel/ai's DeepSeek native
     * gateway.
     */
    protected function mapAssistantMessage(AssistantMessage|Message $message, array &$chatMessages): void
    {
        // Workers AI's /compat endpoint rejects assistant messages without a
        // `content` field — even when the assistant only emitted tool_calls
        // and has nothing textual to say. The OpenAI spec allows omitting
        // content in that case; Workers AI does not. Always emit an empty
        // string as the floor so tool-call follow-ups don't 400 with
        // "oneOf at '/' not met". This matches the Prism-side handling
        // (Handlers/Text.php → data_get(..., 'content') ?? '').
        $msg = [
            'role' => 'assistant',
            'content' => filled($message->content)
                ? $this->coerceContentToString($message->content)
                : '',
        ];

        if ($message instanceof AssistantMessage
            && filled($message->providerContentBlocks['reasoning_content'] ?? null)) {
            $msg['reasoning_content'] = $message->providerContentBlocks['reasoning_content'];
        }

        if ($message instanceof AssistantMessage && $message->toolCalls->isNotEmpty()) {
            $msg['tool_calls'] = $message->toolCalls->map(
                fn (ToolCall $toolCall) => $this->serializeToolCallToChat($toolCall)
            )->all();
        }

        $chatMessages[] = $msg;
    }

    /**
     * Map a tool result message to Chat Completions format.
     */
    protected function mapToolResultMessage(ToolResultMessage|Message $message, array &$chatMessages): void
    {
        if (! $message instanceof ToolResultMessage) {
            return;
        }

        foreach ($message->toolResults as $toolResult) {
            $chatMessages[] = [
                'role' => 'tool',
                'tool_call_id' => $toolResult->resultId ?? $toolResult->id,
                'content' => $this->serializeToolResultOutput($toolResult->result),
            ];
        }
    }

    /**
     * Serialize a tool call DTO to Chat Completions array format.
     */
    protected function serializeToolCallToChat(ToolCall $toolCall): array
    {
        return [
            'id' => $toolCall->resultId ?? $toolCall->id,
            'type' => 'function',
            'function' => [
                'name' => $toolCall->name,
                'arguments' => json_encode($toolCall->arguments ?: (object) []),
            ],
        ];
    }

    /**
     * Serialize a tool result output value to a string.
     */
    protected function serializeToolResultOutput(mixed $output): string
    {
        if (is_string($output)) {
            return $output;
        }

        return is_array($output) ? json_encode($output) : strval($output);
    }

    /**
     * Coerce content to a string.
     */
    protected function coerceContentToString(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        if (is_null($content)) {
            return '';
        }

        if (is_array($content) || is_object($content)) {
            return json_encode($content);
        }

        return strval($content);
    }
}

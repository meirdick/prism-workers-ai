<?php

declare(strict_types=1);

namespace PrismWorkersAi\Maps;

use Exception;
use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;

class MessageMap
{
    /** @var array<int, mixed> */
    protected array $mappedMessages = [];

    /**
     * @param  array<int, Message>  $messages
     * @param  SystemMessage[]  $systemPrompts
     */
    public function __construct(
        protected array $messages,
        protected array $systemPrompts
    ) {
        $this->messages = array_merge(
            $this->systemPrompts,
            $this->messages
        );
    }

    /**
     * @return array<int, mixed>
     */
    public function __invoke(): array
    {
        array_map(
            $this->mapMessage(...),
            $this->messages
        );

        return $this->mappedMessages;
    }

    protected function mapMessage(Message $message): void
    {
        match ($message::class) {
            UserMessage::class => $this->mapUserMessage($message),
            AssistantMessage::class => $this->mapAssistantMessage($message),
            ToolResultMessage::class => $this->mapToolResultMessage($message),
            SystemMessage::class => $this->mapSystemMessage($message),
            default => throw new Exception('Could not map message type '.$message::class),
        };
    }

    protected function mapSystemMessage(SystemMessage $message): void
    {
        $this->mappedMessages[] = [
            'role' => 'system',
            'content' => $message->content,
        ];
    }

    protected function mapToolResultMessage(ToolResultMessage $message): void
    {
        foreach ($message->toolResults as $toolResult) {
            $content = $toolResult->result;

            // Workers AI requires content to be a string
            if (is_array($content) || is_object($content)) {
                $content = json_encode($content);
            } elseif (is_null($content)) {
                $content = '';
            } else {
                $content = (string) $content;
            }

            $this->mappedMessages[] = [
                'role' => 'tool',
                'tool_call_id' => $toolResult->toolCallId,
                'content' => $content,
            ];
        }
    }

    /**
     * Workers AI /compat expects string content for text-only messages.
     * Only use array content format when images are present.
     */
    protected function mapUserMessage(UserMessage $message): void
    {
        $images = $message->images();

        if (empty($images)) {
            // String content — the critical fix vs XAI's array format
            $this->mappedMessages[] = [
                'role' => 'user',
                'content' => $message->text(),
            ];

            return;
        }

        // Array format for multimodal messages
        $imageParts = array_map(
            fn (Image $image): array => (new ImageMapper($image))->toPayload(),
            $images
        );

        $this->mappedMessages[] = [
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => $message->text()],
                ...$imageParts,
            ],
        ];
    }

    protected function mapAssistantMessage(AssistantMessage $message): void
    {
        $toolCalls = array_map(fn (ToolCall $toolCall): array => [
            'id' => $toolCall->id,
            'type' => 'function',
            'function' => [
                'name' => $toolCall->name,
                'arguments' => json_encode($toolCall->arguments() ?: (object) []),
            ],
        ], $message->toolCalls);

        $mapped = [
            'role' => 'assistant',
            'content' => $message->content ?? '',
        ];

        if ($toolCalls !== []) {
            $mapped['tool_calls'] = $toolCalls;
        }

        $this->mappedMessages[] = $mapped;
    }
}

<?php

declare(strict_types=1);

use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use PrismWorkersAi\Maps\MessageMap;

it('maps user messages with string content (not array)', function () {
    $messages = [new UserMessage('Hello, world!')];
    $systemPrompts = [];

    $mapped = (new MessageMap($messages, $systemPrompts))();

    expect($mapped)->toHaveCount(1);
    expect($mapped[0])->toBe([
        'role' => 'user',
        'content' => 'Hello, world!',
    ]);

    // Critical: content must be a string, not an array
    expect($mapped[0]['content'])->toBeString();
});

it('maps system messages with string content', function () {
    $messages = [];
    $systemPrompts = [new SystemMessage('You are a helpful assistant.')];

    $mapped = (new MessageMap($messages, $systemPrompts))();

    expect($mapped)->toHaveCount(1);
    expect($mapped[0])->toBe([
        'role' => 'system',
        'content' => 'You are a helpful assistant.',
    ]);
});

it('maps assistant messages with content', function () {
    $messages = [new AssistantMessage('I can help with that.')];
    $systemPrompts = [];

    $mapped = (new MessageMap($messages, $systemPrompts))();

    expect($mapped)->toHaveCount(1);
    expect($mapped[0]['role'])->toBe('assistant');
    expect($mapped[0]['content'])->toBe('I can help with that.');
});

it('maps assistant messages with tool calls', function () {
    $toolCalls = [
        new ToolCall(
            id: 'call_abc123',
            name: 'get_weather',
            arguments: '{"location": "San Francisco"}',
        ),
    ];

    $messages = [new AssistantMessage('', $toolCalls)];
    $systemPrompts = [];

    $mapped = (new MessageMap($messages, $systemPrompts))();

    expect($mapped)->toHaveCount(1);
    expect($mapped[0]['role'])->toBe('assistant');
    expect($mapped[0]['tool_calls'])->toHaveCount(1);
    expect($mapped[0]['tool_calls'][0]['id'])->toBe('call_abc123');
    expect($mapped[0]['tool_calls'][0]['type'])->toBe('function');
    expect($mapped[0]['tool_calls'][0]['function']['name'])->toBe('get_weather');
});

it('maps tool result messages', function () {
    $toolResults = [
        new ToolResult(
            toolCallId: 'call_abc123',
            toolName: 'get_weather',
            args: ['location' => 'San Francisco'],
            result: '72°F and sunny',
        ),
    ];

    $messages = [new ToolResultMessage($toolResults)];
    $systemPrompts = [];

    $mapped = (new MessageMap($messages, $systemPrompts))();

    expect($mapped)->toHaveCount(1);
    expect($mapped[0]['role'])->toBe('tool');
    expect($mapped[0]['tool_call_id'])->toBe('call_abc123');
    expect($mapped[0]['content'])->toBe('72°F and sunny');
});

it('prepends system prompts before messages', function () {
    $messages = [new UserMessage('Hello!')];
    $systemPrompts = [new SystemMessage('You are helpful.')];

    $mapped = (new MessageMap($messages, $systemPrompts))();

    expect($mapped)->toHaveCount(2);
    expect($mapped[0]['role'])->toBe('system');
    expect($mapped[1]['role'])->toBe('user');
});

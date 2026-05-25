<?php

declare(strict_types=1);

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;

/**
 * Global helpers for the Laravel AI gateway test suite. Inlined from the
 * laravel/ai test harness so the package's tests don't depend on laravel/ai's
 * internal Helpers.php (which evolves on a different release cadence).
 */
function workersAiTextResponse(string $content = 'Hello from Workers AI'): array
{
    return [
        'id' => 'chatcmpl-123',
        'object' => 'chat.completion',
        'model' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => $content,
            ],
            'finish_reason' => 'stop',
        ]],
        'usage' => [
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
        ],
    ];
}

function fakeWorkersAiResponse(string $text = 'Hello'): PromiseInterface
{
    return Http::response(workersAiTextResponse($text));
}

function fakeWorkersAiToolCallResponse(): array
{
    return [
        'id' => 'chatcmpl-tool-123',
        'object' => 'chat.completion',
        'model' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_123',
                    'type' => 'function',
                    'function' => [
                        'name' => 'FixedNumberGenerator',
                        'arguments' => '{}',
                    ],
                ]],
            ],
            'finish_reason' => 'tool_calls',
        ]],
        'usage' => [
            'prompt_tokens' => 20,
            'completion_tokens' => 10,
        ],
    ];
}

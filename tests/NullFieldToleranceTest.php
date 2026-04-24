<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;

/**
 * Guards against the Kimi K2.x crash class: Cloudflare's /compat layer emits
 * explicit null for unused fields rather than omitting them. data_get(..., $default)
 * only substitutes $default for missing keys, not explicit null — so unguarded
 * values flow into typed sinks (int, string, array) and crash.
 *
 * Each field is exercised against three shapes: explicit null, absent key, and
 * the populated happy path. All three must produce the same parsed result.
 */

/**
 * @param  array<string, mixed>  $overrides  Keys merged into the baseline choices[0].message
 * @param  array<string, mixed>|null  $usage  Replaces the default usage block (null = key present but null)
 * @param  bool  $omitUsage  When true, drops the usage key entirely
 * @return array<string, mixed>
 */
function kimiResponseBody(array $overrides = [], ?array $usage = null, bool $omitUsage = false): array
{
    $message = array_merge([
        'role' => 'assistant',
        'content' => 'ok',
        'tool_calls' => null,
    ], $overrides);

    $body = [
        'id' => 'chatcmpl-test',
        'model' => '@cf/moonshotai/kimi-k2.6',
        'choices' => [[
            'index' => 0,
            'message' => $message,
            'finish_reason' => 'stop',
        ]],
    ];

    if (! $omitUsage) {
        $body['usage'] = $usage ?? [
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
            'total_tokens' => 15,
        ];
    }

    return $body;
}

dataset('tool_calls_shapes', [
    'explicit null' => [['tool_calls' => null]],
    'absent key' => [[]],
    'empty array' => [['tool_calls' => []]],
]);

it('handles every tool_calls shape without crashing', function (array $overrides) {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(kimiResponseBody($overrides)),
    ]);

    $response = Prism::text()
        ->using('workers-ai', 'workers-ai/@cf/moonshotai/kimi-k2.6')
        ->withPrompt('hi')
        ->asText();

    expect($response->text)->toBe('ok');
    expect($response->finishReason)->toBe(FinishReason::Stop);
    expect($response->steps->first()->toolCalls)->toBe([]);
})->with('tool_calls_shapes');

dataset('content_shapes', [
    'populated string' => ['hello', 'hello'],
    'explicit null' => [null, ''],
    'empty string' => ['', ''],
]);

it('handles every content shape', function (mixed $content, string $expected) {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(kimiResponseBody(['content' => $content])),
    ]);

    $response = Prism::text()
        ->using('workers-ai', 'workers-ai/@cf/moonshotai/kimi-k2.6')
        ->withPrompt('hi')
        ->asText();

    expect($response->text)->toBe($expected);
})->with('content_shapes');

it('handles explicit null usage block', function () {
    $body = kimiResponseBody();
    $body['usage'] = null;

    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response($body),
    ]);

    $response = Prism::text()
        ->using('workers-ai', 'workers-ai/@cf/moonshotai/kimi-k2.6')
        ->withPrompt('hi')
        ->asText();

    expect($response->usage->promptTokens)->toBe(0);
    expect($response->usage->completionTokens)->toBe(0);
});

it('handles absent usage block', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(kimiResponseBody(omitUsage: true)),
    ]);

    $response = Prism::text()
        ->using('workers-ai', 'workers-ai/@cf/moonshotai/kimi-k2.6')
        ->withPrompt('hi')
        ->asText();

    expect($response->usage->promptTokens)->toBe(0);
    expect($response->usage->completionTokens)->toBe(0);
});

dataset('usage_field_shapes', [
    'null prompt_tokens' => [['prompt_tokens' => null, 'completion_tokens' => 5], 0, 5, null],
    'null completion_tokens' => [['prompt_tokens' => 10, 'completion_tokens' => null], 10, 0, null],
    'null reasoning_tokens' => [['prompt_tokens' => 10, 'completion_tokens' => 5, 'reasoning_tokens' => null], 10, 5, null],
    'populated reasoning_tokens' => [['prompt_tokens' => 10, 'completion_tokens' => 5, 'reasoning_tokens' => 42], 10, 5, 42],
    'all null' => [['prompt_tokens' => null, 'completion_tokens' => null, 'reasoning_tokens' => null], 0, 0, null],
]);

it('handles every usage field shape', function (array $usage, int $expectedPrompt, int $expectedCompletion, ?int $expectedThought) {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(kimiResponseBody(usage: $usage)),
    ]);

    $response = Prism::text()
        ->using('workers-ai', 'workers-ai/@cf/moonshotai/kimi-k2.6')
        ->withPrompt('hi')
        ->asText();

    expect($response->usage->promptTokens)->toBe($expectedPrompt);
    expect($response->usage->completionTokens)->toBe($expectedCompletion);
    expect($response->usage->thoughtTokens)->toBe($expectedThought);
})->with('usage_field_shapes');

dataset('meta_field_shapes', [
    'null id' => [['id' => null], ''],
    'absent id' => [[], ''],
]);

it('handles null/absent id in meta without crashing', function (array $idOverrides, string $expected) {
    $body = kimiResponseBody();
    unset($body['id']);
    $body = array_merge($body, $idOverrides);

    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response($body),
    ]);

    $response = Prism::text()
        ->using('workers-ai', 'workers-ai/@cf/moonshotai/kimi-k2.6')
        ->withPrompt('hi')
        ->asText();

    expect($response->meta->id)->toBe($expected);
})->with('meta_field_shapes');

it('handles null model in meta without crashing', function () {
    $body = kimiResponseBody();
    $body['model'] = null;

    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response($body),
    ]);

    $response = Prism::text()
        ->using('workers-ai', 'workers-ai/@cf/moonshotai/kimi-k2.6')
        ->withPrompt('hi')
        ->asText();

    expect($response->meta->model)->toBe('');
});

it('handles combined worst-case: every nullable field null at once', function () {
    $body = [
        'id' => null,
        'model' => null,
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => null,
            ],
            'finish_reason' => 'stop',
        ]],
        'usage' => null,
    ];

    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response($body),
    ]);

    $response = Prism::text()
        ->using('workers-ai', 'workers-ai/@cf/moonshotai/kimi-k2.6')
        ->withPrompt('hi')
        ->asText();

    expect($response->text)->toBe('');
    expect($response->finishReason)->toBe(FinishReason::Stop);
    expect($response->usage->promptTokens)->toBe(0);
    expect($response->meta->id)->toBe('');
    expect($response->meta->model)->toBe('');
    expect($response->steps->first()->toolCalls)->toBe([]);
});

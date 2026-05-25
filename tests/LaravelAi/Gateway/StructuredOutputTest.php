<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\NonStrictAgent;
use Tests\Fixtures\Agents\StrictAgent;

/**
 * v0.7 made OpenAI strict-mode JSON schema opt-in via the #[Strict] attribute.
 * Our Workers AI gateway mirrors the OpenAI pattern: the response_format
 * payload reflects whether the agent (or tool) is annotated with #[Strict].
 *
 * Workers AI's /compat endpoint forwards both flags. Bare /v1 (direct API
 * and AI Gateway provider path) likewise accept the OpenAI-compatible
 * response_format. Models that don't enforce strict mode internally simply
 * ignore the flag — we always honor the user's intent.
 */

beforeEach(function () {
    config(['ai.providers.workersai' => [
        ...config('ai.providers.workersai'),
        'key' => 'test-key',
        'account_id' => 'test-account',
    ]]);
});

function fakeWorkersAiStructuredResponse(): array
{
    return [
        'id' => 'chatcmpl-123',
        'object' => 'chat.completion',
        'model' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
        'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => '{"answer":"42"}'],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
    ];
}

test('agent without #[Strict] sends strict=false in response_format', function () {
    if (! class_exists(\Laravel\Ai\Attributes\Strict::class)) {
        $this->markTestSkipped('#[Strict] attribute requires laravel/ai ^0.7');
    }

    Http::fake(['api.cloudflare.com/*' => Http::response(fakeWorkersAiStructuredResponse())]);

    (new NonStrictAgent)->prompt('What is the answer?', provider: 'workersai');

    Http::assertSent(function (Request $r) {
        $body = json_decode($r->body(), true);

        return data_get($body, 'response_format.type') === 'json_schema'
            && data_get($body, 'response_format.json_schema.strict') === false;
    });
});

test('agent with #[Strict] sends strict=true in response_format', function () {
    if (! class_exists(\Laravel\Ai\Attributes\Strict::class)) {
        $this->markTestSkipped('#[Strict] attribute requires laravel/ai ^0.7');
    }

    Http::fake(['api.cloudflare.com/*' => Http::response(fakeWorkersAiStructuredResponse())]);

    (new StrictAgent)->prompt('What is the answer?', provider: 'workersai');

    Http::assertSent(function (Request $r) {
        $body = json_decode($r->body(), true);

        return data_get($body, 'response_format.type') === 'json_schema'
            && data_get($body, 'response_format.json_schema.strict') === true;
    });
});

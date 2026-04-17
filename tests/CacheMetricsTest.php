<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Streaming\Events\StreamEndEvent;

it('parses cacheReadInputTokens from text response', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            $this->fixture('text-response-with-cache.json'),
        ),
    ]);

    $response = Prism::text()
        ->using('workers-ai', 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast')
        ->withPrompt('Hello again!')
        ->asText();

    expect($response->usage->promptTokens)->toBe(50);
    expect($response->usage->completionTokens)->toBe(8);
    expect($response->usage->cacheReadInputTokens)->toBe(42);
});

it('returns null cacheReadInputTokens when not present in text response', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            $this->fixture('text-response.json'),
        ),
    ]);

    $response = Prism::text()
        ->using('workers-ai', 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast')
        ->withPrompt('Hello!')
        ->asText();

    expect($response->usage->cacheReadInputTokens)->toBeNull();
});

it('parses cacheReadInputTokens from streaming response', function () {
    $streamBody = file_get_contents(__DIR__.'/Fixtures/stream-response-with-cache.txt');

    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response($streamBody, 200, [
            'Content-Type' => 'text/event-stream',
        ]),
    ]);

    $stream = Prism::text()
        ->using('workers-ai', 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast')
        ->withPrompt('Hello again!')
        ->asStream();

    $events = [];
    foreach ($stream as $event) {
        $events[] = $event;
    }

    $streamEnd = array_values(array_filter($events, fn ($e) => $e instanceof StreamEndEvent));
    expect($streamEnd)->toHaveCount(1);
    expect($streamEnd[0]->usage->promptTokens)->toBe(50);
    expect($streamEnd[0]->usage->cacheReadInputTokens)->toBe(42);
});

it('returns null cacheReadInputTokens when not present in streaming response', function () {
    $streamBody = file_get_contents(__DIR__.'/Fixtures/stream-response.txt');

    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response($streamBody, 200, [
            'Content-Type' => 'text/event-stream',
        ]),
    ]);

    $stream = Prism::text()
        ->using('workers-ai', 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast')
        ->withPrompt('Hello!')
        ->asStream();

    $events = [];
    foreach ($stream as $event) {
        $events[] = $event;
    }

    $streamEnd = array_values(array_filter($events, fn ($e) => $e instanceof StreamEndEvent));
    expect($streamEnd)->toHaveCount(1);
    expect($streamEnd[0]->usage->cacheReadInputTokens)->toBeNull();
});

it('parses cacheReadInputTokens from structured response', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response([
            'id' => 'chatcmpl-cache-struct-001',
            'object' => 'chat.completion',
            'created' => 1700000000,
            'model' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => '{"intent": "greeting", "confidence": 0.95}',
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 50,
                'completion_tokens' => 12,
                'total_tokens' => 62,
                'prompt_tokens_details' => [
                    'cached_tokens' => 35,
                ],
            ],
        ]),
    ]);

    $response = Prism::structured()
        ->using('workers-ai', 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast')
        ->withSchema(new ObjectSchema(
            name: 'intent',
            description: 'User intent classification',
            properties: [
                new StringSchema('intent', 'The detected intent'),
                new NumberSchema('confidence', 'Confidence score'),
            ],
            requiredFields: ['intent', 'confidence'],
        ))
        ->withPrompt('Classify: Hello there!')
        ->generate();

    expect($response->usage->cacheReadInputTokens)->toBe(35);
});

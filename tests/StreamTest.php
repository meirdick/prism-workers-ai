<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;

it('can stream text responses', function () {
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
    $text = '';

    foreach ($stream as $event) {
        $events[] = $event;
        if ($event instanceof TextDeltaEvent) {
            $text .= $event->delta;
        }
    }

    expect($text)->toBe('Hello!');

    $textDeltas = array_filter($events, fn ($e) => $e instanceof TextDeltaEvent);
    expect($textDeltas)->toHaveCount(2);

    $endEvents = array_filter($events, fn ($e) => $e instanceof StreamEndEvent);
    expect($endEvents)->toHaveCount(1);
});

it('streams cleanly when every delta has explicit-null tool_calls (Kimi K2.6)', function () {
    $streamBody = file_get_contents(__DIR__.'/Fixtures/stream-null-fields-response.txt');

    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response($streamBody, 200, [
            'Content-Type' => 'text/event-stream',
        ]),
    ]);

    $stream = Prism::text()
        ->using('workers-ai', 'workers-ai/@cf/moonshotai/kimi-k2.6')
        ->withPrompt('hi')
        ->asStream();

    $text = '';
    foreach ($stream as $event) {
        if ($event instanceof TextDeltaEvent) {
            $text .= $event->delta;
        }
    }

    // Two non-null content chunks: "Hi" and "!"
    expect($text)->toBe('Hi!');
});

it('streams cleanly when final-chunk usage fields are all explicit null', function () {
    $streamBody = file_get_contents(__DIR__.'/Fixtures/stream-null-usage-tokens-response.txt');

    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response($streamBody, 200, [
            'Content-Type' => 'text/event-stream',
        ]),
    ]);

    $stream = Prism::text()
        ->using('workers-ai', 'workers-ai/@cf/moonshotai/kimi-k2.6')
        ->withPrompt('hi')
        ->asStream();

    $text = '';
    foreach ($stream as $event) {
        if ($event instanceof TextDeltaEvent) {
            $text .= $event->delta;
        }
    }

    expect($text)->toBe('hi');
});

it('sends string content and stream flag in stream requests', function () {
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

    // Consume the stream
    foreach ($stream as $_event) {}

    Http::assertSent(function ($request) {
        $body = json_decode($request->body(), true);
        $userMessage = collect($body['messages'])->firstWhere('role', 'user');

        return is_string($userMessage['content'])
            && $body['stream'] === true;
    });
});

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Tool;

it('handles tool calls in streaming mode', function () {
    $streamToolCallBody = file_get_contents(__DIR__.'/Fixtures/stream-tool-call-response.txt');
    $streamTextBody = file_get_contents(__DIR__.'/Fixtures/stream-response.txt');

    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::sequence([
            Http::response($streamToolCallBody, 200, ['Content-Type' => 'text/event-stream']),
            Http::response($streamTextBody, 200, ['Content-Type' => 'text/event-stream']),
        ]),
    ]);

    $tool = (new Tool)
        ->as('get_weather')
        ->for('Get current weather')
        ->withStringParameter('location', 'City name')
        ->using(fn (string $location) => "72°F in {$location}");

    $stream = Prism::text()
        ->using('workers-ai', 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast')
        ->withTools([$tool])
        ->withMaxSteps(3)
        ->withPrompt('What is the weather in San Francisco?')
        ->asStream();

    $events = [];
    $text = '';

    foreach ($stream as $event) {
        $events[] = $event;
        if ($event instanceof TextDeltaEvent) {
            $text .= $event->delta;
        }
    }

    // Should have tool call events from first stream
    $toolCallEvents = array_filter($events, fn ($e) => $e instanceof ToolCallEvent);
    expect($toolCallEvents)->toHaveCount(1);

    $toolCallEvent = array_values($toolCallEvents)[0];
    expect($toolCallEvent->toolCall->name)->toBe('get_weather');

    // Should have text from second stream (after tool execution)
    expect($text)->toBe('Hello!');

    // Should have stream lifecycle events
    $streamStarts = array_filter($events, fn ($e) => $e instanceof StreamStartEvent);
    expect($streamStarts)->not->toBeEmpty();

    $stepStarts = array_filter($events, fn ($e) => $e instanceof StepStartEvent);
    expect(count($stepStarts))->toBeGreaterThanOrEqual(2);

    $stepFinishes = array_filter($events, fn ($e) => $e instanceof StepFinishEvent);
    expect(count($stepFinishes))->toBeGreaterThanOrEqual(2);

    $streamEnds = array_filter($events, fn ($e) => $e instanceof StreamEndEvent);
    expect($streamEnds)->toHaveCount(1);
});

it('accumulates tool call arguments across stream chunks', function () {
    $streamToolCallBody = file_get_contents(__DIR__.'/Fixtures/stream-tool-call-response.txt');
    $streamTextBody = file_get_contents(__DIR__.'/Fixtures/stream-response.txt');

    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::sequence([
            Http::response($streamToolCallBody, 200, ['Content-Type' => 'text/event-stream']),
            Http::response($streamTextBody, 200, ['Content-Type' => 'text/event-stream']),
        ]),
    ]);

    $capturedLocation = null;
    $tool = (new Tool)
        ->as('get_weather')
        ->for('Get current weather')
        ->withStringParameter('location', 'City name')
        ->using(function (string $location) use (&$capturedLocation) {
            $capturedLocation = $location;

            return "72°F in {$location}";
        });

    $stream = Prism::text()
        ->using('workers-ai', 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast')
        ->withTools([$tool])
        ->withMaxSteps(3)
        ->withPrompt('What is the weather in San Francisco?')
        ->asStream();

    foreach ($stream as $_event) {}

    // Arguments were split across chunks: '{"location":' + ' "San Francisco"}'
    // They should be accumulated into a complete JSON string
    expect($capturedLocation)->toBe('San Francisco');
});

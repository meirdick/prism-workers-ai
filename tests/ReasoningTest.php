<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ThinkingEvent;
use Prism\Prism\Streaming\Events\ThinkingStartEvent;
use Prism\Prism\Streaming\Events\ThinkingCompleteEvent;

it('extracts reasoning_content from non-streaming response', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            $this->fixture('reasoning-response.json'),
        ),
    ]);

    $response = Prism::text()
        ->using('workers-ai', 'workers-ai/@cf/moonshotai/kimi-k2.5')
        ->withPrompt('Say hello in one sentence.')
        ->asText();

    expect($response->text)->toBe("Hello, I hope you're having a wonderful day!");
    expect($response->finishReason)->toBe(FinishReason::Stop);
    expect($response->steps[0]->additionalContent)->toHaveKey('thinking');
    expect($response->steps[0]->additionalContent['thinking'])
        ->toBe("The user wants me to say hello in one sentence. I'll provide a friendly greeting.");
});

it('handles reasoning model with null content (max_tokens too low)', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            $this->fixture('reasoning-null-content-response.json'),
        ),
    ]);

    $response = Prism::text()
        ->using('workers-ai', 'workers-ai/@cf/moonshotai/kimi-k2.5')
        ->withPrompt('Say hello in one sentence.')
        ->asText();

    expect($response->text)->toBe('');
    expect($response->finishReason)->toBe(FinishReason::Length);
    expect($response->steps[0]->additionalContent)->toHaveKey('thinking');
    expect($response->steps[0]->additionalContent['thinking'])
        ->toContain('The user wants me to say hello');
});

it('returns empty additionalContent for non-reasoning models', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            $this->fixture('text-response.json'),
        ),
    ]);

    $response = Prism::text()
        ->using('workers-ai', 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast')
        ->withPrompt('Hello!')
        ->asText();

    expect($response->steps[0]->additionalContent)->toBe([]);
});

it('extracts reasoning field from Gemma 4 non-streaming response', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            $this->fixture('gemma-reasoning-response.json'),
        ),
    ]);

    $response = Prism::text()
        ->using('workers-ai', 'workers-ai/@cf/google/gemma-4-26b-a4b-it')
        ->withPrompt('What is 2+2?')
        ->asText();

    expect($response->text)->toBe('4');
    expect($response->finishReason)->toBe(FinishReason::Stop);
    expect($response->steps[0]->additionalContent)->toHaveKey('thinking');
    expect($response->steps[0]->additionalContent['thinking'])
        ->toBe('The user asked what 2+2 is. The answer is 4.');
});

it('streams thinking events from Gemma 4 using reasoning field', function () {
    $streamBody = file_get_contents(__DIR__.'/Fixtures/gemma-reasoning-stream-response.txt');

    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response($streamBody, 200, [
            'Content-Type' => 'text/event-stream',
        ]),
    ]);

    $stream = Prism::text()
        ->using('workers-ai', 'workers-ai/@cf/google/gemma-4-26b-a4b-it')
        ->withPrompt('What is 2+2?')
        ->asStream();

    $events = [];
    $thinkingText = '';
    $contentText = '';

    foreach ($stream as $event) {
        $events[] = $event;
        if ($event instanceof ThinkingEvent) {
            $thinkingText .= $event->delta;
        }
        if ($event instanceof TextDeltaEvent) {
            $contentText .= $event->delta;
        }
    }

    expect($thinkingText)->toBe('The user asked what 2+2 is.');
    expect($contentText)->toBe('4');

    $thinkingStarts = array_filter($events, fn ($e) => $e instanceof ThinkingStartEvent);
    expect($thinkingStarts)->toHaveCount(1);

    $thinkingCompletes = array_filter($events, fn ($e) => $e instanceof ThinkingCompleteEvent);
    expect($thinkingCompletes)->toHaveCount(1);
});

it('streams thinking events from reasoning model', function () {
    $streamBody = file_get_contents(__DIR__.'/Fixtures/reasoning-stream-response.txt');

    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response($streamBody, 200, [
            'Content-Type' => 'text/event-stream',
        ]),
    ]);

    $stream = Prism::text()
        ->using('workers-ai', 'workers-ai/@cf/moonshotai/kimi-k2.5')
        ->withPrompt('Say hello in one sentence.')
        ->asStream();

    $events = [];
    $thinkingText = '';
    $contentText = '';

    foreach ($stream as $event) {
        $events[] = $event;
        if ($event instanceof ThinkingEvent) {
            $thinkingText .= $event->delta;
        }
        if ($event instanceof TextDeltaEvent) {
            $contentText .= $event->delta;
        }
    }

    expect($thinkingText)->toBe('The user wants me to say hello.');
    expect($contentText)->toBe('Hello!');

    $thinkingStarts = array_filter($events, fn ($e) => $e instanceof ThinkingStartEvent);
    expect($thinkingStarts)->toHaveCount(1);

    $thinkingCompletes = array_filter($events, fn ($e) => $e instanceof ThinkingCompleteEvent);
    expect($thinkingCompletes)->toHaveCount(1);
});

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;

it('can generate text', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            $this->fixture('text-response.json'),
        ),
    ]);

    $response = Prism::text()
        ->using('workers-ai', 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast')
        ->withPrompt('Hello!')
        ->asText();

    expect($response->text)->toBe('Hello! How can I help you today?');
    expect($response->finishReason)->toBe(FinishReason::Stop);
    expect($response->usage->promptTokens)->toBe(10);
    expect($response->usage->completionTokens)->toBe(8);
});

it('sends string content for user messages', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            $this->fixture('text-response.json'),
        ),
    ]);

    Prism::text()
        ->using('workers-ai', 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast')
        ->withPrompt('Hello!')
        ->asText();

    Http::assertSent(function ($request) {
        $body = json_decode($request->body(), true);
        $userMessage = collect($body['messages'])->firstWhere('role', 'user');

        // Content must be a string, not an array — the core fix
        return is_string($userMessage['content']);
    });
});

it('handles object content in responses without crashing', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            $this->fixture('structured-response-object-content.json'),
        ),
    ]);

    $response = Prism::text()
        ->using('workers-ai', 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast')
        ->withPrompt('What is the intent?')
        ->asText();

    // Should not crash — content gets JSON encoded
    expect($response->text)->toContain('greeting');
});

it('handles explicit null tool_calls from Kimi K2.6 /compat', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            $this->fixture('kimi-null-tool-calls-response.json'),
        ),
    ]);

    $response = Prism::text()
        ->using('workers-ai', 'workers-ai/@cf/moonshotai/kimi-k2.6')
        ->withPrompt('Hello')
        ->asText();

    expect($response->text)->toBe('Here is your reply.');
    expect($response->finishReason)->toBe(FinishReason::Stop);
    expect($response->steps->first()->toolCalls)->toBe([]);
});

it('handles explicit null usage token fields without crashing', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            $this->fixture('kimi-null-usage-tokens-response.json'),
        ),
    ]);

    $response = Prism::text()
        ->using('workers-ai', 'workers-ai/@cf/moonshotai/kimi-k2.6')
        ->withPrompt('Hello')
        ->asText();

    expect($response->usage->promptTokens)->toBe(0);
    expect($response->usage->completionTokens)->toBe(0);
});

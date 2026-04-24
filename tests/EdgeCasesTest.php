<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;

/**
 * Malformed / edge-case responses that the handlers should gracefully survive
 * rather than crash with a raw TypeError.
 */

it('handles empty choices array without fatal error', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            $this->fixture('empty-choices-response.json'),
        ),
    ]);

    $response = Prism::text()
        ->using('workers-ai', 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast')
        ->withPrompt('hi')
        ->asText();

    // No content, but usage block should still populate — never crash.
    expect($response->text)->toBe('');
    expect($response->usage->promptTokens)->toBe(10);
});

it('handles finish_reason: length with populated content', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            $this->fixture('length-response.json'),
        ),
    ]);

    $response = Prism::text()
        ->using('workers-ai', 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast')
        ->withPrompt('hi')
        ->asText();

    expect($response->finishReason)->toBe(FinishReason::Length);
    expect($response->text)->not->toBeEmpty();
});

it('handles finish_reason: content_filter without crashing', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            $this->fixture('content-filter-response.json'),
        ),
    ]);

    $response = Prism::text()
        ->using('workers-ai', 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast')
        ->withPrompt('hi')
        ->asText();

    expect($response->finishReason)->toBe(FinishReason::ContentFilter);
});

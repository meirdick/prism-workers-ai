<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use PrismWorkersAi\Maps\FinishReasonMap;

// Unit tests for the FinishReasonMap

it('maps stop to FinishReason::Stop', function () {
    expect(FinishReasonMap::map('stop'))->toBe(FinishReason::Stop);
});

it('maps tool_calls to FinishReason::ToolCalls', function () {
    expect(FinishReasonMap::map('tool_calls'))->toBe(FinishReason::ToolCalls);
});

it('maps length to FinishReason::Length', function () {
    expect(FinishReasonMap::map('length'))->toBe(FinishReason::Length);
});

it('maps content_filter to FinishReason::ContentFilter', function () {
    expect(FinishReasonMap::map('content_filter'))->toBe(FinishReason::ContentFilter);
});

it('maps unknown strings to FinishReason::Unknown', function () {
    expect(FinishReasonMap::map('something_else'))->toBe(FinishReason::Unknown);
});

it('maps empty string to FinishReason::Unknown', function () {
    expect(FinishReasonMap::map(''))->toBe(FinishReason::Unknown);
});

// Integration tests: finish reasons through the full handler

it('handles length finish reason in text response', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            $this->fixture('length-response.json'),
        ),
    ]);

    $response = Prism::text()
        ->using('workers-ai', 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast')
        ->withPrompt('Write a very long essay')
        ->asText();

    expect($response->finishReason)->toBe(FinishReason::Length);
    expect($response->text)->toBe('This response was truncated because it hit the max');
    expect($response->usage->completionTokens)->toBe(2048);
});

it('handles content_filter finish reason without crashing', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            $this->fixture('content-filter-response.json'),
        ),
    ]);

    $response = Prism::text()
        ->using('workers-ai', 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast')
        ->withPrompt('Something filtered')
        ->asText();

    expect($response->finishReason)->toBe(FinishReason::ContentFilter);
    expect($response->text)->toBe('');
});

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;

/**
 * Tests for the provider-specific /workers-ai/v1 endpoint.
 *
 * Production uses /compat with workers-ai/ prefixed models, but the
 * provider-specific endpoint also works with bare @cf/... model IDs.
 * These tests verify that alternative path stays functional.
 */

beforeEach(function () {
    config()->set('prism.providers.workers-ai', [
        'api_key' => 'test-api-key',
        'url' => 'https://gateway.ai.cloudflare.com/v1/test/gateway/workers-ai/v1',
    ]);
});

it('can generate text via /workers-ai/v1 with bare model ID', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            $this->fixture('text-response.json'),
        ),
    ]);

    $response = Prism::text()
        ->using('workers-ai', '@cf/meta/llama-3.3-70b-instruct-fp8-fast')
        ->withPrompt('Hello!')
        ->asText();

    expect($response->text)->toBe('Hello! How can I help you today?');
    expect($response->finishReason)->toBe(FinishReason::Stop);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'workers-ai/v1/chat/completions');
    });
});

it('can generate embeddings via /workers-ai/v1 with bare model ID', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            $this->fixture('embeddings-response.json'),
        ),
    ]);

    $response = Prism::embeddings()
        ->using('workers-ai', '@cf/baai/bge-large-en-v1.5')
        ->fromInput('Hello world')
        ->generate();

    expect($response->embeddings)->toHaveCount(1);

    Http::assertSent(function ($request) {
        $body = json_decode($request->body(), true);

        return $body['model'] === '@cf/baai/bge-large-en-v1.5'
            && str_contains($request->url(), 'workers-ai/v1/embeddings');
    });
});

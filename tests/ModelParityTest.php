<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Text\Response as TextResponse;

/**
 * Guard: every recorded real-provider response fixture must parse without a
 * fatal error, regardless of the quirks of that specific model.
 *
 * When adding a new supported model, drop a recorded response into
 * tests/Fixtures/ and add it to the dataset below — no new test code required.
 */

dataset('workers_ai_text_responses', [
    'llama-3.3-70b simple' => ['text-response.json', 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast'],
    'llama-3.3-70b with cache' => ['text-response-with-cache.json', 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast'],
    'kimi-k2.6 null tool_calls' => ['kimi-null-tool-calls-response.json', 'workers-ai/@cf/moonshotai/kimi-k2.6'],
    'kimi-k2.6 null usage tokens' => ['kimi-null-usage-tokens-response.json', 'workers-ai/@cf/moonshotai/kimi-k2.6'],
    'kimi reasoning' => ['reasoning-response.json', 'workers-ai/@cf/moonshotai/kimi-k2.5'],
    'kimi reasoning null content' => ['reasoning-null-content-response.json', 'workers-ai/@cf/moonshotai/kimi-k2.5'],
    'gemma reasoning' => ['gemma-reasoning-response.json', 'workers-ai/@cf/google/gemma-4-26b-a4b-it'],
    'finish_reason length' => ['length-response.json', 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast'],
    'finish_reason content_filter' => ['content-filter-response.json', 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast'],
    'null content' => ['null-content-response.json', 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast'],
]);

it('parses every recorded provider response without fatal error', function (string $fixture, string $model) {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response($this->fixture($fixture)),
    ]);

    $response = Prism::text()
        ->using('workers-ai', $model)
        ->withPrompt('test')
        ->asText();

    expect($response)->toBeInstanceOf(TextResponse::class);
    expect($response->steps)->not->toBeEmpty();
    expect($response->usage->promptTokens)->toBeInt();
    expect($response->usage->completionTokens)->toBeInt();
})->with('workers_ai_text_responses');

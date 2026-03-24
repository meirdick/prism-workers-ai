<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Prism\Facades\Prism;

it('can generate embeddings', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            $this->fixture('embeddings-response.json'),
        ),
    ]);

    $response = Prism::embeddings()
        ->using('workers-ai', 'workers-ai/@cf/baai/bge-large-en-v1.5')
        ->fromInput('Hello world')
        ->generate();

    expect($response->embeddings)->toHaveCount(1);
    expect($response->embeddings[0]->embedding)->toBe([0.1, 0.2, 0.3, 0.4, 0.5]);
    expect($response->usage->tokens)->toBe(5);
});

it('handles missing usage tokens in response', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            $this->fixture('embeddings-response-no-usage.json'),
        ),
    ]);

    $response = Prism::embeddings()
        ->using('workers-ai', 'workers-ai/@cf/baai/bge-large-en-v1.5')
        ->fromInput('Hello world')
        ->generate();

    expect($response->embeddings)->toHaveCount(1);
    expect($response->embeddings[0]->embedding)->toBe([0.1, 0.2, 0.3, 0.4, 0.5]);
    expect($response->usage->tokens)->toBe(0);
});

it('sends correct payload for embeddings', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            $this->fixture('embeddings-response.json'),
        ),
    ]);

    Prism::embeddings()
        ->using('workers-ai', 'workers-ai/@cf/baai/bge-large-en-v1.5')
        ->fromInput('Hello world')
        ->generate();

    Http::assertSent(function ($request) {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'workers-ai/@cf/baai/bge-large-en-v1.5'
            && str_contains($request->url(), 'embeddings');
    });
});

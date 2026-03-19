<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Prism\Facades\Prism;

it('can generate embeddings for multiple inputs', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            $this->fixture('batch-embeddings-response.json'),
        ),
    ]);

    $response = Prism::embeddings()
        ->using('workers-ai', 'workers-ai/@cf/baai/bge-large-en-v1.5')
        ->fromInput('Hello world')
        ->fromInput('Goodbye world')
        ->fromInput('Another input')
        ->generate();

    expect($response->embeddings)->toHaveCount(3);
    expect($response->embeddings[0]->embedding)->toBe([0.1, 0.2, 0.3, 0.4, 0.5]);
    expect($response->embeddings[1]->embedding)->toEqual([0.6, 0.7, 0.8, 0.9, 1.0]);
    expect($response->embeddings[2]->embedding)->toBe([0.11, 0.22, 0.33, 0.44, 0.55]);
    expect($response->usage->tokens)->toBe(15);
});

it('sends all inputs in the payload', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            $this->fixture('batch-embeddings-response.json'),
        ),
    ]);

    Prism::embeddings()
        ->using('workers-ai', 'workers-ai/@cf/baai/bge-large-en-v1.5')
        ->fromInput('Hello world')
        ->fromInput('Goodbye world')
        ->fromInput('Another input')
        ->generate();

    Http::assertSent(function ($request) {
        $body = json_decode($request->body(), true);

        // Each fromInput creates a separate content entry; inputs() flattens them
        return is_array($body['input'])
            && count($body['input']) === 3;
    });
});

<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Facades\Prism;

it('throws PrismRateLimitedException on 429 response', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            ['error' => ['type' => 'rate_limit_exceeded', 'message' => 'Too many requests']],
            429,
        ),
    ]);

    Prism::text()
        ->using('workers-ai', 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast')
        ->withPrompt('Hello!')
        ->asText();
})->throws(PrismRateLimitedException::class);

it('throws PrismException on 500 error with details', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            $this->fixture('error-response.json'),
            500,
        ),
    ]);

    Prism::text()
        ->using('workers-ai', 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast')
        ->withPrompt('Hello!')
        ->asText();
})->throws(PrismException::class);

it('throws PrismException on 401 unauthorized', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            ['error' => ['type' => 'authentication_error', 'message' => 'Invalid API key']],
            401,
        ),
    ]);

    Prism::text()
        ->using('workers-ai', 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast')
        ->withPrompt('Hello!')
        ->asText();
})->throws(PrismException::class);

it('throws PrismException when response body contains error field', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            $this->fixture('error-response.json'),
            200,
        ),
    ]);

    Prism::text()
        ->using('workers-ai', 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast')
        ->withPrompt('Hello!')
        ->asText();
})->throws(PrismException::class, 'invalid_request_error');

it('throws PrismException on structured output error response', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            $this->fixture('error-response.json'),
            200,
        ),
    ]);

    Prism::structured()
        ->using('workers-ai', 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast')
        ->withSchema(new \Prism\Prism\Schema\ObjectSchema(
            name: 'test',
            description: 'test',
            properties: [new \Prism\Prism\Schema\StringSchema('name', 'name')],
            requiredFields: ['name'],
        ))
        ->withPrompt('Hello!')
        ->generate();
})->throws(PrismException::class);

it('throws PrismException on embeddings error response', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            $this->fixture('error-response.json'),
            200,
        ),
    ]);

    Prism::embeddings()
        ->using('workers-ai', 'workers-ai/@cf/baai/bge-large-en-v1.5')
        ->fromInput('Hello')
        ->generate();
})->throws(PrismException::class);

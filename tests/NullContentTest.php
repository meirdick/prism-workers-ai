<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;

it('handles null content in text response', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            $this->fixture('null-content-response.json'),
        ),
    ]);

    $response = Prism::text()
        ->using('workers-ai', 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast')
        ->withPrompt('Hello!')
        ->asText();

    expect($response->text)->toBe('');
    expect($response->finishReason)->toBe(FinishReason::Stop);
});

it('throws PrismStructuredDecodingException on null content in structured response', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            $this->fixture('null-content-response.json'),
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
})->throws(\Prism\Prism\Exceptions\PrismStructuredDecodingException::class);

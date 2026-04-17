<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Schema\NumberSchema;

it('can generate structured output from string content', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            $this->fixture('structured-response.json'),
        ),
    ]);

    $response = Prism::structured()
        ->using('workers-ai', 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast')
        ->withSchema(new ObjectSchema(
            name: 'intent',
            description: 'User intent classification',
            properties: [
                new StringSchema('intent', 'The detected intent'),
                new NumberSchema('confidence', 'Confidence score'),
            ],
            requiredFields: ['intent', 'confidence'],
        ))
        ->withPrompt('Classify: Hello there!')
        ->generate();

    expect($response->structured)->toBeArray();
    expect($response->structured['intent'])->toBe('greeting');
    expect($response->structured['confidence'])->toBe(0.95);
    expect($response->finishReason)->toBe(FinishReason::Stop);
});

it('handles object content from Workers AI without TypeError', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            $this->fixture('structured-response-object-content.json'),
        ),
    ]);

    $response = Prism::structured()
        ->using('workers-ai', 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast')
        ->withSchema(new ObjectSchema(
            name: 'intent',
            description: 'User intent classification',
            properties: [
                new StringSchema('intent', 'The detected intent'),
                new NumberSchema('confidence', 'Confidence score'),
            ],
            requiredFields: ['intent', 'confidence'],
        ))
        ->withPrompt('Classify: Hello there!')
        ->generate();

    // Object content gets json_encoded then parsed — no TypeError crash
    expect($response->structured)->toBeArray();
    expect($response->structured['intent'])->toBe('greeting');
});

it('tracks thoughtTokens and thinking from reasoning models', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            $this->fixture('structured-response-with-reasoning.json'),
        ),
    ]);

    $response = Prism::structured()
        ->using('workers-ai', 'workers-ai/@cf/moonshotai/kimi-k2.5')
        ->withSchema(new ObjectSchema(
            name: 'intent',
            description: 'User intent classification',
            properties: [
                new StringSchema('intent', 'The detected intent'),
                new NumberSchema('confidence', 'Confidence score'),
            ],
            requiredFields: ['intent', 'confidence'],
        ))
        ->withPrompt('Classify: Hello there!')
        ->generate();

    expect($response->structured['intent'])->toBe('greeting');
    expect($response->usage->thoughtTokens)->toBe(15);
    expect($response->steps[0]->additionalContent)->toHaveKey('thinking');
    expect($response->steps[0]->additionalContent['thinking'])
        ->toBe('The user said hello, so this is clearly a greeting with high confidence.');
});

it('sends json_schema response format', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            $this->fixture('structured-response.json'),
        ),
    ]);

    Prism::structured()
        ->using('workers-ai', 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast')
        ->withSchema(new ObjectSchema(
            name: 'intent',
            description: 'User intent classification',
            properties: [
                new StringSchema('intent', 'The detected intent'),
            ],
            requiredFields: ['intent'],
        ))
        ->withPrompt('Classify: Hello')
        ->generate();

    Http::assertSent(function ($request) {
        $body = json_decode($request->body(), true);

        return data_get($body, 'response_format.type') === 'json_schema'
            && data_get($body, 'response_format.json_schema.name') === 'intent';
    });
});

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Tool;

it('handles tool call responses', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::sequence([
            Http::response($this->fixture('tool-call-response.json')),
            Http::response($this->fixture('text-response.json')),
        ]),
    ]);

    $tool = (new Tool)
        ->as('get_weather')
        ->for('Get current weather')
        ->withStringParameter('location', 'City name')
        ->using(fn (string $location) => "72°F in {$location}");

    $response = Prism::text()
        ->using('workers-ai', 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast')
        ->withTools([$tool])
        ->withMaxSteps(3)
        ->withPrompt('What is the weather in San Francisco?')
        ->asText();

    expect($response->steps)->toHaveCount(2);
    expect($response->text)->toBe('Hello! How can I help you today?');

    // Tool calls and results are on the first step
    $firstStep = $response->steps->first();
    expect($firstStep->toolCalls)->not->toBeEmpty();
    expect($firstStep->toolCalls[0]->name)->toBe('get_weather');
    expect($firstStep->toolResults)->not->toBeEmpty();
    expect($firstStep->toolResults[0]->result)->toBe('72°F in San Francisco');
});

it('sends tool definitions in request payload', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::response(
            $this->fixture('text-response.json'),
        ),
    ]);

    $tool = (new Tool)
        ->as('get_weather')
        ->for('Get current weather')
        ->withStringParameter('location', 'City name')
        ->using(fn (string $location) => "72°F in {$location}");

    Prism::text()
        ->using('workers-ai', 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast')
        ->withTools([$tool])
        ->withPrompt('What is the weather?')
        ->asText();

    Http::assertSent(function ($request) {
        $body = json_decode($request->body(), true);

        return isset($body['tools'])
            && $body['tools'][0]['type'] === 'function'
            && $body['tools'][0]['function']['name'] === 'get_weather';
    });
});

it('sends assistant content as string in tool call follow-up', function () {
    Http::fake([
        'gateway.ai.cloudflare.com/*' => Http::sequence([
            Http::response($this->fixture('tool-call-response.json')),
            Http::response($this->fixture('text-response.json')),
        ]),
    ]);

    $tool = (new Tool)
        ->as('get_weather')
        ->for('Get current weather')
        ->withStringParameter('location', 'City name')
        ->using(fn (string $location) => "72°F in {$location}");

    Prism::text()
        ->using('workers-ai', 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast')
        ->withTools([$tool])
        ->withMaxSteps(3)
        ->withPrompt('What is the weather in SF?')
        ->asText();

    // The second request (after tool call) must have assistant content as string
    Http::assertSent(function ($request) {
        $body = json_decode($request->body(), true);
        $assistantMessage = collect($body['messages'])->firstWhere('role', 'assistant');

        if ($assistantMessage === null) {
            return false;
        }

        // Content must be a string (even empty), not null or missing
        return array_key_exists('content', $assistantMessage)
            && is_string($assistantMessage['content']);
    });
});

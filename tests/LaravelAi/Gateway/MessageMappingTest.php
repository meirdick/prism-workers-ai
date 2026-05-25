<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Files\Base64Image;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;

use function Laravel\Ai\agent;

beforeEach(function () {
    config(['ai.providers.workersai' => [
        ...config('ai.providers.workersai'),
        'key' => 'test-key',
        'account_id' => 'test-account',
    ]]);
});

test('user message content is coerced to string', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(workersAiTextResponse())]);

    (new AssistantAgent)->prompt(
        'What is Laravel?',
        provider: 'workersai',
    );

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $userMessage = collect($body['messages'])->firstWhere('role', 'user');

        return $userMessage !== null
            && is_string($userMessage['content'])
            && $userMessage['content'] === 'What is Laravel?';
    });
});

test('tool result follow up maps assistant and tool result messages', function () {
    Http::fake([
        'api.cloudflare.com/*' => Http::sequence([
            Http::response(fakeWorkersAiToolCallResponse()),
            Http::response(workersAiTextResponse('The number is 72019')),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'workersai',
    );

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $followUpBody = json_decode($recorded[1][0]->body(), true);
    $followUpMessages = $followUpBody['messages'];

    $hasAssistantWithToolCalls = false;
    $hasToolResult = false;

    foreach ($followUpMessages as $msg) {
        if ($msg['role'] === 'assistant' && isset($msg['tool_calls'])) {
            $hasAssistantWithToolCalls = true;
        }

        if ($msg['role'] === 'tool') {
            $hasToolResult = true;
        }
    }

    expect($hasAssistantWithToolCalls)->toBeTrue()
        ->and($hasToolResult)->toBeTrue();
});

test('assistant message with tool_calls always includes content field (Workers AI requires it)', function () {
    // Regression: Workers AI's /compat endpoint returns 400 "oneOf at '/' not
    // met" when an assistant message has tool_calls but no content field.
    // Discovered via live paws integration test on 2026-04-26. Both the
    // non-streaming follow-up (MapsMessages::mapAssistantMessage) and the
    // streaming follow-up (HandlesTextStreaming::handleStreamingToolCalls)
    // must emit content as an empty string when the model didn't speak.
    Http::fake([
        'api.cloudflare.com/*' => Http::sequence([
            Http::response(fakeWorkersAiToolCallResponse()),
            Http::response(workersAiTextResponse('done')),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt('Generate a number', provider: 'workersai');

    $followUpBody = json_decode(Http::recorded()[1][0]->body(), true);
    $assistantMsg = collect($followUpBody['messages'])
        ->first(fn ($m) => $m['role'] === 'assistant' && isset($m['tool_calls']));

    expect($assistantMsg)->not->toBeNull()
        ->and($assistantMsg)->toHaveKey('content')
        ->and($assistantMsg['content'])->toBeString();
});

test('image attachment maps to image url content block', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(workersAiTextResponse('I see an image'))]);

    $image = new Base64Image(base64_encode('fake-image-data'), 'image/png');

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [$image],
        provider: 'workersai',
    );

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $userMessage = collect($body['messages'])->firstWhere('role', 'user');
        $content = $userMessage['content'];

        $imageBlock = collect($content)->firstWhere('type', 'image_url');

        return $imageBlock !== null
            && str_contains($imageBlock['image_url']['url'], 'image/png')
            && str_contains($imageBlock['image_url']['url'], base64_encode('fake-image-data'));
    });
});

test('document attachments throw exception', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(workersAiTextResponse())]);

    $pdf = new Base64Document(base64_encode('fake-pdf'), 'application/pdf');

    agent('You are helpful.')->prompt(
        'What is in this PDF?',
        attachments: [$pdf],
        provider: 'workersai',
    );
})->throws(InvalidArgumentException::class);


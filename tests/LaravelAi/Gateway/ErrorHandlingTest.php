<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\RateLimitedException;

use function Laravel\Ai\agent;

beforeEach(function () {
    config(['ai.providers.workersai' => [
        ...config('ai.providers.workersai'),
        'key' => 'test-key',
        'account_id' => 'test-account',
    ]]);
});

test('workersai throws on error response', function () {
    Http::fake([
        'api.cloudflare.com/*' => Http::response([
            'error' => [
                'type' => 'invalid_request_error',
                'message' => 'Model not found',
            ],
        ]),
    ]);

    agent()->prompt('Hello', provider: 'workersai');
})->throws(AiException::class, 'Model not found');

test('workersai throws on empty choices', function () {
    Http::fake([
        'api.cloudflare.com/*' => Http::response([
            'model' => '@cf/meta/llama-3.1-8b-instruct',
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 0],
        ]),
    ]);

    agent()->prompt('Hello', provider: 'workersai');
})->throws(AiException::class, 'did not contain any choices');

test('workersai throws rate limited exception on 429', function () {
    Http::fake([
        'api.cloudflare.com/*' => Http::response('Rate limited', 429),
    ]);

    agent()->prompt('Hello', provider: 'workersai');
})->throws(RateLimitedException::class);

test('workersai throws InsufficientCreditsException on 402 (auto-wired by HandlesFailoverErrors)', function () {
    // The auto-402 conversion to InsufficientCreditsException landed in
    // laravel/ai's HandlesFailoverErrors trait in ^0.7. On older lines the
    // trait still has the exception class but only invokes it from the
    // pattern-matching path, not from a bare 402 status. Detect the new
    // branch by looking for the literal status check.
    $reflection = new ReflectionMethod(
        \Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors::class,
        'withErrorHandling'
    );
    $source = file_get_contents($reflection->getFileName());
    if (! str_contains($source, '$status === 402')) {
        $this->markTestSkipped('HandlesFailoverErrors does not auto-convert 402 on this laravel/ai version');
    }

    Http::fake([
        'api.cloudflare.com/*' => Http::response([
            'error' => ['type' => 'insufficient_quota', 'message' => 'Quota exceeded'],
        ], 402),
    ]);

    agent()->prompt('Hello', provider: 'workersai');
})->throws(InsufficientCreditsException::class);

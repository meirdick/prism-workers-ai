<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\AiException;
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

// The 402 → InsufficientCreditsException conversion is provided upstream by
// `HandlesFailoverErrors::withErrorHandling` (laravel/ai v0.7). Testing it
// here would just be testing upstream's behavior, not ours — and the previous
// version inspected the trait's source file to decide when to skip, which
// would silently rot if upstream restructured the trait. Re-add when Cloudflare
// gives us a fixture showing the real quota error shape so we can map message
// patterns to InsufficientCreditsException ourselves via insufficientCreditPatterns().

<?php

declare(strict_types=1);

use Prism\Prism\PrismManager;
use PrismWorkersAi\WorkersAi;

/**
 * Covers the Prism-side parity with the Laravel AI gateway's URL config:
 * users can use `account_id` (+ optional `gateway`) on Prism too, not just
 * the explicit `url`. Same shared resolver, same error messages.
 */

it('builds the direct API URL from account_id alone', function () {
    config(['prism.providers.workers-ai' => [
        'api_key' => 'test-key',
        'account_id' => 'abc123',
    ]]);

    $manager = app(PrismManager::class);
    $provider = $manager->resolve('workers-ai');

    expect($provider->url)->toBe('https://api.cloudflare.com/client/v4/accounts/abc123/ai/v1');
});

it('builds the AI Gateway URL from account_id + gateway', function () {
    config(['prism.providers.workers-ai' => [
        'api_key' => 'test-key',
        'account_id' => 'abc123',
        'gateway' => 'production-llm',
    ]]);

    $manager = app(PrismManager::class);
    $provider = $manager->resolve('workers-ai');

    expect($provider->url)->toBe('https://gateway.ai.cloudflare.com/v1/abc123/production-llm/workers-ai/v1');
});

it('keeps explicit url as the override', function () {
    config(['prism.providers.workers-ai' => [
        'api_key' => 'test-key',
        'url' => 'https://custom.example.com/v1',
    ]]);

    $manager = app(PrismManager::class);
    $provider = $manager->resolve('workers-ai');

    expect($provider->url)->toBe('https://custom.example.com/v1');
});

it('rejects ambiguous config with both url and account_id', function () {
    config(['prism.providers.workers-ai' => [
        'api_key' => 'test-key',
        'url' => 'https://custom.example.com/v1',
        'account_id' => 'abc123',
    ]]);

    app(PrismManager::class)->resolve('workers-ai');
})->throws(\InvalidArgumentException::class, 'both `url` and `account_id`');

it('rejects an account_id that looks like a URL', function () {
    config(['prism.providers.workers-ai' => [
        'api_key' => 'test-key',
        'account_id' => 'https://api.cloudflare.com/client/v4/accounts/abc',
    ]]);

    app(PrismManager::class)->resolve('workers-ai');
})->throws(\InvalidArgumentException::class, '`account_id` looks like a URL');

it('rejects a gateway slug containing a path', function () {
    config(['prism.providers.workers-ai' => [
        'api_key' => 'test-key',
        'account_id' => 'abc',
        'gateway' => 'my-gateway/extra',
    ]]);

    app(PrismManager::class)->resolve('workers-ai');
})->throws(\InvalidArgumentException::class, '`gateway` looks malformed');

it('throws when prefixed model is used against non-compat URL', function () {
    config(['prism.providers.workers-ai' => [
        'api_key' => 'test-key',
        'account_id' => 'abc',
    ]]);

    $provider = app(PrismManager::class)->resolve('workers-ai');

    expect(fn () => $provider->normalizeModel('workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast'))
        ->toThrow(\InvalidArgumentException::class, 'has the `workers-ai/` prefix, but the configured endpoint expects bare');
});

it('throws when bare model is used against compat URL', function () {
    config(['prism.providers.workers-ai' => [
        'api_key' => 'test-key',
        'url' => 'https://gateway.ai.cloudflare.com/v1/abc/gw/compat',
    ]]);

    $provider = app(PrismManager::class)->resolve('workers-ai');

    expect(fn () => $provider->normalizeModel('@cf/meta/llama-3.3-70b-instruct-fp8-fast'))
        ->toThrow(\InvalidArgumentException::class, 'is missing the `workers-ai/` prefix');
});

it('does not auto-prefix on non-compat URLs', function () {
    config(['prism.providers.workers-ai' => [
        'api_key' => 'test-key',
        'account_id' => 'abc',
    ]]);

    $provider = app(PrismManager::class)->resolve('workers-ai');

    expect($provider->normalizeModel('@cf/meta/llama-3.3-70b-instruct-fp8-fast'))
        ->toBe('@cf/meta/llama-3.3-70b-instruct-fp8-fast');
});

it('auto-prefixes on compat URLs', function () {
    config(['prism.providers.workers-ai' => [
        'api_key' => 'test-key',
        'url' => 'https://gateway.ai.cloudflare.com/v1/abc/gw/compat',
    ]]);

    $provider = app(PrismManager::class)->resolve('workers-ai');

    expect($provider->normalizeModel('workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast'))
        ->toBe('workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast');
});

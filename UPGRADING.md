# Upgrading

## 0.4.x → 0.5.0

### TL;DR — what existing users need to do

| Your setup | Action |
| --- | --- |
| Already require `prism-php/prism` in your own `composer.json` | Nothing — `composer update` and you're done. |
| Use `Prism::text()->using('workers-ai', ...)` but never required Prism directly | Add `"prism-php/prism": "^0.99 \|\| ^0.100"` to your project's `composer.json` `require`. v0.5.0 moved it from a hard dep to a suggest. |
| Imported `PrismWorkersAi\WorkersAi::KEY` directly | Switch to `PrismWorkersAi\WorkersAiKey::PRIMARY` (and `::ALIAS` for the dashless). The old constants still work but trigger Prism autoload, defeating the no-Prism promise. They're `@deprecated` now. |
| Subclass `PrismWorkersAi\LaravelAi\WorkersAiProvider` | Drop the `Gateway $gateway` constructor argument — see "Constructor BC break" below. |
| Use the structured-output `#[Strict]` mode and you're on `laravel/ai ^0.7` | Add `#[Strict]` to your agent class — v0.7 made the flag opt-in. |
| Use the structured-output `#[Strict]` mode and you're on `laravel/ai ^0.5` / `^0.6` | Nothing — we preserve the v0.4.x always-strict default on those lines because they have no opt-in mechanism. |
| Subclass `WorkersAiGateway` and override `generateEmbeddings` | Add the new `array $providerOptions = []` parameter to your signature — v0.7 required it via LSP. |
| You're on `laravel/ai ^0.3 \|\| ^0.4` | Pin `meirdick/prism-workers-ai ^0.4`. The v0.5.0 native gateway uses primitives those lines don't have. |

### Why Prism is now optional

`laravel/ai` v0.6.1 dropped its `prism-php/prism` dependency; native gateways (Anthropic, Xai, Mistral, etc.) own their own HTTP. Our v0.5.0 follows the same direction. Forcing every consumer to install Prism just to use `agent()->prompt(provider: 'workers-ai')` made no sense once the bridge no longer needed it.

The Prism integration (`Prism::text()->using('workers-ai', ...)`, embeddings via Prism, streaming, structured) is preserved — only the dependency moved.

### Constructor BC break on `WorkersAiProvider`

The constructor dropped `Gateway $gateway` to mirror laravel/ai's native-gateway pattern (XaiProvider, MistralProvider, etc.):

```php
// Before (v0.4.x)
public function __construct(Gateway $gateway, array $config, Dispatcher $events)

// After (v0.5.0)
public function __construct(array $config, Dispatcher $events)
```

If you were extending `WorkersAiProvider` and calling `parent::__construct(...)`, drop the `$gateway` argument. The provider now lazy-instantiates its own gateway via `textGateway()` / `embeddingGateway()`.

### Gateway class moved namespaces

| Before (v0.4.x) | After (v0.5.0) |
| --- | --- |
| `PrismWorkersAi\LaravelAi\WorkersAiGateway` | `PrismWorkersAi\LaravelAi\Gateway\WorkersAiGateway` |

Update direct FQN imports (rare).

### `#[Strict]` JSON-schema mode

v0.4.x always sent `'strict' => true` in the structured-output `response_format`. v0.5.0 honors `laravel/ai` v0.7's `#[Strict]` attribute when present, but preserves the always-strict default on older lines that don't have the attribute:

| `laravel/ai` | Default | Opt-in mechanism |
| --- | --- | --- |
| `^0.7` | `strict: false` | Add `#[Strict]` to the agent (or tool) class |
| `^0.6` | `strict: true` (preserved from v0.4.x) | None — pin `meirdick/prism-workers-ai ^0.4` if you need non-strict |
| `^0.5` | `strict: true` (preserved from v0.4.x) | Same — pin `^0.4` for non-strict |

### Endpoint configuration — three options

The native gateway picks the URL shape from config keys:

```php
// config/ai.php

'providers' => [
    'workers-ai' => [
        'driver' => 'workers-ai',
        'key' => env('CLOUDFLARE_AI_API_TOKEN'),

        // Option A (recommended): direct Workers AI
        'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
        // → https://api.cloudflare.com/client/v4/accounts/{id}/ai/v1
        // Bare model IDs: '@cf/meta/llama-3.3-70b-instruct-fp8-fast'

        // Option B: Cloudflare AI Gateway (observability + caching)
        // 'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
        // 'gateway' => env('CLOUDFLARE_AI_GATEWAY'),
        // → https://gateway.ai.cloudflare.com/v1/{id}/{gw}/workers-ai/v1
        // Bare model IDs.

        // Option C: explicit URL (escape hatch; only way to hit /compat)
        // 'url' => env('WORKERS_AI_URL'),
        // → used as-is. If it ends in /compat, model IDs MUST be prefixed
        //   like 'workers-ai/@cf/meta/...'
    ],
],
```

If you were using `'url' => '...gateway.ai.cloudflare.com/.../compat'` with prefixed model IDs (`workers-ai/@cf/...`), that still works (option C). To opt into the cleaner config, switch to A or B and drop the prefix.

### Stricter config validation

Two cases that previously silently 404'd from Cloudflare now throw at request time with the fix in the message:

- **Both `url` and `account_id` set** → "Pick one". The common cleanup is removing a stale `WORKERS_AI_URL` env var after switching to `account_id`/`gateway`.
- **Model-prefix / URL mismatch** in either direction → throws (`InvalidArgumentException` on Prism path; `AiException` on Laravel AI path).

The Prism path used to auto-prefix bare model IDs on `/compat` (preserved) and silently accept prefixed IDs on `/v1` (now an error).

### Removed APIs

- `WorkersAiServiceProvider::needsGatewayOverride()` — the reflection-based probe is no longer needed. Native gateway works against the `TextGateway` / `EmbeddingGateway` contracts.
- The graceful-degradation `Log::warning` on `laravel/ai ^0.6` boot — the bridge works on v0.6+ now.

## 0.3.x → 0.4.x

No manual migration steps. All changes are additive (reasoning extraction, session affinity, prefix-cache metrics, `workersai` alias, default retry).

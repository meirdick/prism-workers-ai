# Upgrading

## 0.4.x → 0.5.0

### TL;DR — what existing users need to do

| Setup | Action |
|---|---|
| **Already require `prism-php/prism` in your own `composer.json`** | Nothing — `composer update` and you're done. |
| **Used `Prism::text()->using('workers-ai', ...)` but never required Prism directly** | Add `"prism-php/prism": "^0.99 \|\| ^0.100"` to your project's `composer.json` `require`. v0.5.0 moved it from a hard dep to a suggest. |
| **Imported `PrismWorkersAi\WorkersAi::KEY` in your code without `prism-php/prism` installed** | Switch to `PrismWorkersAi\WorkersAiKey::PRIMARY` (and `::ALIAS` for the dashless). The old constants are still there but autoload the Prism-backed class. |
| **On `laravel/ai ^0.7`** | See the v0.7 catchup section below — `#[Strict]` is now opt-in and `WorkersAiGateway::generateEmbeddings` gained a `providerOptions` parameter. |

### Why Prism is now optional

`laravel/ai` v0.6.1 dropped its `prism-php/prism` dependency. Native gateways (Anthropic, Xai, Mistral, etc.) handle their own HTTP. Our v0.5.0 follows the same direction — the Laravel AI bridge is now a native `TextGateway` + `EmbeddingGateway` with zero Prism involvement. Forcing every consumer to install Prism (and its ~150 transitive packages) just to use `agent()->prompt(provider: 'workers-ai')` made no sense once the bridge no longer needed it.

The Prism integration (`Prism::text()->using('workers-ai', ...)`, embeddings via Prism, streaming, structured) is preserved — only the dependency moved.

### Strict JSON-schema is now opt-in (v0.7 catchup)

v0.4.x always sent `'strict' => true` in the structured-output `response_format`. v0.5.0 mirrors `laravel/ai` v0.7's `#[Strict]` attribute pattern — only agents (or tools) annotated with `#[Strict]` get strict mode. If you depended on auto-strict (all-required-properties, no additionalProperties), add `#[Strict]` to your agent class.

### `EmbeddingGateway::generateEmbeddings` signature

If you subclassed `WorkersAiGateway` and overrode this method, add the new `array $providerOptions = []` parameter. Calling code is unaffected (laravel/ai forwards the value automatically).

Other v0.7 alignments are additive: tool `name()` methods are honored automatically; `top_p` is forwarded if set via `#[TopP]`; reasoning content round-trips across tool-call turns automatically.

v0.5.0 ships a **native Laravel AI gateway**, ported from the unmerged [laravel/ai#405](https://github.com/laravel/ai/pull/405). The old `PrismGateway` subclass is gone — `agent()->prompt(provider: 'workers-ai')` now talks to Cloudflare directly, with no `prism-php/prism` involvement on the Laravel AI path. Works on `laravel/ai ^0.5` and `^0.6+`.

The Prism integration (`Prism::text()->using('workers-ai', ...)`) is unchanged.

### TL;DR

Most users: just `composer update` and you're done. Read further if any of the following apply:

1. You subclass `PrismWorkersAi\LaravelAi\WorkersAiProvider` directly.
2. You import `PrismWorkersAi\LaravelAi\WorkersAiGateway` directly.
3. You configured `ai.providers.workers-ai` with an explicit `url` (especially `/compat`).
4. You're on `laravel/ai ^0.3 || ^0.4`.

### 1. Constructor BC break on `WorkersAiProvider`

The constructor lost its `Gateway $gateway` parameter to mirror laravel/ai's native-gateway pattern (XaiProvider, MistralProvider, etc.). Before:

```php
public function __construct(Gateway $gateway, array $config, Dispatcher $events)
```

After:

```php
public function __construct(array $config, Dispatcher $events)
```

If you were extending `WorkersAiProvider` and calling `parent::__construct(...)`, drop the `$gateway` argument. The provider now lazy-instantiates its own gateway via `textGateway()` / `embeddingGateway()`.

### 2. Gateway class moved

| Before (v0.4.x) | After (v0.5.0) |
| --- | --- |
| `PrismWorkersAi\LaravelAi\WorkersAiGateway` | `PrismWorkersAi\LaravelAi\Gateway\WorkersAiGateway` |

The new gateway is a complete rewrite — it `implements TextGateway, EmbeddingGateway` directly instead of `extends PrismGateway`. If you imported the class by FQN (rare), update the namespace.

### 3. Endpoint configuration — three options now

The native gateway supports three URL shapes, picked by config:

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

        // Option B: AI Gateway (adds observability + caching)
        // 'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
        // 'gateway' => env('CLOUDFLARE_AI_GATEWAY'),
        // → https://gateway.ai.cloudflare.com/v1/{id}/{gw}/workers-ai/v1
        // Bare model IDs.

        // Option C: explicit URL (escape hatch — also the only way to hit /compat)
        // 'url' => env('WORKERS_AI_URL'),
        // → used as-is; if it ends in /compat, model IDs MUST be prefixed
        //   like 'workers-ai/@cf/meta/...'
    ],
],
```

If you were using v0.4.x with `'url' => '...gateway.ai.cloudflare.com/.../compat'` and prefixed model IDs (`workers-ai/@cf/...`), **that still works** — option C above. You'll keep getting prefixed-model errors at request time if a model lacks the prefix. To opt into the cleaner config, switch to option A or B and drop the prefix from model IDs.

### 4. `laravel/ai` version support

| `laravel/ai` | `prism-workers-ai 0.5.0` |
| --- | --- |
| `^0.6.x` | ✅ Recommended target |
| `^0.5.x` | ✅ Supported |
| `^0.4.x` | ⚠️ Best-effort — interfaces predate some primitives the new gateway uses |
| `^0.3.x` | ⚠️ Best-effort |

If you're on `laravel/ai ^0.3 || ^0.4`, the safest path is to stay on `prism-workers-ai 0.4.x` for now. `0.4.6` correctly degrades the Laravel AI bridge to a `Log::warning` when running against `laravel/ai ^0.6` — Prism-side usage is unaffected on every version.

### 5. Strict config validation (Prism path included)

v0.5.0 pre-validates provider config to catch paste-errors and ambiguous setups that previously silently 404'd against Cloudflare. Two new error cases you might hit:

- **Both `url` and `account_id` set** → throws "Pick one". Previously `url` won silently. Drop whichever you don't intend; the most common cleanup is removing a stale `WORKERS_AI_URL` env var when you've switched to `account_id`/`gateway`.
- **Model-prefix / URL mismatch** → throws in either direction. A `workers-ai/`-prefixed model ID against a non-`/compat` URL (direct API or AI Gateway provider path) now throws `InvalidArgumentException` (Prism path) or `AiException` (Laravel AI path). Previously this 404'd silently from Cloudflare. Drop the prefix when using `account_id` config.

The Prism path used to *auto-prefix* bare model IDs on `/compat` URLs and silently accept prefixed IDs on `/v1` URLs. The first behavior is preserved (bare IDs still work on `/compat`); the second is now an error. If you have prefixed IDs in code AND a non-`/compat` URL, drop the prefix.

### 6. Removed APIs

- `WorkersAiServiceProvider::needsGatewayOverride()` — the reflection-based probe is no longer needed; the native gateway works on every supported `laravel/ai` line via stable contracts (`TextGateway`, `EmbeddingGateway`).
- The graceful-degradation `Log::warning` on `laravel/ai ^0.6` boot — the bridge works on v0.6+ in 0.5.0, so there's nothing to warn about.

## 0.3.x → 0.4.x

No manual migration steps. All changes are additive (reasoning extraction, session affinity, prefix-cache metrics, `workersai` alias, default retry).

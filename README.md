# Prism Workers AI

Cloudflare Workers AI provider for [Prism PHP](https://github.com/prism-php/prism) and the [Laravel AI SDK](https://github.com/laravel/ai). Targets the direct Workers AI API, the AI Gateway, or the legacy `/compat` endpoint — your config picks.

Works with both `Prism::text()->using('workers-ai', ...)` and `agent()->prompt(provider: 'workers-ai')`. The Laravel AI integration is a **native gateway** (no `prism-php/prism` dependency on the `agent()` path) — same pattern as `laravel/ai`'s built-in providers (`Anthropic`, `Xai`, `Mistral`, etc.).

## Why this package?

Workers AI's OpenAI-compatible `/compat` endpoint has subtle differences from the OpenAI API that break Prism's built-in xAI driver:

1. **Array content format** — The xAI `MessageMap` wraps user content in `[{type: "text", text: "..."}]`. Workers AI expects a plain string.
2. **Structured output type mismatch** — `/compat` may return `content` as a JSON object instead of a string, causing TypeError crashes.
3. **Missing `content` field** — Workers AI requires `content` to always be present on assistant messages, even when empty (tool call responses).
4. **No embeddings** — The xAI driver doesn't support embeddings. This package does.

## Installation

### Laravel AI SDK only (recommended for new projects)

```bash
composer require laravel/ai meirdick/prism-workers-ai
```

`prism-php/prism` is **not** required — the native Laravel AI gateway runs against Cloudflare directly. Use `agent()->prompt(provider: 'workers-ai')`.

### With Prism PHP

```bash
composer require prism-php/prism meirdick/prism-workers-ai
```

Use `Prism::text()->using('workers-ai', ...)` plus the agent integration if `laravel/ai` is also installed.

### Both

```bash
composer require laravel/ai prism-php/prism meirdick/prism-workers-ai
```

Either entry point works; they're independent.

The service provider is auto-discovered by Laravel. No additional setup needed.

## Configuration

### Prism PHP (`config/prism.php`)

The Prism path supports the same three URL shapes as the Laravel AI gateway — pick one. Same env vars work for both.

```php
'providers' => [
    'workers-ai' => [
        'api_key' => env('CLOUDFLARE_AI_API_TOKEN', ''),

        // Option A — direct Workers AI API
        'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),

        // Option B — Cloudflare AI Gateway (uncomment to enable)
        // 'gateway' => env('CLOUDFLARE_AI_GATEWAY'),

        // Option C — explicit URL escape hatch (uncomment to use)
        // 'url' => env('WORKERS_AI_URL'),
    ],
],
```

### Laravel AI SDK (`config/ai.php`)

The native gateway supports three URL shapes — pick one. The recommended path is `account_id` (+ optional `gateway`), which lets you swap between direct API and AI Gateway with a single env var.

```php
'providers' => [
    'workers-ai' => [
        'driver' => 'workers-ai',
        'key' => env('CLOUDFLARE_AI_API_TOKEN'),

        // Option A — direct Workers AI API (recommended for development)
        'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
        // → https://api.cloudflare.com/client/v4/accounts/{id}/ai/v1
        // Use bare model IDs:  '@cf/meta/llama-3.3-70b-instruct-fp8-fast'

        // Option B — Cloudflare AI Gateway (adds observability + caching)
        // 'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
        // 'gateway' => env('CLOUDFLARE_AI_GATEWAY'),
        // → https://gateway.ai.cloudflare.com/v1/{id}/{gw}/workers-ai/v1
        // Use bare model IDs.

        // Option C — explicit URL (escape hatch; only path that hits /compat)
        // 'url' => env('WORKERS_AI_URL'),
        // → used as-is. If it ends in /compat, model IDs MUST be prefixed:
        //   'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast'
    ],
],
```

### Environment

```env
CLOUDFLARE_AI_API_TOKEN=your-cloudflare-api-token
CLOUDFLARE_ACCOUNT_ID=your-account-id
# Optional — set to enable AI Gateway routing
# CLOUDFLARE_AI_GATEWAY=your-gateway-slug
```

Create a Cloudflare API token with `Workers AI: Read` permission at [dash.cloudflare.com/profile/api-tokens](https://dash.cloudflare.com/profile/api-tokens). Find your account ID in the URL of your Cloudflare dashboard.

### Endpoint and model-ID matrix

| Endpoint | URL shape | Model IDs |
|----------|-----------|-----------|
| Direct Workers AI | `api.cloudflare.com/client/v4/accounts/{id}/ai/v1` | bare: `@cf/meta/...` |
| AI Gateway provider path | `gateway.ai.cloudflare.com/v1/{id}/{gw}/workers-ai/v1` | bare: `@cf/meta/...` |
| AI Gateway universal `/compat` | `gateway.ai.cloudflare.com/v1/{id}/{gw}/compat` | prefixed: `workers-ai/@cf/meta/...` |

The native gateway validates model IDs at request time — a bare model ID against a `/compat` URL throws an `AiException` with the fix in the message. The `/compat` endpoint is the only path that requires the `workers-ai/` prefix; on `/compat` the gateway uses the prefix to route between providers.

## Usage

### With Prism PHP

```php
use Prism\Prism\Facades\Prism;

// Text generation
$response = Prism::text()
    ->using('workers-ai', 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast')
    ->withPrompt('Hello!')
    ->asText();

// Structured output
$response = Prism::structured()
    ->using('workers-ai', 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast')
    ->withSchema($schema)
    ->withPrompt('Classify this intent.')
    ->generate();

// Embeddings
$response = Prism::embeddings()
    ->using('workers-ai', 'workers-ai/@cf/baai/bge-large-en-v1.5')
    ->fromInput('Hello world')
    ->generate();

// Streaming
$stream = Prism::text()
    ->using('workers-ai', 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast')
    ->withPrompt('Tell me a story')
    ->asStream();

// Tool calling
$response = Prism::text()
    ->using('workers-ai', 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast')
    ->withTools([$weatherTool])
    ->withMaxSteps(3)
    ->withPrompt('What is the weather?')
    ->asText();

// Reasoning models (Kimi K2.5) — thinking content extracted automatically
$response = Prism::text()
    ->using('workers-ai', 'workers-ai/@cf/moonshotai/kimi-k2.5')
    ->withMaxTokens(2000) // reasoning models need higher max_tokens
    ->withPrompt('What is 15 * 37?')
    ->asText();

$response->text; // "555"
$response->steps[0]->additionalContent['thinking']; // "The user is asking for the product of 15 and 37..."

// Streaming with thinking events
$stream = Prism::text()
    ->using('workers-ai', 'workers-ai/@cf/moonshotai/kimi-k2.5')
    ->withMaxTokens(2000)
    ->withPrompt('Explain briefly why the sky is blue.')
    ->asStream();

foreach ($stream as $event) {
    // ThinkingStartEvent, ThinkingEvent (deltas), ThinkingCompleteEvent
    // then TextStartEvent, TextDeltaEvent, TextCompleteEvent
}

// Session affinity (prefix caching for multi-turn conversations)
$response = Prism::text()
    ->using('workers-ai', 'workers-ai/@cf/moonshotai/kimi-k2.5')
    ->withProviderOptions(['session_affinity' => 'ses_' . $conversation->id])
    ->withMaxTokens(2000)
    ->withPrompt('Follow-up question...')
    ->asText();

// Prefix cache metrics are available in usage when the API returns them
$response->usage->cacheReadInputTokens; // cached prompt tokens (null if no cache hit)
```

### With Laravel AI SDK

```php
use function Laravel\Ai\agent;

// Text generation via agent
$response = agent(instructions: 'You are a helpful assistant.')
    ->prompt('Hello!', provider: 'workers-ai');

// With explicit model
$response = agent(instructions: 'Be brief.')
    ->prompt('Hello!',
        provider: 'workers-ai',
        model: 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast',
    );

// Via agent class attributes
#[Provider('workers-ai')]
#[Model('workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast')]
class MyAgent implements Agent, Conversational { ... }
```

## Recommended Models

| Model | Use Case |
|-------|----------|
| `workers-ai/@cf/moonshotai/kimi-k2.5` | Frontier — smartest (256K context, reasoning, vision, tool calling) |
| `workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast` | General purpose (best quality/speed) |
| `workers-ai/@cf/qwen/qwen3-30b-a3b-fp8` | Efficient MoE (30B total / 3B active, 32K context) |
| `workers-ai/@cf/zai-org/glm-4.7-flash` | Long document summarization (131K context) |
| `workers-ai/@cf/meta/llama-3.1-8b-instruct` | Fast/cheap tasks |
| `workers-ai/@cf/qwen/qwq-32b` | Reasoning |
| `workers-ai/@cf/qwen/qwen2.5-coder-32b-instruct` | Code generation |
| `workers-ai/@cf/baai/bge-large-en-v1.5` | Embeddings (1024 dimensions) |
| `workers-ai/@cf/qwen/qwen3-embedding-0.6b` | Embeddings (1024 dimensions, 4096 input tokens) |
| `workers-ai/@cf/google/embeddinggemma-300m` | Embeddings (768 dimensions, low-latency) |

> **Embedding dimensions:** Most embedding models output 1024 dimensions. If using `embeddinggemma-300m` (768 dims), set `models.embeddings.dimensions` to `768` in your `config/ai.php` provider config.

> **Reasoning models:** Kimi K2.5 is a thinking model — it reasons before answering. Set `withMaxTokens(2000)` or higher, as reasoning tokens count against the limit. The thinking chain is available in `$response->steps[0]->additionalContent['thinking']`.

All model names must be prefixed with `workers-ai/` when routing through AI Gateway, so the gateway knows which provider to route to.

## Known quirks of the `/compat` endpoint

Cloudflare's OpenAI-compatibility layer has one notable deviation from the OpenAI
spec that this package handles internally, but is worth knowing about if you're
writing your own handlers against the same endpoint:

- **Explicit nulls instead of absent fields.** Some models (e.g. Kimi K2.6) emit
  `"tool_calls": null` and `"prompt_tokens": null` rather than omitting the keys
  when they have no value. Laravel's `data_get($data, 'key', $default)` only
  returns `$default` when the key is **missing** — it happily returns explicit
  `null`. Always coalesce with `?? $default` downstream of `data_get` when feeding
  a typed sink.

- **Network resets.** Long-running requests through the gateway occasionally die
  with cURL 56 / connection reset. This package retries 3× on transient errors by
  default (ConnectionException + HTTP 502/503/504). Override per-request via
  `withClientRetry(...)` on the Prism request, or disable globally by adding
  `'retry' => false` under `providers.workers-ai` in `config/prism.php`.

## `#[Strict]` mode against Workers AI

`laravel/ai` v0.7's `#[Strict]` attribute opts an agent into OpenAI's strict-JSON-schema mode. Our gateway honors the attribute and threads it into both `ObjectSchema(strict: ...)` (tighter schema generation) and the response_format `strict` flag.

**Behavior by `laravel/ai` version:**

| `laravel/ai` | Default | Opt-in mechanism |
| --- | --- | --- |
| `^0.7` | `strict: false` | Add `#[Strict]` to the agent (or tool) class |
| `^0.6` | `strict: true` (preserved from v0.4.x) | None — the attribute doesn't exist on this line; pin `meirdick/prism-workers-ai ^0.4` if you need non-strict |
| `^0.5` | `strict: true` (preserved from v0.4.x) | Same — pin `^0.4` for non-strict |

Live testing against Workers AI's `/compat` endpoint (direct API + AI Gateway routes) shows the schema constraints are enforced in both directions — `strict: true` and `strict: false` produce structurally identical responses against the same schema, and unknown properties don't leak through either way. Workers AI returns structured `content` as a JSON object (not a string); the gateway re-serializes it transparently. The flag is sent honestly per user intent, but Workers AI's documentation doesn't make explicit guarantees about how `strict` interacts with their `/compat` layer.

## Configuration troubleshooting

The config resolver pre-validates common paste-errors and ambiguous configurations before the first request, so you get an actionable message instead of a silent 404 from Cloudflare.

| Error message contains | What's wrong | How to fix |
|---|---|---|
| `requires an `account_id` or explicit `url`` | Neither config key set. | Add `account_id` (recommended) or `url` to the provider config. |
| `both `url` and `account_id` set` | You set both — the resolver doesn't pick one silently. | Remove whichever you don't want. Use `url` only if you need a non-standard endpoint or `/compat`. |
| ``account_id` looks like a URL` | A full URL was pasted into the `account_id` field. | Use just the bare account ID (32-char hex from `dash.cloudflare.com/<account_id>/...`). |
| ``account_id` looks malformed` | Slashes or whitespace in the value. | Strip path segments — only the account ID. |
| ``gateway` looks like a URL` / `looks malformed` | Full URL or path pasted into the `gateway` slug field. | Use just the gateway name (e.g. `production-llm`). For a full custom URL, set `url` instead and drop `account_id`/`gateway`. |
| `is missing the `workers-ai/` prefix required by the `/compat` endpoint` | Bare model ID (`@cf/...`) on a `/compat` URL. | Either add the `workers-ai/` prefix, or switch to `account_id`/`gateway` config (those use bare IDs). |
| `has the `workers-ai/` prefix, but the configured endpoint expects bare model IDs` | Prefixed model ID on a non-`/compat` endpoint. | Drop the `workers-ai/` prefix, or switch back to a `/compat` URL by setting `url` directly. |

All errors fire on the first request through the misconfigured provider — there's no boot-time scan. The Prism path raises `InvalidArgumentException`; the Laravel AI path wraps it as `Laravel\Ai\Exceptions\AiException` so it flows through `agent()`'s normal error handling.

## Compatibility

| Dependency | Status | Notes |
|------------|--------|-------|
| `prism-php/prism` `^0.99 \|\| ^0.100` | Optional (suggest) | Only needed for the Prism integration (`Prism::text()->using('workers-ai', ...)`). Laravel-AI-only consumers don't pay for it. |
| `laravel/ai` `^0.7` | ✅ Recommended (dev pin) | Full v0.7 catchup: `ToolNameResolver`, `#[Strict]` opt-in, `#[TopP]`, `providerOptions` in embeddings, `reasoning_content` replay. CI-tested. |
| `laravel/ai` `^0.6` | ✅ Supported | Native gateway works the same. `#[Strict]` doesn't exist here → we preserve the v0.4.x always-strict default. Other v0.7-only primitives silently no-op via compat shims. CI-tested. |
| `laravel/ai` `^0.5` | ✅ Supported with skips | CI-tested. 9 v0.6+/v0.7+ feature tests self-skip on this line (`Embeddings::providerOptions`, `#[TopP]`, `CanActAsTool`, `AssistantMessage->providerContentBlocks`, `#[Strict]`). Core gateway functionality works; advanced features are graceful no-ops. |
| `laravel/ai` `^0.3 \|\| ^0.4` | ⚠️ Best-effort | Pin `prism-workers-ai ^0.4`. The native gateway uses primitives those lines don't have. |

## How it works

Two independent integrations, neither of which depends on the other:

1. **Prism layer** — `PrismWorkersAi\WorkersAi` registers via `PrismManager::extend()`. Handles `Prism::text()`, `Prism::structured()`, `Prism::stream()`, `Prism::embeddings()` against Workers AI's OpenAI-compatible `/compat` endpoint with the wire-shape adjustments needed (string content, content-as-object handling, present-but-empty assistant messages, string-coerced tool results, embeddings).
2. **Laravel AI layer** — `PrismWorkersAi\LaravelAi\Gateway\WorkersAiGateway` implements `Laravel\Ai\Contracts\Gateway\TextGateway` + `EmbeddingGateway` directly. Owns its own HTTP client, message mapping, SSE streaming, multi-round tool call loop, structured output, and reasoning event emission. Registered via `AiManager::extend()`. Mirrors the native-gateway pattern used by every first-party `laravel/ai` provider.

Both share `PrismWorkersAi\Cloudflare\*` for wire-shape concerns: error envelope extraction (OpenAI + Cloudflare AI Gateway shapes), explicit-null-tolerant token parsing, JSON Schema empty-properties coercion, retry policy, streamed tool-call delta accumulation. Workers AI quirks land in one place and propagate to both integrations.

### Upstream coupling

The Laravel AI gateway implements `Laravel\Ai\Contracts\Gateway\TextGateway` and `EmbeddingGateway` (public contracts), plus uses three traits from `Laravel\Ai\Gateway\Concerns\*` (`HandlesFailoverErrors`, `InvokesTools`, `ParsesServerSentEvents`). The traits aren't part of laravel/ai's *documented* public API but they've been stable in name and signature since `^0.5` — same as the contracts. Inlining them into our `Cloudflare/*` namespace would be a 244-line copy; leaning on them is the pragmatic call. If a future laravel/ai release renames or restructures them, the gateway breaks at autoload and we ship a patch.

## Automated Setup

For a fully automated setup — including AI Gateway routing, environment configuration, and Workers AI registration — use the [laravel-cloudflare-ai-gateway](https://github.com/meirdick/laravel-cloudflare-ai-gateway) Claude Code skill:

```bash
npx skills add meirdick/laravel-cloudflare-ai-gateway
```

It handles installing this package, configuring your gateway URL, and wiring up all providers in a single guided workflow.

## Testing

```bash
composer test
```

## License

MIT

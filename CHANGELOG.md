# Changelog

## v0.5.0 — 2026-05-25

The headline: this release decouples the package from Prism. Laravel-AI-only consumers no longer pull in `prism-php/prism` and its ~150 transitive packages. The Prism integration is preserved and unchanged for users who want it.

### Native Laravel AI gateway

A native `WorkersAiGateway` for `agent()->prompt(provider: 'workers-ai')`, ported from the unmerged [laravel/ai#405](https://github.com/laravel/ai/pull/405) into our package. Replaces the old `PrismGateway` subclass shim that `laravel/ai v0.6.1` removed upstream.

- HTTP, message mapping, streaming SSE parsing, multi-round tool loops, structured output, image attachments, embeddings — all owned by this package on the Laravel AI path.
- Smart URL construction: `account_id` alone → direct Workers AI API; `account_id` + `gateway` → Cloudflare AI Gateway; explicit `url` → escape hatch (and the only path that hits the legacy `/compat` endpoint).
- Reasoning events (`ReasoningStart` / `ReasoningDelta` / `ReasoningEnd`) for thinking models (Kimi K2.5, Gemma 4, QwQ).
- Session affinity header forwarded for prefix-cache multi-turn caching.
- Validation: pairing a `/compat` URL with a bare `@cf/...` model ID (or vice versa) now throws `AiException` with the fix in the message, instead of silently 404-ing from Cloudflare.

### `prism-php/prism` is now optional

- Moved from `require` to `suggest`. Consumers who use `Prism::text()->using('workers-ai', ...)` need to add `"prism-php/prism": "^0.99 || ^0.100"` to their own `composer.json` (existing Prism users already have it).
- New `PrismWorkersAi\WorkersAiKey` (Prism-free constants: `::PRIMARY`, `::ALIAS`). `WorkersAi::KEY` / `KEY_ALIAS` are kept as `@deprecated` BC aliases — reading them autoloads the Prism-backed class, defeating the no-Prism promise.
- `WorkersAiServiceProvider::registerWithPrism()` gates on `class_exists(\Prism\Prism\PrismManager::class)`. Prism absent → that registration silently no-ops, Laravel AI bridge still wires up.
- New `no-prism` CI job locks the promise: removes `prism-php/prism` from vendor, runs the Laravel AI testsuite.

### `laravel/ai ^0.7` catchup

Each interface or idiomatic change upstream landed between `v0.6.3` and `v0.7.0` is reflected here:

- `EmbeddingGateway::generateEmbeddings` — added `array $providerOptions = []` (LSP-required for v0.7). Caller options merge into the Workers AI body; reserved keys (`model`, `input`) are stripped.
- `ToolNameResolver::resolve($tool)` — replaces `class_basename($tool)` in `MapsTools`. Tools defining a `name()` method emit the dynamic name (MCP adapters, sub-agent `AgentTool` wrappers).
- `$options->topP` — forwarded via `Arr::whereNotNull` in both streaming and non-streaming bodies when set via `#[TopP(0.9)]`.
- `AssistantMessage->providerContentBlocks` (third constructor arg, v0.6.6/v0.6.7) — gateway captures `reasoning_content` into it and replays across tool-call turns so multi-turn reasoning models keep their chain of thought.
- `#[Strict]` attribute — honored when present (v0.7+). Default behavior: see "BC breaks" below.
- HTTP 402 → `InsufficientCreditsException` auto-wired via `HandlesFailoverErrors` on v0.7+.

### Compatibility shims (`src/Cloudflare/*Compat`, `*Guard`)

So the package works across `laravel/ai ^0.5 || ^0.6 || ^0.7`:

- `StrictAttributeGuard` — reads `Strict::isAppliedTo()` on v0.7+; on older lines preserves the v0.4.x always-strict default (the attribute doesn't exist there and users have no opt-in mechanism).
- `TextOptionsCompat::topP()` — `property_exists` probe so reading `$options->topP` doesn't warn on lines that don't have the field.
- `ToolNameCompat::resolve()` — falls back to historical `class_basename`/`name()` when `Laravel\Ai\Tools\ToolNameResolver` is missing.
- `AssistantMessageFactory::make()` — reflects the constructor at runtime and drops the third arg on lines that don't accept it.

### Shared invariants in `PrismWorkersAi\Cloudflare\*`

Wire-shape concerns the Prism path and Laravel AI gateway both consume:

- `ErrorEnvelope` — extracts error message + code from both the OpenAI `{"error": {...}}` shape and the Cloudflare AI Gateway `{"errors": [...]}` shape.
- `UsageTokens` — null-tolerant token parsing (Workers AI emits explicit-null fields on some models).
- `BaseUrl` — single URL resolver for both paths; validates `account_id` / `gateway` formats and rejects ambiguous configs (both `url` and `account_id` set).
- `ModelPrefix` — validates model-ID/endpoint pairing in both directions.
- `RetryPolicy`, `ToolSchema`, `ToolCallList`, `StreamToolCallAccumulator`.

### Bug fixes discovered via live verification

- **Workers AI requires `content` on assistant tool-call messages.** The `/compat` endpoint 400s with "oneOf at '/' not met" when an assistant message has `tool_calls` but no `content` field. `MapsMessages::mapAssistantMessage` and `HandlesTextStreaming::handleStreamingToolCalls` now always emit `content` as the floor (empty string when the model didn't speak), matching the Prism-side handling. Regression-locked in `MessageMappingTest`.

### BC breaks

- **`PrismWorkersAi\LaravelAi\WorkersAiProvider` constructor** changed from `(Gateway $gateway, array $config, Dispatcher $events)` to `(array $config, Dispatcher $events)`. Mirrors `XaiProvider`/`MistralProvider`. Affects subclassers only; `agent()` / config-driven users unaffected.
- **`PrismWorkersAi\LaravelAi\WorkersAiGateway` was rewritten** and moved namespace to `PrismWorkersAi\LaravelAi\Gateway\WorkersAiGateway`. It now implements `TextGateway` + `EmbeddingGateway` directly instead of extending `Laravel\Ai\Gateway\Prism\PrismGateway`.
- **`#[Strict]` default on `laravel/ai ^0.7`** flipped from always-strict (v0.4.x) to opt-in via the attribute. On `^0.5` / `^0.6` (no attribute available) the gateway preserves the v0.4.x always-strict default — users on those lines who need non-strict should pin `meirdick/prism-workers-ai ^0.4`.
- **`prism-php/prism` no longer auto-installed.** Existing Prism users add `"prism-php/prism": "^0.99 || ^0.100"` to their `composer.json` (most already have it).
- **Prism path now validates model-prefix/endpoint pairing.** `Prism::text()->using('workers-ai', 'workers-ai/@cf/...')` against a non-`/compat` URL throws `InvalidArgumentException` instead of silently 404-ing.
- **Setting both `url` and `account_id` in config is now an error** (was previously: `url` won silently).
- `WorkersAiServiceProvider::needsGatewayOverride()` removed (reflection probe no longer needed).
- The graceful-degradation `Log::warning` on `laravel/ai ^0.6` boot removed (bridge works natively on v0.6+ now).

### Compatibility matrix

| Dependency | Support |
| --- | --- |
| `php` | `^8.2` |
| `prism-php/prism` `^0.99 || ^0.100` | Optional (suggest). Required for `Prism::text()->using('workers-ai', ...)`. |
| `laravel/ai` `^0.7` | ✅ Recommended (dev pin) |
| `laravel/ai` `^0.6` | ✅ Supported (CI matrix) |
| `laravel/ai` `^0.5` | ✅ Supported (CI matrix; 9 v0.6+/v0.7+ feature tests self-skip) |
| `laravel/ai` `^0.3 \|\| ^0.4` | Best-effort; pin `meirdick/prism-workers-ai ^0.4` |

### Live verification

- **paws** (Prism path): `php artisan test:workers-ai --test=all` → 8/8 subtests pass against live Cloudflare AI Gateway. Prefix-cache metric verified live (17 cached tokens on session-affinity second turn).
- **paws** (Prism removed + `laravel/ai v0.7.0`): `agent()->prompt`, `Embeddings::for`, tool calling, streaming all round-trip to `gateway.ai.cloudflare.com`. Prism absent from vendor confirmed.
- **endo-baddie** (v0.5.0 swapped in): 5/5 live tests pass; `Embeddings::for()` matches the production `food:generate-embeddings` command's call shape.
- **Fresh standalone project** (`laravel/ai ^0.7` + our package + zero Prism): `agent()->prompt()`, `Embeddings::for()->generate()`, tool calling all hit `api.cloudflare.com` successfully.

### Upstream coupling note

The Laravel AI gateway implements `Laravel\Ai\Contracts\Gateway\TextGateway` and `EmbeddingGateway` (public contracts), and uses three traits from `Laravel\Ai\Gateway\Concerns\*` (`HandlesFailoverErrors`, `InvokesTools`, `ParsesServerSentEvents`). The traits aren't documented as public API but have been stable in name and signature since `^0.5`. Inlining them into `Cloudflare/*` would be a 244-line copy. If a future laravel/ai release renames or restructures them, the gateway breaks at autoload and we ship a patch.

### Migration

See [UPGRADING.md](UPGRADING.md). For the typical user (config-driven, `agent()`-based, already requires Prism explicitly) there's nothing to do beyond `composer update`.

### Tests

207 tests pass on `laravel/ai v0.7.0` (dev pin). CI matrix: default + Prism-only + no-Prism + `^0.6` + `^0.5`. All green.

## v0.4.6 — 2026-04-23

### Features

- **Prefix cache metrics** — `cacheReadInputTokens` is now populated in `Usage` for text, streaming, and structured responses when Workers AI returns `prompt_tokens_details.cached_tokens`. Pair with session affinity for multi-turn cache hits.
- **Default retry on transient errors** — HTTP client now retries 3 times with 500ms base delay (exponential backoff) on connection errors (cURL 6/7/28/56) and HTTP 502/503/504. Fixes intermittent "Connection reset by peer" failures seen against the Cloudflare AI Gateway. Three ways to control it: (a) override per-request via `withClientRetry(...)` on the Prism request, (b) disable globally by setting `retry => false` in `config/prism.php` under `providers.workers-ai`, (c) leave it on (default).
- **`workersai` alias (dashless)** — provider is now registered under both `workers-ai` (primary) and `workersai` (alias) in Prism's `PrismManager` and Laravel AI's `AiManager`. Every other provider in the ecosystem (`openai`, `anthropic`, `xai`, `gemini`, `groq`, `mistral`, `deepseek`) is a single lowercase token with no separator, so users naturally reach for the dashless form. Without the alias, `provider: 'workersai'` errors with "Instance driver [workersai] is not supported" — which is silently caught by the defensive try/catch pattern common around AI calls, causing features to degrade to fallback output invisibly in production.

### Fixes

- **Missing `thoughtTokens` in Structured handler** — Reasoning models used with structured output now correctly report `thoughtTokens` and `additionalContent['thinking']`. Previously only the Text and Stream handlers tracked these.
- **Null `tool_calls` crash on reasoning models (production fix)** — Kimi K2.5/K2.6 and other `/compat` responses emit `"tool_calls": null` (explicitly null, not absent) when `finish_reason: "stop"`. `data_get($data, '...', [])` only returns the default for missing keys, not explicit nulls — so `null` flowed into `mapToolCalls(array $toolCalls)` and crashed with `TypeError`. Fixed across all remaining sites: `Text::handleToolCalls()`, `Stream::extractToolCalls()`.
- **Null usage token fields crash** — Same `data_get` anti-pattern: `"prompt_tokens": null` in responses flowed into `new Usage(int $promptTokens, ...)` causing TypeError. All token int fields (`prompt_tokens`, `completion_tokens`, `total_tokens`) now coalesce explicit nulls to `0` in Text, Structured, Stream, and Embeddings handlers.
- **Empty tool `properties` array rejected by Workers AI** — Tools with zero parameters (e.g. `GetChildrenInfoTool` with `schema()` returning `[]`) produced `"properties": []` in the request payload. Workers AI's JSON Schema validator requires `properties` to be an `object` type, rejecting the empty array with "Tool X function has invalid 'parameters' schema". Fixed by coercing empty `properties` to an empty JSON object `{}`, mirroring Prism's OpenRouter provider pattern.
- **Error messages swallowed on Cloudflare AI Gateway errors** — When the gateway returns errors in an `{ errors: [{ message: "...", code: N }] }` envelope (instead of the OpenAI-style `{ error: { message: "..." } }`), the real error message was lost and users only saw "WorkersAI Error [400]: Unknown error". The error extractor now handles both shapes plus string errors and top-level messages.
- **Streamed tool-call argument chunks dropped when payload is `"0"` or `""`** — `Stream::extractToolCalls()` used a truthy check (`if ($arguments = data_get(...))`) that skipped falsy deltas. A tool argument like `{"count":0}` streamed as `{"count":` + `0` + `}` would lose the `0` chunk, producing malformed JSON on the accumulator. Fix by [@danwall](https://github.com/danwall) in [#2](https://github.com/meirdick/prism-workers-ai/pull/2) (`arguments` delta); extended to `id` and `name` deltas for consistency.

### Contributors

- [@danwall](https://github.com/danwall) — first external contribution ([#2](https://github.com/meirdick/prism-workers-ai/pull/2)). Thanks, Dan!

### Changes

- **`smartestTextModel` default updated to Kimi K2.5** — The Laravel AI SDK `smartestTextModel()` fallback now returns `workers-ai/@cf/moonshotai/kimi-k2.5` instead of `llama-3.3-70b-instruct-fp8-fast` (which was identical to `defaultTextModel()`). Configurable via `models.text.smartest` in provider config.

### Docs

- Added new models from Cloudflare Agents Week 2026: Qwen3-30B (efficient MoE), GLM-4.7-flash (131K context), Qwen3-embedding, EmbeddingGemma-300M
- Documented prefix cache metrics in session affinity section
- Added embedding dimension note for non-1024 models
- **New `Compatibility` section in README** — explicit matrix of supported `laravel/ai` ranges and the v0.6 degradation story.
- **`UPGRADING.md`** — stub for the 0.4.x → 0.5.0 migration path.
- **`composer suggest` widened** — `laravel/ai` suggest now covers `^0.3 || ^0.4 || ^0.5 || ^0.6` with a note that the Laravel AI bridge auto-disables on v0.6+ until v0.5.0 of this package lands.

### Upcoming — v0.5.0 preview

The next release will introduce a **native Laravel AI gateway**, ported from the unmerged [laravel/ai#405](https://github.com/laravel/ai/pull/405) design, replacing the current `PrismGateway` subclass. This restores `agent()->prompt(provider: 'workers-ai')` on `laravel/ai` v0.6+, where upstream's removal of `PrismGateway` currently forces the Laravel AI bridge into a graceful-degradation warning.

Heads-up for anyone subclassing: the constructor of `PrismWorkersAi\LaravelAi\WorkersAiProvider` will change in v0.5.0 (minor BC break, scoped to direct subclassers — ordinary users of `agent()` / config-driven registration are unaffected). Full migration notes land in `UPGRADING.md` when v0.5.0 ships.

## v0.4.3 — 2026-04-06

### Fixes

- **Streaming reasoning extraction for Gemma 4** — Cloudflare's compat endpoint surfaces Gemma 4's chain-of-thought in `delta.reasoning` (streaming) and `message.reasoning` (non-streaming), not the `reasoning_content` field used by Kimi K2.5. The streaming `ExtractsThinking` trait now checks `delta.reasoning` as a fallback. Non-streaming already handled this. Forward-compatible: when Cloudflare normalizes to `reasoning_content`, that check hits first — no code change needed.

### Tests

- 2 new Gemma 4 reasoning tests (non-streaming + streaming extraction via `reasoning` field)
- 2 new fixtures: `gemma-reasoning-response.json`, `gemma-reasoning-stream-response.txt`
- 70 tests, 140 assertions total

## v0.4.2 — 2026-03-30

### Defensive

- **Graceful fallback if `PrismGateway` is removed** — Laravel AI is moving providers to direct gateways that bypass Prism. If a future `laravel/ai` version removes `PrismGateway`, the service provider now logs a warning and disables the Laravel AI bridge instead of crashing. Prism standalone usage is unaffected.

## v0.4.0 — 2026-03-22

### Features

- **Forward provider options to API payload** — Provider options like `reasoning_effort` are now passed through to the Workers AI request body for text, streaming, and structured handlers. Internal options (`session_affinity`, `schema`) are filtered out automatically. This enables reasoning output from models that require an explicit reasoning level parameter.

### Usage

```php
Prism::text()
    ->using('workers-ai', 'workers-ai/@cf/moonshotai/kimi-k2.5')
    ->withPrompt('Explain quantum entanglement')
    ->withProviderOptions(['reasoning_effort' => 'high'])
    ->asText();
```

### New Files

- `src/Concerns/ForwardsProviderOptions.php` — Trait for filtering and forwarding provider options to API payloads

### Tests

- 5 new tests: provider options forwarding (text, streaming, structured), internal key filtering, empty options passthrough
- 67 tests, 129 assertions total

## v0.3.0 — 2026-03-19

### Features

- **Reasoning model support** — Extract `reasoning_content` from thinking models like Kimi K2.5 (`@cf/moonshotai/kimi-k2.5`). Non-streaming responses surface thinking in `$response->steps[0]->additionalContent['thinking']`. Streaming emits `ThinkingStartEvent`, `ThinkingEvent` (deltas), and `ThinkingCompleteEvent` — matching Prism's xAI driver pattern.
- **Session affinity** — Opt-in `x-session-affinity` header via `->withProviderOptions(['session_affinity' => 'ses_...'])`. Routes multi-turn requests to the same Workers AI instance for prefix caching (lower TTFT, discounted cached tokens). Default off — no behavior change for existing code.

### New Files

- `src/Concerns/ExtractsThinking.php` — Trait for extracting `reasoning_content` / `reasoning` from both streaming deltas and non-streaming responses
- `src/Concerns/AppliesSessionAffinity.php` — Trait for conditionally adding `x-session-affinity` header from provider options

### Tests

- 8 new tests: reasoning extraction (text, streaming, null content, non-reasoning passthrough), session affinity (text, streaming, structured, off-by-default)
- 62 tests, 124 assertions total

### Stats

- Validated against live Cloudflare Workers AI endpoint with Kimi K2.5

## v0.2.0 — 2026-03-19

### Bug Fixes

- **Fix `content_filter` crash** — Text handler threw `PrismException: unknown finish reason` when Workers AI returned `finish_reason: "content_filter"` or any unrecognized value. Now all non-tool-call finish reasons return the response to the caller with the correct `FinishReason` enum. Verified broken → fixed via unit test and live endpoint.

### Tests

- **Align tests to production** — Tests now use `/compat` endpoint with `workers-ai/`-prefixed model IDs, matching production configuration (was `/workers-ai/v1` with bare `@cf/...` models)
- **Alternative endpoint tests** — New `AlternativeEndpointTest.php` covers the provider-specific `/workers-ai/v1` endpoint with bare model IDs
- **Error handling tests** — 429 rate limits, 500/401 HTTP errors, error field in 200 responses, errors across text/structured/embeddings handlers
- **FinishReason mapping tests** — All 5 enum values (stop, tool_calls, length, content_filter, unknown) with unit and integration coverage
- **ToolChoice mapping tests** — Auto, Any, None (throws), null, string tool name
- **Streaming tool call tests** — Tool calls in streaming mode with follow-up, argument accumulation across SSE chunks
- **Batch embeddings tests** — Multiple input embeddings, payload verification
- **Null content tests** — Null content in text (empty string) and structured (throws decoding exception)
- **Config tests** — api_key reading, key fallback, auth header, base URL routing

### Docs

- **README** — Added endpoint/model-prefix explanation table after Environment section

### Stats

- 54 tests, 107 assertions (was 23 tests, 54 assertions)
- Validated against live Cloudflare AI Gateway via paws project

## v0.1.0 — 2026-03-18

Initial release.

### Features

- **Text generation** — String content format for Workers AI `/compat` endpoint
- **Structured output** — Handles object content responses without TypeError
- **Tool calling** — Multi-step tool execution with correct assistant message format
- **Streaming** — SSE streaming via `/chat/completions`
- **Embeddings** — Via `/embeddings` endpoint (not available in xAI driver)
- **Laravel AI SDK bridge** — `agent()->prompt(provider: 'workers-ai')` works via `AiManager::extend()` with auto-detecting `PrismGateway` override

### Fixes vs xAI driver

- User messages send `content` as plain string (not `[{type: "text", text: "..."}]` array)
- Assistant messages always include `content` field (Workers AI rejects requests without it)
- Tool result content coerced to string (Workers AI rejects non-string values)
- Structured output gracefully handles `content` returned as JSON object instead of string

### Upstream

- Filed [laravel/ai#283](https://github.com/laravel/ai/issues/283) — support external Prism providers in PrismGateway
- Submitted [laravel/ai#284](https://github.com/laravel/ai/pull/284) — one-line fix to allow custom provider resolution
- Gateway override auto-disables via reflection when upstream fix lands

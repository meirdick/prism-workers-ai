# Changelog

## v0.4.6 — 2026-04-17

### Features

- **Prefix cache metrics** — `cacheReadInputTokens` is now populated in `Usage` for text, streaming, and structured responses when Workers AI returns `prompt_tokens_details.cached_tokens`. Pair with session affinity for multi-turn cache hits.

### Fixes

- **Missing `thoughtTokens` in Structured handler** — Reasoning models used with structured output now correctly report `thoughtTokens` and `additionalContent['thinking']`. Previously only the Text and Stream handlers tracked these.
- **Null `tool_calls` crash on reasoning models** — Kimi K2.5 returns `"tool_calls": null` (not absent) in responses, causing a TypeError in `mapToolCalls()`. Now handles null gracefully.

### Changes

- **`smartestTextModel` default updated to Kimi K2.5** — The Laravel AI SDK `smartestTextModel()` fallback now returns `workers-ai/@cf/moonshotai/kimi-k2.5` instead of `llama-3.3-70b-instruct-fp8-fast` (which was identical to `defaultTextModel()`). Configurable via `models.text.smartest` in provider config.

### Docs

- Added new models from Cloudflare Agents Week 2026: Qwen3-30B (efficient MoE), GLM-4.7-flash (131K context), Qwen3-embedding, EmbeddingGemma-300M
- Documented prefix cache metrics in session affinity section
- Added embedding dimension note for non-1024 models

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

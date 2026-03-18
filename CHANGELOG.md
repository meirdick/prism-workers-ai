# Changelog

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

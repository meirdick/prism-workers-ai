# Prism Workers AI

Cloudflare Workers AI provider for [Prism PHP](https://github.com/prism-php/prism) and [Laravel AI SDK](https://github.com/laravel/ai) — routes through the AI Gateway `/compat` endpoint.

Works with both `Prism::text()->using('workers-ai', ...)` and `agent()->prompt(provider: 'workers-ai')`.

## Why this package?

Workers AI's OpenAI-compatible `/compat` endpoint has subtle differences from the OpenAI API that break Prism's built-in xAI driver:

1. **Array content format** — The xAI `MessageMap` wraps user content in `[{type: "text", text: "..."}]`. Workers AI expects a plain string.
2. **Structured output type mismatch** — `/compat` may return `content` as a JSON object instead of a string, causing TypeError crashes.
3. **Missing `content` field** — Workers AI requires `content` to always be present on assistant messages, even when empty (tool call responses).
4. **No embeddings** — The xAI driver doesn't support embeddings. This package does.

## Installation

```bash
composer require meirdick/prism-workers-ai
```

The service provider is auto-discovered by Laravel. No additional setup needed.

## Configuration

### Prism PHP (`config/prism.php`)

```php
'providers' => [
    'workers-ai' => [
        'api_key' => env('CLOUDFLARE_AI_API_TOKEN', ''),
        'url' => env('WORKERS_AI_URL'),
    ],
],
```

### Laravel AI SDK (`config/ai.php`)

```php
'providers' => [
    'workers-ai' => [
        'driver' => 'workers-ai',
        'key' => env('CLOUDFLARE_AI_API_TOKEN'),
        'url' => env('WORKERS_AI_URL'),
    ],
],
```

### Environment

```env
CLOUDFLARE_AI_API_TOKEN=your-cloudflare-api-token
WORKERS_AI_URL=https://gateway.ai.cloudflare.com/v1/{account_id}/{gateway_slug}/compat
```

> **Note:** The URL must end in `/compat`, not `/workers-ai/v1`. The SDK appends `/chat/completions` automatically.

Create a Cloudflare API token with `Workers AI: Read` permission at [dash.cloudflare.com/profile/api-tokens](https://dash.cloudflare.com/profile/api-tokens).

### Understanding the `workers-ai/` model prefix

The AI Gateway has two OpenAI-compatible endpoints for Workers AI:

| Endpoint | URL | Model format |
|----------|-----|-------------|
| Universal (recommended) | `.../compat` | `workers-ai/@cf/meta/...` |
| Provider-specific | `.../workers-ai/v1` | `@cf/meta/...` |

This package targets the `/compat` endpoint. The `workers-ai/` prefix in model names tells the gateway which provider to route to. The gateway strips the prefix internally — responses return just `@cf/...`.

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

## How it works

The package registers a `workers-ai` provider at two levels:

1. **Prism layer** — via `PrismManager::extend()`, handling the actual HTTP requests with correct content formatting
2. **Laravel AI SDK layer** — via `AiManager::extend()`, bridging the `workers-ai` driver to the Prism provider

The Laravel AI SDK bridge includes a `PrismGateway` subclass that overrides `configure()` to pass the driver name as a string to Prism, bypassing the SDK's hardcoded provider enum mapping. This override auto-disables itself when `laravel/ai` adds native support for custom Prism providers ([laravel/ai#283](https://github.com/laravel/ai/issues/283), [laravel/ai#284](https://github.com/laravel/ai/pull/284)).

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

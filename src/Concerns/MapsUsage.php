<?php

declare(strict_types=1);

namespace PrismWorkersAi\Concerns;

use Prism\Prism\ValueObjects\Usage;

trait MapsUsage
{
    /**
     * Build Usage from a `usage` subtree. Coalesces explicit `null` on token
     * fields to 0 because Workers AI /compat (Kimi K2.5/K2.6) emits literal
     * `"prompt_tokens": null` instead of omitting the key — and `data_get`
     * only substitutes a default for missing keys, not explicit nulls.
     *
     * @param  array<string, mixed>  $usage
     */
    protected function mapUsage(array $usage): Usage
    {
        return new Usage(
            promptTokens: data_get($usage, 'prompt_tokens') ?? 0,
            completionTokens: data_get($usage, 'completion_tokens') ?? 0,
            cacheReadInputTokens: data_get($usage, 'prompt_tokens_details.cached_tokens'),
            thoughtTokens: data_get($usage, 'reasoning_tokens'),
        );
    }
}

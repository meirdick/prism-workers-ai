<?php

declare(strict_types=1);

namespace PrismWorkersAi\Concerns;

use Prism\Prism\ValueObjects\Usage;
use PrismWorkersAi\Cloudflare\UsageTokens;

trait MapsUsage
{
    /**
     * Build Prism's Usage from a Workers AI `usage` subtree. The wire-shape
     * coalescing (explicit-null tolerance, optional cached/reasoning tokens)
     * lives in PrismWorkersAi\Cloudflare\UsageTokens so the Laravel AI gateway
     * can reuse the exact same parsing rules without duplication.
     *
     * @param  array<string, mixed>  $usage
     */
    protected function mapUsage(array $usage): Usage
    {
        return new Usage(
            promptTokens: UsageTokens::promptTokens($usage),
            completionTokens: UsageTokens::completionTokens($usage),
            cacheReadInputTokens: UsageTokens::cachedTokens($usage),
            thoughtTokens: UsageTokens::reasoningTokens($usage),
        );
    }
}

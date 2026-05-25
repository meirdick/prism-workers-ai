<?php

declare(strict_types=1);

namespace PrismWorkersAi\Cloudflare;

/**
 * Safe accessor for laravel/ai's `#[Strict]` attribute check.
 *
 * The attribute class lives at `Laravel\Ai\Attributes\Strict` and was
 * introduced in laravel/ai v0.7.0 (PR #530). Calling `Strict::isAppliedTo()`
 * directly would FatalError on v0.5/v0.6 with "Class not found", so the
 * gateway has to gate the call behind a `class_exists` check.
 *
 * On laravel/ai ^0.5 and ^0.6 the attribute does not exist and users have
 * no way to opt in — but our v0.4.x always sent `strict: true` for every
 * structured request. Silently flipping the default to `false` on those
 * lines would be a real BC break with no opt-in mechanism. So when the
 * attribute class is absent we preserve the old `strict: true` default;
 * when it's present we honor it as opt-in like the OpenAI gateway does.
 *
 * Pre-v0.7 callers who explicitly want non-strict can drop the schema or
 * pin meirdick/prism-workers-ai ^0.4.
 */
final class StrictAttributeGuard
{
    public static function isAppliedTo(?object $target): bool
    {
        if (! class_exists(\Laravel\Ai\Attributes\Strict::class)) {
            // Preserve v0.4.x always-strict behavior on lines without the
            // opt-in mechanism. See class docblock above.
            return true;
        }

        return \Laravel\Ai\Attributes\Strict::isAppliedTo($target);
    }
}

<?php

declare(strict_types=1);

namespace PrismWorkersAi\Cloudflare;

/**
 * Safe accessor for laravel/ai's `#[Strict]` attribute check.
 *
 * The attribute class lives at `Laravel\Ai\Attributes\Strict` and was
 * introduced in laravel/ai v0.7.0 (PR #530). Calling `Strict::isAppliedTo()`
 * directly would FatalError on v0.5/v0.6 with "Class not found", so any
 * code that wants to honor the attribute needs to gate the call behind a
 * `class_exists` check.
 *
 * Wrapping that gate in one place keeps the gateway concerns clean (no
 * inline `class_exists` checks in every callsite) and gives us a single seam
 * if the attribute moves or gains semantics later.
 *
 * Returns false on older laravel/ai lines — strict mode is a v0.7-only opt-in,
 * and `false` is the right default for structured-output requests when the
 * user can't even reach the attribute.
 */
final class StrictAttributeGuard
{
    public static function isAppliedTo(?object $target): bool
    {
        if (! class_exists(\Laravel\Ai\Attributes\Strict::class)) {
            return false;
        }

        return \Laravel\Ai\Attributes\Strict::isAppliedTo($target);
    }
}

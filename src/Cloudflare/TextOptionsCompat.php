<?php

declare(strict_types=1);

namespace PrismWorkersAi\Cloudflare;

use Laravel\Ai\Gateway\TextGenerationOptions;

/**
 * Safe accessors for `TextGenerationOptions` fields that were added after
 * laravel/ai v0.5.x.
 *
 * The `topP` property landed in v0.6.6 (PR #306). On older laravel/ai
 * versions the property doesn't exist, and PHP emits an "Undefined property"
 * warning when we read it — even with the null-safe operator. Wrapping the
 * read behind `property_exists()` keeps the gateway clean on every supported
 * laravel/ai line (^0.5 || ^0.6 || ^0.7).
 *
 * Add new accessors here when laravel/ai introduces additional options we
 * want to forward (e.g. frequency_penalty, presence_penalty, etc.).
 */
final class TextOptionsCompat
{
    /**
     * Top-p (nucleus sampling) — null when the option is unset or the
     * running laravel/ai version doesn't expose the field.
     */
    public static function topP(?TextGenerationOptions $options): ?float
    {
        if ($options === null) {
            return null;
        }

        if (! property_exists($options, 'topP')) {
            return null;
        }

        return $options->topP;
    }
}

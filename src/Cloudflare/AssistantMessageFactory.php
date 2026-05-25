<?php

declare(strict_types=1);

namespace PrismWorkersAi\Cloudflare;

use Illuminate\Support\Collection;
use Laravel\Ai\Messages\AssistantMessage;
use ReflectionClass;

/**
 * Constructs `Laravel\Ai\Messages\AssistantMessage` portably across
 * laravel/ai versions.
 *
 * The `$providerContentBlocks` parameter was added in v0.6.6/v0.6.7 to
 * preserve reasoning_content (and similar provider-specific replay state)
 * across tool-call turns. On v0.5.x the constructor accepts only
 * `(string $content, ?Collection $toolCalls = null)` — passing a third
 * argument raises an "Unknown named parameter" error and aborts the
 * tool-call follow-up silently from the caller's perspective.
 *
 * This factory detects the constructor signature at runtime and falls back
 * to the two-arg form when needed. Reasoning replay is implicitly
 * unavailable on older laravel/ai, which matches the user's expectation
 * (the feature is a v0.6+ enhancement).
 */
final class AssistantMessageFactory
{
    /**
     * @param  array<string, mixed>  $providerContentBlocks
     */
    public static function make(string $content, ?Collection $toolCalls = null, array $providerContentBlocks = []): AssistantMessage
    {
        if (self::supportsProviderContentBlocks()) {
            return new AssistantMessage($content, $toolCalls, $providerContentBlocks);
        }

        return new AssistantMessage($content, $toolCalls);
    }

    /**
     * Cached reflection probe — re-checking on every call would dominate
     * the cost of hot tool-call loops. Cache is process-lifetime; tests that
     * swap the installed laravel/ai version inside a single PHP process
     * (e.g. matrix simulation without restarting) should call
     * {@see self::resetCache()} between configurations.
     */
    private static ?bool $supports = null;

    private static function supportsProviderContentBlocks(): bool
    {
        if (self::$supports !== null) {
            return self::$supports;
        }

        $ctor = (new ReflectionClass(AssistantMessage::class))->getConstructor();

        return self::$supports = $ctor !== null && $ctor->getNumberOfParameters() >= 3;
    }

    /**
     * Reset the static probe cache. Intended for test harnesses that swap
     * laravel/ai installations within a single PHP process. Production code
     * should never need to call this.
     *
     * @internal
     */
    public static function resetCache(): void
    {
        self::$supports = null;
    }
}

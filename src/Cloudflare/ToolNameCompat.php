<?php

declare(strict_types=1);

namespace PrismWorkersAi\Cloudflare;

use Laravel\Ai\Contracts\Tool;

/**
 * Safe wrapper around `Laravel\Ai\Tools\ToolNameResolver` (introduced in
 * laravel/ai v0.7.0, PR #420).
 *
 * On older laravel/ai versions the class doesn't exist, so a direct
 * `ToolNameResolver::resolve($tool)` call would fatal with "Class not
 * found". This shim falls back to `class_basename($tool)` (the pre-v0.7
 * behavior across every native gateway) while preserving dynamic-name
 * support on v0.7+ — tools or sub-agent wrappers with a `name()` method
 * automatically emit that name on the newer line.
 *
 * Adopting the shim lets us advertise `^0.5 || ^0.6 || ^0.7` compatibility
 * honestly: dynamic tool names degrade gracefully to class basenames on
 * older laravel/ai, but everything else (tool calls, schemas, request
 * shape) keeps working untouched.
 */
final class ToolNameCompat
{
    public static function resolve(Tool $tool): string
    {
        if (class_exists(\Laravel\Ai\Tools\ToolNameResolver::class)) {
            return \Laravel\Ai\Tools\ToolNameResolver::resolve($tool);
        }

        // Fallback for laravel/ai < v0.7 — matches the historical behavior
        // every native gateway used pre-PR #420.
        return is_callable([$tool, 'name']) ? $tool->name() : class_basename($tool);
    }
}

<?php

declare(strict_types=1);

namespace PrismWorkersAi\Cloudflare;

/**
 * Null-tolerant access to a `tool_calls` array from a Workers AI response.
 *
 * Reasoning models on /compat (Kimi K2.5, K2.6) emit `"tool_calls": null`
 * explicitly when `finish_reason: "stop"` rather than omitting the key. The
 * usual `data_get($data, '...tool_calls', [])` pattern fails here because the
 * default only substitutes for *missing* keys, not explicit nulls — so `null`
 * propagates into typed `array` parameters and crashes with TypeError.
 *
 * This helper centralizes the `?? []` coalesce so both the Prism path and the
 * Laravel AI path can't forget it independently.
 */
final class ToolCallList
{
    /**
     * Read tool_calls from a non-streaming response.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    public static function fromResponse(array $data): array
    {
        return data_get($data, 'choices.0.message.tool_calls') ?? [];
    }

    /**
     * Read tool_calls from a streaming delta chunk.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    public static function fromStreamDelta(array $data): array
    {
        return data_get($data, 'choices.0.delta.tool_calls') ?? [];
    }
}

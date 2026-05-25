<?php

declare(strict_types=1);

namespace PrismWorkersAi\Cloudflare;

/**
 * Accumulates streamed tool-call deltas from Workers AI's SSE chunks.
 *
 * Tool arguments arrive across multiple SSE frames: the first frame has the
 * tool `id` and `function.name`, subsequent frames append fragments of
 * `function.arguments` until the JSON is complete. A naive truthy check
 * (`if ($delta = data_get(...))`) silently drops legitimate falsy fragments
 * — most commonly the chunk containing `"0"` or `""`. Example: a tool call
 * `{"count":0}` streams as `{"count":` + `0` + `}`. Drop the `0` frame and
 * the accumulator ends up with `{"count":}`, which crashes on
 * `json_decode`.
 *
 * Always test deltas with `!== null`, not truthiness. Same rule for `id` and
 * `name` deltas — providers can emit them as empty strings during the warmup
 * frame.
 */
final class StreamToolCallAccumulator
{
    /**
     * Apply the deltas in `$chunk` to the running `$accumulator`. Returns the
     * updated accumulator. Index-keyed so multiple parallel tool calls
     * accumulate independently.
     *
     * @param  array<int, array{id: string, name: string, arguments: string}>  $accumulator
     * @param  array<int, array<string, mixed>>  $deltas
     * @return array<int, array{id: string, name: string, arguments: string}>
     */
    public static function append(array $accumulator, array $deltas): array
    {
        foreach ($deltas as $delta) {
            $index = data_get($delta, 'index', 0);

            if (! isset($accumulator[$index])) {
                $accumulator[$index] = ['id' => '', 'name' => '', 'arguments' => ''];
            }

            $id = data_get($delta, 'id');
            if ($id !== null) {
                $accumulator[$index]['id'] = (string) $id;
            }

            $name = data_get($delta, 'function.name');
            if ($name !== null) {
                $accumulator[$index]['name'] = (string) $name;
            }

            // `!== null` (not truthy): argument deltas can legitimately be "0" or "".
            $arguments = data_get($delta, 'function.arguments');
            if ($arguments !== null) {
                $accumulator[$index]['arguments'] .= (string) $arguments;
            }
        }

        return $accumulator;
    }
}

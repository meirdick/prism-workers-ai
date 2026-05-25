<?php

declare(strict_types=1);

namespace PrismWorkersAi\Cloudflare;

use stdClass;

/**
 * Builds the OpenAI-compatible `tools[]` entry that Workers AI expects.
 *
 * Workers AI's JSON Schema validator rejects `"properties": []` outright —
 * the spec requires `properties` to be an object. PHP's empty array would
 * otherwise serialize as `[]`, breaking tools that legitimately take no
 * arguments (a `now()` clock, a `get_user_info()` lookup that reads context
 * from the session). Coercing to `stdClass` forces JSON encoders to emit
 * `"properties": {}` regardless of how the array got there.
 */
final class ToolSchema
{
    /**
     * @param  array<string, mixed>  $properties
     * @param  array<int, string>  $required
     * @return array<string, mixed>
     */
    public static function toolDefinition(
        string $name,
        ?string $description,
        array $properties,
        array $required,
    ): array {
        return [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => [
                    'type' => 'object',
                    'properties' => self::coerceProperties($properties),
                    'required' => $required,
                ],
            ],
        ];
    }

    /**
     * Coerce an empty `properties` array to a stdClass so JSON encoding emits
     * `{}` rather than `[]`. Non-empty arrays pass through unchanged.
     *
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>|stdClass
     */
    public static function coerceProperties(array $properties): array|stdClass
    {
        return $properties === [] ? new stdClass : $properties;
    }
}

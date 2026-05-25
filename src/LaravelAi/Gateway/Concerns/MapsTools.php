<?php

namespace PrismWorkersAi\LaravelAi\Gateway\Concerns;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Providers\Tools\ProviderTool;
use PrismWorkersAi\Cloudflare\ToolNameCompat;
use PrismWorkersAi\Cloudflare\ToolSchema;
use RuntimeException;

trait MapsTools
{
    /**
     * Map the given tools to Chat Completions function definitions.
     */
    protected function mapTools(array $tools): array
    {
        $mapped = [];

        foreach ($tools as $tool) {
            if ($tool instanceof ProviderTool) {
                throw new RuntimeException('Workers AI does not support ['.class_basename($tool).'] provider tools.');
            }

            if ($tool instanceof Tool) {
                $mapped[] = $this->mapTool($tool);
            }
        }

        return $mapped;
    }

    /**
     * Map a regular tool to a Chat Completions function definition.
     */
    protected function mapTool(Tool $tool): array
    {
        $schema = $tool->schema(new JsonSchemaTypeFactory);

        $schemaArray = filled($schema)
            ? (new ObjectSchema($schema))->toSchema()
            : [];

        // Workers AI's JSON Schema validator rejects `properties: []` outright;
        // ToolSchema::coerceProperties forces empty arrays into stdClass so they
        // serialize as `{}`. Required for tools with zero parameters that read
        // context from elsewhere (session, request scope, etc.).
        $properties = $schemaArray['properties'] ?? [];
        $properties = is_array($properties) ? ToolSchema::coerceProperties($properties) : $properties;

        // ToolNameCompat honors an optional `name()` method on the tool —
        // critical for adapter-style tools (MCP, AgentTool wrappers for
        // CanActAsTool sub-agents) where multiple instances of the same
        // class would otherwise collapse to the same basename. On
        // laravel/ai < v0.7 (no upstream `Tools\ToolNameResolver` class) the
        // shim falls back to the historical class_basename behavior.
        return [
            'type' => 'function',
            'function' => [
                'name' => ToolNameCompat::resolve($tool),
                'description' => (string) $tool->description(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => $properties,
                    'required' => $schemaArray['required'] ?? [],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }
}

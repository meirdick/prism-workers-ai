<?php

namespace Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Tool that declares its name dynamically — adapter-style instances where the
 * class name would be ambiguous. Used by the v0.7 ToolNameResolver coverage.
 */
class DynamicNameTool implements Tool
{
    public function __construct(private string $instanceName) {}

    public function name(): string
    {
        return $this->instanceName;
    }

    public function description(): Stringable|string
    {
        return 'A dynamically-named tool used in tests.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Stringable|string
    {
        return 'ok';
    }
}

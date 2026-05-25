<?php

declare(strict_types=1);

namespace PrismWorkersAi\Maps;

use Prism\Prism\Tool;
use PrismWorkersAi\Cloudflare\ToolSchema;

class ToolMap
{
    /**
     * @param  Tool[]  $tools
     * @return array<string, mixed>
     */
    public static function map(array $tools): array
    {
        return array_map(fn (Tool $tool): array => ToolSchema::toolDefinition(
            name: $tool->name(),
            description: $tool->description(),
            properties: $tool->hasParameters() ? $tool->parametersAsArray() : [],
            required: $tool->requiredParameters(),
        ), $tools);
    }
}

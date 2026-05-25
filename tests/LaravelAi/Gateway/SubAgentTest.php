<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\ResearchSubAgent;

use function Laravel\Ai\agent;

/**
 * laravel/ai #348 introduced sub-agents-as-tools via the `CanActAsTool`
 * contract. Agents passed in the tools array are auto-wrapped in `AgentTool`,
 * whose `name()` proxies to the wrapped agent's `name()`. Our gateway's
 * MapsTools::mapTool calls ToolNameResolver::resolve() so the dynamic name
 * lands in `tools[].function.name` without any sub-agent-specific code on
 * our side.
 */

beforeEach(function () {
    config(['ai.providers.workersai' => [
        ...config('ai.providers.workersai'),
        'key' => 'test-key',
        'account_id' => 'test-account',
    ]]);
});

test('sub-agent wrapped as tool emits its dynamic name in tools[].function.name', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(workersAiTextResponse())]);

    agent(tools: [new ResearchSubAgent])->prompt('Hello', provider: 'workersai');

    Http::assertSent(function (Request $r) {
        $body = json_decode($r->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'function');

        return ($tool['function']['name'] ?? null) === 'research_subagent'
            && str_contains($tool['function']['description'] ?? '', 'specialist sub-agent');
    });
});

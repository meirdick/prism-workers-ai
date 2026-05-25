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
    // CanActAsTool + AgentTool wrapping was added in laravel/ai v0.6.8 (#348).
    // Skip the whole file on older versions — the fixture itself can't load
    // because it implements an interface that doesn't exist.
    if (! interface_exists(\Laravel\Ai\Contracts\CanActAsTool::class)) {
        $this->markTestSkipped('CanActAsTool sub-agent contract requires laravel/ai ^0.6.8 or newer');
    }

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

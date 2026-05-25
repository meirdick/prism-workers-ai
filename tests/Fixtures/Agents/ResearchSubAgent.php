<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\CanActAsTool;
use Laravel\Ai\Promptable;

/**
 * Sub-agent fixture for verifying laravel/ai's #348 sub-agent-as-tool path.
 * When passed in the parent's `tools` array, laravel/ai wraps it in an
 * `AgentTool` whose `name()` returns this class's `name()`. Our gateway then
 * surfaces that dynamic name via `ToolNameResolver::resolve()`.
 */
#[Provider('workersai')]
class ResearchSubAgent implements Agent, CanActAsTool
{
    use Promptable;

    public function name(): string
    {
        return 'research_subagent';
    }

    public function description(): string
    {
        return 'Delegates research tasks to a specialist sub-agent.';
    }

    public function instructions(): string
    {
        return 'You are a research specialist.';
    }
}

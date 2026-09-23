<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Config;

use PhpClaw\Config\LoopConfig;
use PhpClaw\Config\ProviderConfig;
use PhpClaw\Config\SkillConfig;
use PhpClaw\Config\ToolConfig;
use PHPUnit\Framework\TestCase;

final class DtoValidationTest extends TestCase
{
    public function test_loop_config_clamps_iterations_and_counters(): void
    {
        $this->assertSame(LoopConfig::DEFAULT_MAX_ITERATIONS, (new LoopConfig)->maxIterations);
        $this->assertSame(20, (new LoopConfig(maxIterations: 0))->maxIterations);
        $this->assertSame(20, (new LoopConfig(maxIterations: -5))->maxIterations);
        $this->assertSame(7, (new LoopConfig(maxIterations: 7))->maxIterations);

        $limits = new LoopConfig(maxRetries: -1, maxHistoryLength: -2, maxHistoryTokens: -3, maxToolsPerTurn: -4);
        $this->assertSame(0, $limits->maxRetries);
        $this->assertSame(0, $limits->maxHistoryLength);
        $this->assertSame(0, $limits->maxHistoryTokens);
        $this->assertSame(0, $limits->maxToolsPerTurn);
    }

    public function test_provider_config_clamps_token_budgets(): void
    {
        $this->assertSame(0, (new ProviderConfig(maxTokens: -1))->maxTokens);
        $this->assertSame(0, (new ProviderConfig(thinkingBudget: -500))->thinkingBudget);
        $this->assertSame(2000, (new ProviderConfig(maxTokens: 2000))->maxTokens);
    }

    public function test_skill_config_clamps_match_limit_to_at_least_one(): void
    {
        $this->assertSame(1, (new SkillConfig(skillMatchLimit: 0))->skillMatchLimit);
        $this->assertSame(1, (new SkillConfig(skillMatchLimit: -3))->skillMatchLimit);
        $this->assertSame(3, (new SkillConfig)->skillMatchLimit);
    }

    public function test_tool_config_falls_back_to_default_shell_allowlist(): void
    {
        $this->assertSame(ToolConfig::DEFAULT_SHELL_ALLOWLIST, (new ToolConfig)->shellAllowlist);
        $this->assertSame(['ls', 'pwd'], (new ToolConfig(shellAllowlist: ['ls', 'pwd']))->shellAllowlist);
    }
}

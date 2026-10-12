<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit;

use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Symfony\PhpClawFactory;
use PHPUnit\Framework\TestCase;

final class PerTurnToolBudgetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('OPENAI_API_KEY=sk-test-budget');
    }

    protected function tearDown(): void
    {
        putenv('OPENAI_API_KEY');
    }

    public function test_a_small_local_model_gets_the_minimal_per_turn_budget(): void
    {
        self::assertSame(
            5,
            $this->budgetFor('ollama', 'qwen2.5:7b'),
            'the adapter must hand the resolved budget to maxToolsPerTurn(); without it ToolRouter falls back to its own default and never ranks a roster of ten or fewer',
        );
    }

    public function test_a_medium_local_model_gets_the_standard_per_turn_budget(): void
    {
        self::assertSame(8, $this->budgetFor('ollama', 'qwen2.5:32b'));
    }

    public function test_a_cloud_provider_gets_the_model_derived_limit(): void
    {
        self::assertSame(
            0,
            $this->budgetFor('openai', 'gpt-4o'),
            'a cloud model is not capped by the local-provider profile; 0 lets ToolRouter derive the limit from the model id',
        );
    }

    public function test_max_tools_per_turn_from_config_overrides_the_profile(): void
    {
        self::assertSame(3, $this->factory('ollama', 'qwen2.5:7b', ['maxToolsPerTurn' => 3])->create()->config()->maxToolsPerTurn);
    }

    public function test_minus_one_from_config_removes_the_cap(): void
    {
        self::assertSame(-1, $this->factory('openai', 'gpt-4o-mini', ['maxToolsPerTurn' => -1])->create()->config()->maxToolsPerTurn);
    }

    public function test_zero_keeps_the_profile_limit(): void
    {
        self::assertSame(5, $this->factory('ollama', 'qwen2.5:7b', ['maxToolsPerTurn' => 0])->create()->config()->maxToolsPerTurn);
    }

    public function test_always_include_tools_from_config_reach_the_engine(): void
    {
        $config = $this->factory('openai', 'gpt-4o-mini', ['alwaysIncludeTools' => ['file_read', 'code_search']])->create()->config();

        self::assertSame(['file_read', 'code_search'], $config->alwaysIncludeTools);
    }

    public function test_always_include_and_tool_deny_on_the_same_name_keeps_it_denied(): void
    {
        $config = $this->factory('openai', 'gpt-4o-mini', ['toolDeny' => ['file_read'], 'alwaysIncludeTools' => ['file_read']])->create()->config();

        self::assertNotContains('file_read', array_map(static fn ($tool): string => $tool->name(), $config->tools));
    }

    private function budgetFor(string $provider, string $model): int
    {
        return $this->factory($provider, $model)->create()->config()->maxToolsPerTurn;
    }

    private function factory(string $provider, string $model, array $extra = []): PhpClawFactory
    {
        return new PhpClawFactory(...array_merge([
            'apiKey' => 'sk-test-budget',
            'provider' => $provider,
            'model' => $model,
            'storeMessages' => false,
            'maxIterations' => 10,
            'shellAllowlist' => ['ls'],
            'tools' => [],
            'memory' => new ArrayMemory,
            'workspaceRoot' => sys_get_temp_dir(),
            'systemPrompt' => '',
            'maxTokens' => 0,
            'promptCache' => false,
            'thinkingBudget' => 0,
        ], $extra));
    }
}

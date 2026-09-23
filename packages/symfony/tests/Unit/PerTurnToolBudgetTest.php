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

    public function test_a_cloud_provider_stays_uncapped(): void
    {
        self::assertSame(
            0,
            $this->budgetFor('openai', 'gpt-4o'),
            'a cloud model must not be capped by the local-provider profile',
        );
    }

    private function budgetFor(string $provider, string $model): int
    {
        $factory = new PhpClawFactory(
            apiKey: 'sk-test-budget',
            provider: $provider,
            model: $model,
            storeMessages: false,
            maxIterations: 10,
            shellAllowlist: ['ls'],
            tools: [],
            memory: new ArrayMemory,
            workspaceRoot: sys_get_temp_dir(),
            systemPrompt: '',
            maxTokens: 0,
            promptCache: false,
            thinkingBudget: 0,
        );

        return $factory->create()->config()->maxToolsPerTurn;
    }
}

<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit;

use Orchestra\Testbench\TestCase;
use PhpClaw\Laravel\Engine\EngineFactory;
use PhpClaw\Laravel\PhpClawServiceProvider;
use PhpClaw\Skills\SkillRegistry;

final class PerTurnToolBudgetTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    protected function setUp(): void
    {
        SkillRegistry::reset();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        SkillRegistry::reset();
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('phpclaw.api_key', 'test-key');
        $app['config']->set('phpclaw.memory_driver', 'array');
    }

    public function test_a_small_local_model_gets_the_minimal_per_turn_budget(): void
    {
        config(['phpclaw.provider' => 'ollama', 'phpclaw.model' => 'qwen2.5:7b']);

        self::assertSame(
            5,
            EngineFactory::build($this->app)->config()->maxToolsPerTurn,
            'the adapter must hand the resolved budget to maxToolsPerTurn(); without it ToolRouter falls back to its own default and sends ten tools',
        );
    }

    public function test_a_medium_local_model_gets_the_standard_per_turn_budget(): void
    {
        config(['phpclaw.provider' => 'ollama', 'phpclaw.model' => 'qwen2.5:32b']);

        self::assertSame(8, EngineFactory::build($this->app)->config()->maxToolsPerTurn);
    }

    public function test_a_cloud_provider_gets_the_model_derived_limit(): void
    {
        config(['phpclaw.provider' => 'openai', 'phpclaw.model' => 'gpt-4o']);

        self::assertSame(
            0,
            EngineFactory::build($this->app)->config()->maxToolsPerTurn,
            'a cloud model is not capped by the local-provider profile; 0 lets ToolRouter derive the limit from the model id',
        );
    }

    public function test_max_tools_per_turn_from_config_overrides_the_profile(): void
    {
        config(['phpclaw.provider' => 'ollama', 'phpclaw.model' => 'qwen2.5:7b', 'phpclaw.max_tools_per_turn' => 3]);

        self::assertSame(3, EngineFactory::build($this->app)->config()->maxToolsPerTurn);
    }

    public function test_minus_one_from_config_removes_the_cap(): void
    {
        config(['phpclaw.provider' => 'anthropic', 'phpclaw.model' => 'claude-haiku-5-5', 'phpclaw.max_tools_per_turn' => -1]);

        self::assertSame(-1, EngineFactory::build($this->app)->config()->maxToolsPerTurn);
    }

    public function test_zero_keeps_the_profile_limit(): void
    {
        config(['phpclaw.provider' => 'ollama', 'phpclaw.model' => 'qwen2.5:7b', 'phpclaw.max_tools_per_turn' => 0]);

        self::assertSame(5, EngineFactory::build($this->app)->config()->maxToolsPerTurn);
    }

    public function test_always_include_tools_from_config_reach_the_engine(): void
    {
        config(['phpclaw.provider' => 'anthropic', 'phpclaw.model' => 'claude-haiku-5-5', 'phpclaw.always_include_tools' => ['file_read', 'code_search']]);

        self::assertSame(['file_read', 'code_search'], EngineFactory::build($this->app)->config()->alwaysIncludeTools);
    }

    public function test_always_include_and_tool_deny_on_the_same_name_keeps_it_denied(): void
    {
        config([
            'phpclaw.provider' => 'anthropic',
            'phpclaw.model' => 'claude-haiku-5-5',
            'phpclaw.tool_deny' => ['file_read'],
            'phpclaw.always_include_tools' => ['file_read'],
        ]);

        $names = array_map(static fn ($tool): string => $tool->name(), EngineFactory::build($this->app)->config()->tools);

        self::assertNotContains('file_read', $names);
    }
}

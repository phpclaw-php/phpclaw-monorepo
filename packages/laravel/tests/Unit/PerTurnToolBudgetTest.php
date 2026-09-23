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

    public function test_a_cloud_provider_stays_uncapped(): void
    {
        config(['phpclaw.provider' => 'openai', 'phpclaw.model' => 'gpt-4o']);

        self::assertSame(
            0,
            EngineFactory::build($this->app)->config()->maxToolsPerTurn,
            'a cloud model must not be capped by the local-provider profile',
        );
    }
}

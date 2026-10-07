<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit;

use Illuminate\Support\Facades\Log;
use Orchestra\Testbench\TestCase;
use PhpClaw\Agent\CliApprovalGate;
use PhpClaw\Agent\SuspendableApprovalGate;
use PhpClaw\Laravel\Engine\EngineFactory;
use PhpClaw\Laravel\PhpClawServiceProvider;
use PhpClaw\Skills\SkillRegistry;

final class EngineFactoryDurableRunsTest extends TestCase
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
        $app['config']->set('phpclaw.provider', 'anthropic');
        $app['config']->set('phpclaw.memory_driver', 'array');
    }

    public function test_durable_runs_are_off_by_default_and_the_terminal_gate_stays(): void
    {
        $config = EngineFactory::build($this->app)->config();

        self::assertNull($config->durableRuns);
        self::assertInstanceOf(CliApprovalGate::class, $config->approvalGate);
    }

    public function test_switched_on_a_web_or_worker_engine_saves_runs_and_pauses_for_approval(): void
    {
        config(['phpclaw.durable_runs' => true, 'phpclaw.durable_step_budget' => 3]);

        $config = EngineFactory::build($this->app, terminalApproval: false)->config();

        self::assertSame(3, $config->durableRuns?->steps);
        self::assertNull($config->durableRuns?->deadlineSeconds);
        self::assertInstanceOf(SuspendableApprovalGate::class, $config->approvalGate);
    }

    public function test_switched_on_an_interactive_terminal_saves_runs_and_keeps_the_y_n_prompt(): void
    {
        config(['phpclaw.durable_runs' => true]);

        $config = EngineFactory::build($this->app, terminalApproval: true)->config();

        self::assertNotNull($config->durableRuns);
        self::assertInstanceOf(CliApprovalGate::class, $config->approvalGate);
    }

    public function test_the_gate_follows_the_console_when_not_told(): void
    {
        config(['phpclaw.durable_runs' => true]);

        self::assertInstanceOf(CliApprovalGate::class, EngineFactory::build($this->app)->config()->approvalGate);
    }

    public function test_a_configured_deadline_is_applied(): void
    {
        config(['phpclaw.durable_runs' => true, 'phpclaw.durable_deadline_seconds' => '30']);

        self::assertSame(30, EngineFactory::build($this->app, terminalApproval: false)->config()->durableRuns?->deadlineSeconds);
    }

    public function test_switched_on_with_message_storing_off_is_skipped_with_a_warning(): void
    {
        config(['phpclaw.durable_runs' => true, 'phpclaw.store_messages' => false]);
        Log::spy();

        $config = EngineFactory::build($this->app, terminalApproval: false)->config();

        self::assertNull($config->durableRuns);
        self::assertInstanceOf(CliApprovalGate::class, $config->approvalGate);
        Log::shouldHaveReceived('warning')->once();
    }
}

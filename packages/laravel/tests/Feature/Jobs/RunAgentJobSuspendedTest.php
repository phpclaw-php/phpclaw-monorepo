<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Feature\Jobs;

use Orchestra\Testbench\TestCase;
use PhpClaw\Claw;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Laravel\Enums\JobStatus;
use PhpClaw\Laravel\Jobs\RunAgentJob;
use PhpClaw\Laravel\PhpClawServiceProvider;
use PhpClaw\Laravel\Tests\Feature\Durable\CountingTool;
use PhpClaw\Laravel\Tests\Feature\Durable\ScriptedProvider;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Memory\Contracts\MemoryInterface;

final class RunAgentJobSuspendedTest extends TestCase
{
    private ArrayMemory $memory;

    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        HookRegistry::reset();
        CountingTool::$runs = [];
        $this->memory = new ArrayMemory;
        $this->app->instance(MemoryInterface::class, $this->memory);
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
        parent::tearDown();
    }

    private function pausingAgent(): Claw
    {
        return Claw::builder()
            ->providerOverride(new ScriptedProvider(ScriptedProvider::refundPlan()))
            ->memory($this->memory)
            ->useDefaultGuards(false)
            ->tools([new CountingTool('refund')])
            ->withSuspendableApproval()
            ->build();
    }

    public function test_a_queued_run_that_pauses_is_stored_as_suspended_with_its_run_id_and_is_not_retried(): void
    {
        $failed = [];
        HookRegistry::on('job.failed', static function (array $c) use (&$failed): void {
            $failed[] = $c;
        });

        (new RunAgentJob(jobId: 'job_p', message: 'refund order 7', userId: '7'))->handle($this->pausingAgent(), $this->memory);

        $stored = $this->memory->get('job_p', RunAgentJob::NAMESPACE);
        self::assertSame(JobStatus::Suspended->value, $stored['status']);
        self::assertSame('awaiting_approval', $stored['run_status']);
        self::assertNotEmpty($stored['run_id']);
        self::assertSame('7', $stored[RunAgentJob::OWNER_KEY]);
        self::assertSame([], $failed);
        self::assertSame([], CountingTool::$runs);
    }

    public function test_jobs_status_reports_a_suspended_job_with_its_run(): void
    {
        $this->memory->set('job_s', ['status' => JobStatus::Suspended->value, 'run_id' => '01JRUN0000000000000000000A', 'run_status' => 'awaiting_approval', 'at' => date('c')], RunAgentJob::NAMESPACE);

        $this->artisan('phpclaw:jobs:status', ['jobId' => 'job_s'])
            ->expectsOutputToContain('01JRUN0000000000000000000A')
            ->expectsOutputToContain('phpclaw:runs')
            ->assertExitCode(4);
    }
}

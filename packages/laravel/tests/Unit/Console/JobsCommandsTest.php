<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit\Console;

use Orchestra\Testbench\TestCase;
use PhpClaw\Laravel\Jobs\RunAgentJob;
use PhpClaw\Laravel\PhpClawServiceProvider;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Memory\Contracts\MemoryInterface;

final class JobsCommandsTest extends TestCase
{
    private ArrayMemory $memory;

    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('phpclaw.api_key', 'test-key');
        $app['config']->set('phpclaw.memory_driver', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->memory = new ArrayMemory;
        $this->app->instance(MemoryInterface::class, $this->memory);
    }

    public function test_status_done_prints_result_and_returns_zero(): void
    {
        $this->memory->set(
            key: 'job_done',
            value: [
                'status' => 'done',
                'text' => 'all good',
                'provider' => 'anthropic',
                'model' => 'claude-haiku-4-5-20251001',
                'tokens' => 150,
                'iterations' => 2,
                'duration_ms' => 1234,
                'at' => '2026-04-14T12:00:00+00:00',
            ],
            namespace: RunAgentJob::NAMESPACE,
        );

        $this->artisan('phpclaw:jobs:status', ['jobId' => 'job_done'])
            ->assertExitCode(0)
            ->expectsOutputToContain('done')
            ->expectsOutputToContain('anthropic')
            ->expectsOutputToContain('all good');
    }

    public function test_status_failed_returns_exit_code_2(): void
    {
        $this->memory->set(
            key: 'job_fail',
            value: [
                'status' => 'failed',
                'error' => 'boom',
                'at' => '2026-04-14T12:00:00+00:00',
            ],
            namespace: RunAgentJob::NAMESPACE,
        );

        $this->artisan('phpclaw:jobs:status', ['jobId' => 'job_fail'])
            ->assertExitCode(2)
            ->expectsOutputToContain('failed')
            ->expectsOutputToContain('boom');
    }

    public function test_status_pending_returns_exit_code_1(): void
    {
        $this->artisan('phpclaw:jobs:status', ['jobId' => 'never_dispatched'])
            ->assertExitCode(1)
            ->expectsOutputToContain('pending');
    }

    public function test_status_unknown_status_returns_exit_code_3(): void
    {
        $this->memory->set(
            key: 'job_weird',
            value: ['status' => 'weird_status'],
            namespace: RunAgentJob::NAMESPACE,
        );

        $this->artisan('phpclaw:jobs:status', ['jobId' => 'job_weird'])
            ->assertExitCode(3);
    }

    public function test_list_prints_row_per_stored_job(): void
    {
        $this->memory->set(
            key: 'job_a',
            value: [
                'status' => 'done',
                'provider' => 'anthropic',
                'model' => 'claude-haiku-4-5-20251001',
                'at' => '2026-04-14T12:00:00+00:00',
            ],
            namespace: RunAgentJob::NAMESPACE,
        );

        $this->memory->set(
            key: 'job_b',
            value: [
                'status' => 'failed',
                'provider' => 'openai',
                'model' => 'gpt-4o-mini',
                'at' => '2026-04-14T12:05:00+00:00',
            ],
            namespace: RunAgentJob::NAMESPACE,
        );

        $this->artisan('phpclaw:jobs:list')
            ->assertSuccessful()
            ->expectsOutputToContain('job_a')
            ->expectsOutputToContain('job_b');
    }

    public function test_list_shows_empty_message_when_no_jobs(): void
    {
        $this->artisan('phpclaw:jobs:list')
            ->assertSuccessful()
            ->expectsOutputToContain('No job results stored');
    }
}

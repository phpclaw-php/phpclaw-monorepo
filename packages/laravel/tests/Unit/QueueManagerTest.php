<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit;

use Illuminate\Support\Facades\Bus;
use Orchestra\Testbench\TestCase;
use PhpClaw\Laravel\Jobs\RunAgentJob;
use PhpClaw\Laravel\PhpClawServiceProvider;
use PhpClaw\Laravel\QueueManager;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Memory\Contracts\MemoryInterface;

final class QueueManagerTest extends TestCase
{
    private ArrayMemory $memory;

    private QueueManager $queue;

    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->memory = new ArrayMemory;
        $this->app->instance(MemoryInterface::class, $this->memory);

        $this->queue = new QueueManager(memory: $this->memory);
    }

    public function test_dispatch_send_returns_ulid_string(): void
    {
        Bus::fake();

        $jobId = $this->queue->dispatchSend('hello world');

        $this->assertNotSame('', $jobId);
        $this->assertSame(26, strlen($jobId), 'ULIDs are 26 chars');
    }

    public function test_dispatch_send_dispatches_run_agent_job_with_default_ttl(): void
    {
        Bus::fake();

        $jobId = $this->queue->dispatchSend('ping');

        Bus::assertDispatched(RunAgentJob::class, function (RunAgentJob $job) use ($jobId): bool {
            return $job->jobId === $jobId
                && $job->message === 'ping'
                && $job->ttl === 3600;
        });
    }

    public function test_dispatch_send_passes_custom_ttl(): void
    {
        Bus::fake();

        $this->queue->dispatchSend('ping', ttl: 120);

        Bus::assertDispatched(RunAgentJob::class, function (RunAgentJob $job): bool {
            return $job->ttl === 120;
        });
    }

    public function test_dispatch_send_produces_unique_ids(): void
    {
        Bus::fake();

        $ids = [
            $this->queue->dispatchSend('a'),
            $this->queue->dispatchSend('b'),
            $this->queue->dispatchSend('c'),
        ];

        $this->assertCount(3, array_unique($ids));
    }

    public function test_poll_result_returns_null_when_job_unknown(): void
    {
        $this->assertNull($this->queue->pollResult('nonexistent_job'));
    }

    public function test_poll_result_returns_stored_array(): void
    {
        $payload = ['status' => 'done', 'text' => 'ok'];
        $this->memory->set('job_x', $payload, RunAgentJob::NAMESPACE);

        $this->assertSame($payload, $this->queue->pollResult('job_x'));
    }

    public function test_poll_result_returns_null_when_stored_value_is_not_array(): void
    {
        $this->memory->set('job_bad', 'corrupted', RunAgentJob::NAMESPACE);

        $this->assertNull($this->queue->pollResult('job_bad'));
    }

    public function test_poll_result_is_namespace_scoped(): void
    {
        $this->memory->set('job_y', ['status' => 'done'], 'other_ns');

        $this->assertNull($this->queue->pollResult('job_y'));
    }

    public function test_list_jobs_returns_every_phpclaw_jobs_entry(): void
    {
        $this->memory->set('j1', ['status' => 'done'], RunAgentJob::NAMESPACE);
        $this->memory->set('j2', ['status' => 'failed'], RunAgentJob::NAMESPACE);

        $this->memory->set('other', ['status' => 'done'], 'unrelated_ns');

        $jobs = $this->queue->listJobs();

        $this->assertCount(2, $jobs);
        $this->assertArrayHasKey('j1', $jobs);
        $this->assertArrayHasKey('j2', $jobs);
        $this->assertArrayNotHasKey('other', $jobs);
    }

    public function test_list_jobs_returns_empty_when_nothing_dispatched(): void
    {
        $this->assertSame([], $this->queue->listJobs());
    }

    public function test_list_jobs_filters_out_non_array_values(): void
    {
        $this->memory->set('j_ok', ['status' => 'done'], RunAgentJob::NAMESPACE);
        $this->memory->set('j_bad', 'corrupted', RunAgentJob::NAMESPACE);

        $jobs = $this->queue->listJobs();

        $this->assertArrayHasKey('j_ok', $jobs);
        $this->assertArrayNotHasKey('j_bad', $jobs);
    }

    public function test_forget_job_removes_entry(): void
    {
        $this->memory->set('gone', ['status' => 'done'], RunAgentJob::NAMESPACE);

        $this->queue->forgetJob('gone');

        $this->assertNull($this->queue->pollResult('gone'));
    }

    public function test_forget_job_noop_on_unknown_id(): void
    {
        $before = $this->queue->listJobs();

        $this->queue->forgetJob('ghost');

        $this->assertSame($before, $this->queue->listJobs(), 'forgetting an unknown job must leave the roster untouched');
    }
}

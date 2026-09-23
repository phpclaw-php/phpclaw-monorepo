<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;
use Orchestra\Testbench\TestCase;
use PhpClaw\Laravel\Jobs\RunAgentJob;
use PhpClaw\Laravel\LaravelIdentityResolver;
use PhpClaw\Laravel\PhpClawServiceProvider;
use PhpClaw\Laravel\QueueManager;
use PhpClaw\Memory\Contracts\MemoryInterface;

final class JobOwnershipTest extends TestCase
{
    private QueueManager $queue;

    private MemoryInterface $memory;

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

        $this->memory = $this->app->make(MemoryInterface::class);
        $this->queue = new QueueManager($this->memory);
    }

    private function loginAs(int|string $id): void
    {
        $this->actingAs(new GenericUser(['id' => $id]));
    }

    private function storeJob(string $jobId, string $userId): void
    {
        $this->memory->set($jobId, [
            'status' => 'done',
            'text' => "result for {$userId}",
            RunAgentJob::OWNER_KEY => $userId,
        ], RunAgentJob::NAMESPACE);
    }

    public function test_poll_result_hides_another_users_job(): void
    {
        $this->storeJob('job-a', '7');

        $this->loginAs(9);

        self::assertNull($this->queue->pollResult('job-a'));
    }

    public function test_poll_result_returns_the_owners_own_job(): void
    {
        $this->storeJob('job-b', '7');

        $this->loginAs(7);

        self::assertSame('result for 7', $this->queue->pollResult('job-b')['text']);
    }

    public function test_list_jobs_returns_only_the_callers_own(): void
    {
        $this->storeJob('mine', '7');
        $this->storeJob('theirs', '9');

        $this->loginAs(7);

        self::assertSame(['mine'], array_keys($this->queue->listJobs()));
    }

    public function test_forget_job_refuses_another_users_job(): void
    {
        $this->storeJob('victim', '7');

        $this->loginAs(9);
        $this->queue->forgetJob('victim');

        self::assertTrue($this->memory->has('victim', RunAgentJob::NAMESPACE));
    }

    public function test_forget_job_removes_the_callers_own(): void
    {
        $this->storeJob('disposable', '7');

        $this->loginAs(7);
        $this->queue->forgetJob('disposable');

        self::assertFalse($this->memory->has('disposable', RunAgentJob::NAMESPACE));
    }

    public function test_manage_all_reaches_every_job(): void
    {
        $this->storeJob('mine', '7');
        $this->storeJob('theirs', '9');

        $this->loginAs(7);
        Gate::define(LaravelIdentityResolver::MANAGE_ALL_ABILITY, static fn (): bool => true);

        self::assertCount(2, $this->queue->listJobs());
    }

    public function test_a_console_run_sees_jobs_written_before_ownership_existed(): void
    {
        $this->memory->set('legacy', ['status' => 'done', 'text' => 'old'], RunAgentJob::NAMESPACE);

        self::assertSame('old', $this->queue->pollResult('legacy')['text']);
    }

    public function test_a_uuid_keyed_owner_is_scoped_correctly(): void
    {
        $this->storeJob('uuid-job', 'aaaaaaaa-0000-4000-8000-000000000001');

        $this->loginAs('bbbbbbbb-0000-4000-8000-000000000002');
        self::assertNull($this->queue->pollResult('uuid-job'));

        $this->loginAs('aaaaaaaa-0000-4000-8000-000000000001');
        self::assertNotNull($this->queue->pollResult('uuid-job'));
    }
}

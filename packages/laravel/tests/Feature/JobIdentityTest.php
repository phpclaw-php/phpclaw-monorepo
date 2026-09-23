<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PhpClaw\Laravel\Jobs\RunAgentJob;
use PhpClaw\Laravel\LaravelIdentityResolver;
use PhpClaw\Laravel\PhpClawServiceProvider;
use PhpClaw\Laravel\QueueManager;
use PhpClaw\Memory\Contracts\MemoryInterface;

final class JobIdentityTest extends TestCase
{
    private MemoryInterface $memory;

    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('phpclaw.api_key', 'test-key');
        $app['config']->set('phpclaw.memory_driver', 'array');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('auth.providers.users.model', JobIdentityUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->memory = $this->app->make(MemoryInterface::class);

        Schema::create('users', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name')->nullable();
        });

        JobIdentityUser::query()->create(['id' => 2, 'name' => 'usera']);
    }

    public function test_dispatch_captures_the_queueing_user(): void
    {
        Queue::fake();

        $this->actingAs(JobIdentityUser::query()->find(2));

        $jobId = (new QueueManager($this->memory))->dispatchSend('anything');

        Queue::assertPushed(RunAgentJob::class, static function (RunAgentJob $job) use ($jobId): bool {
            return $job->jobId === $jobId && $job->userId === '2';
        });
    }

    public function test_the_worker_acts_as_the_queueing_user_while_the_job_runs(): void
    {
        $seen = null;
        $agent = new RecordingClawFake(static function () use (&$seen): void {
            $seen = LaravelIdentityResolver::actingUserId();
        });

        Auth::forgetUser();
        self::assertSame('', LaravelIdentityResolver::actingUserId());

        (new RunAgentJob('job-1', 'anything', 3600, '2'))->handle($agent, $this->memory);

        self::assertSame('2', $seen);
    }

    public function test_the_worker_holds_no_user_after_the_job_returns(): void
    {
        $agent = new RecordingClawFake(static function (): void {});

        (new RunAgentJob('job-2', 'anything', 3600, '2'))->handle($agent, $this->memory);

        self::assertSame('', LaravelIdentityResolver::actingUserId());
    }

    public function test_a_job_queued_from_the_console_carries_no_user(): void
    {
        $seen = null;
        $agent = new RecordingClawFake(static function () use (&$seen): void {
            $seen = LaravelIdentityResolver::actingUserId();
        });

        (new RunAgentJob('job-3', 'anything', 3600, ''))->handle($agent, $this->memory);

        self::assertSame('', $seen);
    }

    public function test_an_unknown_user_id_resolves_to_no_user_rather_than_being_fabricated(): void
    {
        $seen = null;
        $agent = new RecordingClawFake(static function () use (&$seen): void {
            $seen = LaravelIdentityResolver::actingUserId();
        });

        (new RunAgentJob('job-4', 'anything', 3600, '9999'))->handle($agent, $this->memory);

        self::assertSame('', $seen);
    }

    public function test_the_identity_is_dropped_even_when_the_run_fails(): void
    {
        $agent = new RecordingClawFake(static function (): void {
            throw new \RuntimeException('provider exploded');
        });

        try {
            (new RunAgentJob('job-5', 'anything', 3600, '2'))->handle($agent, $this->memory);
            self::fail('the job should have rethrown');
        } catch (\Throwable) {
            self::assertSame('', LaravelIdentityResolver::actingUserId());
        }
    }
}

class JobIdentityUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Feature\Durable;

use Illuminate\Auth\GenericUser;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PhpClaw\Claw;
use PhpClaw\Exceptions\RunSuspendedException;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Laravel\PhpClawServiceProvider;
use PhpClaw\Laravel\Testing\ClawFake;
use PhpClaw\Memory\Contracts\MemoryInterface;

final class DurableRunsCommandTest extends TestCase
{
    use RefreshDatabase;

    private ConflictingMemory $conflicting;

    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('phpclaw.memory_driver', 'eloquent');
        $app['config']->set('phpclaw.api_key', 'test-key');
        $app['config']->set('phpclaw.durable_runs', true);
        $app['config']->set('auth.providers.users.model', DurableUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();
        HookRegistry::reset();
        $this->artisan('migrate');
        Schema::create('users', static function (Blueprint $table): void {
            $table->increments('id');
        });
        DurableUser::query()->create(['id' => 7]);
        CountingTool::$runs = [];
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
        parent::tearDown();
    }

    private function engine(array $plan, int $stepBudget = 0, bool $pauseForApproval = true): Claw
    {
        $builder = Claw::builder()
            ->providerOverride(new ScriptedProvider($plan))
            ->memory($this->conflicting = new ConflictingMemory($this->app->make(MemoryInterface::class)))
            ->useDefaultGuards(false)
            ->tools([new CountingTool('refund'), new CountingTool('lookup')])
            ->durableRuns(stepBudget: $stepBudget);
        $claw = $pauseForApproval ? $builder->withSuspendableApproval()->build() : $builder->build();
        $this->app->instance(PhpClawServiceProvider::DURABLE_ENGINE, $claw);

        return $claw;
    }

    private function startAsUser(Claw $claw, int $userId, string $message): array
    {
        Auth::setUser(new GenericUser(['id' => $userId]));
        $conversation = $claw->conversation();
        try {
            $claw->sendInConversation($conversation, $message);
            $this->fail('expected the run to stop');
        } catch (RunSuspendedException $e) {
            return [$e->runId, $conversation->id];
        } finally {
            Auth::forgetUser();
        }
    }

    private function roles(string $conversationId): array
    {
        return DB::table('phpclaw_messages')->where('conversation_id', $conversationId)->orderBy('created_at')->orderBy('id')->pluck('role')->all();
    }

    public function test_list_shows_every_paused_run_with_its_owner(): void
    {
        [$runId] = $this->startAsUser($this->engine(ScriptedProvider::refundPlan()), 7, 'refund order 7');

        self::assertSame(0, Artisan::call('phpclaw:runs', ['action' => 'list']));
        $output = Artisan::output();

        self::assertMatchesRegularExpression('/\\|\\s*'.$runId.'\\s*\\|\\s*7\\s*\\|\\s*refund\\s*\\|\\s*c1\\s*\\|/', $output);
        self::assertStringContainsString('{"order":7}', $output);
    }

    public function test_approve_finishes_a_web_users_run_and_saves_the_turn_to_their_conversation(): void
    {
        [$runId, $conversationId] = $this->startAsUser($this->engine(ScriptedProvider::refundPlan()), 7, 'refund order 7');

        $this->artisan('phpclaw:runs', ['action' => 'approve', 'run' => $runId, 'call' => 'c1'])
            ->expectsOutputToContain('refund handled')
            ->assertSuccessful();

        self::assertSame(['refund' => 1], CountingTool::$runs);
        self::assertSame(['user', 'assistant'], $this->roles($conversationId));
        self::assertNull(Auth::user());
    }

    public function test_deny_finishes_the_run_without_running_the_tool(): void
    {
        [$runId] = $this->startAsUser($this->engine(ScriptedProvider::refundPlan()), 7, 'refund order 7');

        $this->artisan('phpclaw:runs', ['action' => 'deny', 'run' => $runId, 'call' => 'c1', '--reason' => 'no'])->assertSuccessful();

        self::assertSame([], CountingTool::$runs);
    }

    public function test_resume_continues_a_run_that_spent_its_budget(): void
    {
        [$runId, $conversationId] = $this->startAsUser($this->engine(ScriptedProvider::lookupPlan(2), stepBudget: 1, pauseForApproval: false), 7, 'look twice');
        $this->engine(ScriptedProvider::lookupPlan(2), pauseForApproval: false);

        $this->artisan('phpclaw:runs', ['action' => 'resume', 'run' => $runId])
            ->expectsOutputToContain('all steps done')
            ->assertSuccessful();

        self::assertSame(['user', 'assistant'], $this->roles($conversationId));
    }

    public function test_resume_due_finishes_due_runs_as_their_owners_and_prints_the_counts(): void
    {
        [, $conversationId] = $this->startAsUser($this->engine(ScriptedProvider::lookupPlan(2), stepBudget: 1, pauseForApproval: false), 7, 'look twice');
        $this->engine(ScriptedProvider::lookupPlan(2), pauseForApproval: false);

        $this->artisan('phpclaw:runs', ['action' => 'resume-due'])
            ->expectsOutput('completed=1 suspended=0 failed=0 skipped=0')
            ->assertSuccessful();

        self::assertSame(['user', 'assistant'], $this->roles($conversationId));
    }

    public function test_a_missing_run_id_or_an_unknown_action_fails(): void
    {
        $this->engine(ScriptedProvider::refundPlan());

        $this->artisan('phpclaw:runs', ['action' => 'approve'])->assertFailed();
        $this->artisan('phpclaw:runs', ['action' => 'explode'])->assertFailed();
        $this->artisan('phpclaw:runs', ['action' => 'approve', 'run' => '01JUNKNOWNRUN0000000000000', 'call' => 'c1'])->assertFailed();
    }

    public function test_the_scheduler_runs_resume_due_every_minute(): void
    {
        $events = array_values(array_filter(
            $this->app->make(Schedule::class)->events(),
            static fn ($event): bool => str_contains((string) $event->command, 'phpclaw:runs resume-due'),
        ));

        self::assertCount(1, $events);
        self::assertSame('* * * * *', $events[0]->expression);
    }

    public function test_a_run_started_from_the_console_is_resumed_with_no_user(): void
    {
        $claw = $this->engine(ScriptedProvider::lookupPlan(2), stepBudget: 1, pauseForApproval: false);
        $conversation = $claw->conversation();
        try {
            $claw->sendInConversation($conversation, 'look twice');
        } catch (RunSuspendedException) {
        }
        $this->engine(ScriptedProvider::lookupPlan(2), pauseForApproval: false);

        $this->artisan('phpclaw:runs', ['action' => 'resume-due'])->expectsOutput('completed=1 suspended=0 failed=0 skipped=0')->assertSuccessful();

        self::assertSame(['user', 'assistant'], $this->roles($conversation->id));
    }

    public function test_approving_a_run_that_pauses_again_says_so(): void
    {
        [$runId] = $this->startAsUser($this->engine(ScriptedProvider::twoRefundPlan()), 7, 'refund twice');

        $this->artisan('phpclaw:runs', ['action' => 'approve', 'run' => $runId, 'call' => 'c1'])
            ->expectsOutputToContain('stopped again: awaiting_approval')
            ->assertSuccessful();
    }

    public function test_a_run_changed_by_another_process_is_reported(): void
    {
        [$runId] = $this->startAsUser($this->engine(ScriptedProvider::refundPlan()), 7, 'refund order 7');
        $this->conflicting->armed = true;

        $this->artisan('phpclaw:runs', ['action' => 'approve', 'run' => $runId, 'call' => 'c1'])
            ->expectsOutputToContain('Another process changed this run first')
            ->assertFailed();
    }

    public function test_an_engine_that_is_not_a_claw_cannot_serve_runs(): void
    {
        $this->app->instance(PhpClawServiceProvider::DURABLE_ENGINE, new ClawFake);

        $this->artisan('phpclaw:runs', ['action' => 'list'])->assertFailed();
    }
}

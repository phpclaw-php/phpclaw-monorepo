<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Feature\Durable;

use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Orchestra\Testbench\TestCase;
use PhpClaw\Claw;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Laravel\LaravelIdentityResolver;
use PhpClaw\Laravel\PhpClawServiceProvider;
use PhpClaw\Laravel\Testing\ClawFake;
use PhpClaw\Memory\Contracts\MemoryInterface;

final class DurableRunsApiTest extends TestCase
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
        $app['config']->set('phpclaw.api.enabled', true);
        $app['config']->set('phpclaw.api.prefix', 'phpclaw');
        $app['config']->set('phpclaw.api.middleware', ['api']);
        $app['config']->set('phpclaw.durable_runs', true);
    }

    protected function setUp(): void
    {
        parent::setUp();
        HookRegistry::reset();
        $this->artisan('migrate');
        CountingTool::$runs = [];
        $this->useEngine(ScriptedProvider::refundPlan());
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
        parent::tearDown();
    }

    private function useEngine(array $plan, int $stepBudget = 0): void
    {
        $builder = Claw::builder()
            ->providerOverride(new ScriptedProvider($plan))
            ->memory($this->conflicting = new ConflictingMemory($this->app->make(MemoryInterface::class)))
            ->useDefaultGuards(false)
            ->tools([new CountingTool('refund'), new CountingTool('lookup')])
            ->durableRuns(stepBudget: $stepBudget);

        $this->app->instance(PhpClawInterface::class, $stepBudget > 0 ? $builder->build() : $builder->withSuspendableApproval()->build());
    }

    private function asUser(int $id): static
    {
        return $this->actingAs(new GenericUser(['id' => $id]));
    }

    private function messages(string $conversationId): array
    {
        return DB::table('phpclaw_messages')->where('conversation_id', $conversationId)->orderBy('created_at')->orderBy('id')->pluck('role')->all();
    }

    private function pause(int $userId = 7): array
    {
        $response = $this->asUser($userId)->postJson('/phpclaw/send', ['message' => 'refund order 7']);
        $response->assertStatus(202);

        return $response->json();
    }

    public function test_send_pauses_a_mutating_call_and_writes_nothing_to_the_conversation(): void
    {
        $paused = $this->pause();

        self::assertSame('awaiting_approval', $paused['status']);
        self::assertSame(['call_id' => 'c1', 'tool_name' => 'refund', 'tool_input' => ['order' => 7]], $paused['pending']);
        self::assertSame([], CountingTool::$runs);
        self::assertSame([], $this->messages($paused['conversation_id']));
    }

    public function test_the_owner_approves_and_the_run_finishes_in_the_same_request(): void
    {
        $paused = $this->pause();

        $response = $this->asUser(7)->postJson("/phpclaw/runs/{$paused['run_id']}/approve", ['call_id' => 'c1']);

        $response->assertOk()->assertJson(['status' => 'completed', 'text' => 'refund handled', 'conversation_id' => $paused['conversation_id']]);
        self::assertSame(['refund' => 1], CountingTool::$runs);
        self::assertSame(['user', 'assistant'], $this->messages($paused['conversation_id']));
    }

    public function test_the_owner_denies_and_the_tool_never_runs(): void
    {
        $paused = $this->pause();

        $response = $this->asUser(7)->postJson("/phpclaw/runs/{$paused['run_id']}/deny", ['call_id' => 'c1', 'reason' => 'not today']);

        $response->assertOk()->assertJson(['status' => 'completed']);
        self::assertSame([], CountingTool::$runs);
    }

    public function test_another_user_cannot_approve_and_the_run_stays_paused(): void
    {
        $paused = $this->pause();

        $this->asUser(8)->postJson("/phpclaw/runs/{$paused['run_id']}/approve", ['call_id' => 'c1'])->assertForbidden();

        self::assertSame([], CountingTool::$runs);
        $this->asUser(7)->getJson('/phpclaw/runs')->assertJsonCount(1, 'runs');
    }

    public function test_a_manage_all_user_can_approve_another_users_run(): void
    {
        $paused = $this->pause();
        Gate::define(LaravelIdentityResolver::MANAGE_ALL_ABILITY, static fn (): bool => true);

        $this->asUser(9)->postJson("/phpclaw/runs/{$paused['run_id']}/approve", ['call_id' => 'c1'])->assertOk();

        self::assertSame(['refund' => 1], CountingTool::$runs);
        self::assertSame(['user', 'assistant'], $this->messages($paused['conversation_id']));
    }

    public function test_the_pending_list_shows_only_the_users_own_runs(): void
    {
        $paused = $this->pause();

        $this->asUser(7)->getJson('/phpclaw/runs')->assertOk()->assertJsonPath('runs.0.run_id', $paused['run_id']);
        $this->asUser(8)->getJson('/phpclaw/runs')->assertOk()->assertJsonCount(0, 'runs');
    }

    public function test_a_wrong_call_id_or_an_unknown_run_is_refused(): void
    {
        $paused = $this->pause();

        $this->asUser(7)->postJson("/phpclaw/runs/{$paused['run_id']}/approve", ['call_id' => 'nope'])->assertStatus(409);
        $this->asUser(7)->postJson('/phpclaw/runs/01JUNKNOWNRUN0000000000000/approve', ['call_id' => 'c1'])
            ->assertNotFound()
            ->assertJson(['error' => 'No saved run with that id.']);
        self::assertSame([], CountingTool::$runs);
    }

    public function test_the_stream_reports_the_pause_as_an_event(): void
    {
        $response = $this->asUser(7)->post('/phpclaw/chat/stream', ['message' => 'refund order 7']);

        $body = $response->streamedContent();
        self::assertStringContainsString("event: approval_required\n", $body);
        self::assertStringContainsString('"tool_name":"refund"', $body);
        self::assertSame([], CountingTool::$runs);
    }

    public function test_a_run_that_spends_its_step_budget_reports_suspended(): void
    {
        $this->useEngine(ScriptedProvider::lookupPlan(2), stepBudget: 1);

        $response = $this->asUser(7)->postJson('/phpclaw/send', ['message' => 'look twice']);

        $response->assertStatus(202)->assertJson(['status' => 'suspended', 'pending' => null]);
    }

    public function test_approving_a_run_that_pauses_again_answers_with_the_next_paused_call(): void
    {
        $this->useEngine(ScriptedProvider::twoRefundPlan());
        $paused = $this->pause();

        $response = $this->asUser(7)->postJson("/phpclaw/runs/{$paused['run_id']}/approve", ['call_id' => 'c1']);

        $response->assertStatus(202)->assertJsonPath('pending.call_id', 'c2');
        self::assertSame(['refund' => 1], CountingTool::$runs);
    }

    public function test_a_run_saved_by_another_request_meanwhile_is_a_conflict(): void
    {
        $paused = $this->pause();
        $this->conflicting->armed = true;

        $this->asUser(7)->postJson("/phpclaw/runs/{$paused['run_id']}/approve", ['call_id' => 'c1'])->assertStatus(409);
    }

    public function test_a_failure_while_resuming_is_a_generic_error(): void
    {
        $this->useEngine([ScriptedProvider::refundPlan()[0], 'throw']);
        $paused = $this->pause();

        $response = $this->asUser(7)->postJson("/phpclaw/runs/{$paused['run_id']}/approve", ['call_id' => 'c1']);

        $response->assertStatus(500)->assertJson(['error' => 'An internal error occurred. Please try again.']);
    }

    public function test_an_engine_that_is_not_a_claw_cannot_serve_runs(): void
    {
        $this->app->instance(PhpClawInterface::class, new ClawFake);

        $this->asUser(7)->getJson('/phpclaw/runs')->assertStatus(501);
        $this->asUser(7)->postJson('/phpclaw/runs/01JUNKNOWNRUN0000000000000/approve', ['call_id' => 'c1'])->assertStatus(501);
    }

    public function test_a_pause_from_an_engine_that_is_not_a_claw_is_still_reported(): void
    {
        $this->app->instance(PhpClawInterface::class, new SuspendingEngine);

        $this->asUser(7)->postJson('/phpclaw/send', ['message' => 'go'])
            ->assertStatus(202)
            ->assertJson(['run_id' => '01JFAKERUN000000000000000000', 'status' => 'suspended', 'pending' => null]);
    }
}

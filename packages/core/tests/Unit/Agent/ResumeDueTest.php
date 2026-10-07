<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Agent;

use PhpClaw\Agent\RunState;
use PhpClaw\Agent\RunStatus;
use PhpClaw\Agent\RunStore;
use PhpClaw\Claw;
use PhpClaw\ClawBuilder;
use PhpClaw\Exceptions\RunStateException;
use PhpClaw\Exceptions\RunSuspendedException;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Tests\Unit\Agent\Durable\CountingMutatingTool;
use PhpClaw\Tests\Unit\Agent\Durable\CountingTool;
use PhpClaw\Tests\Unit\Agent\Durable\FailingListMemory;
use PhpClaw\Tests\Unit\Agent\Durable\ScriptedProvider;
use PHPUnit\Framework\TestCase;

final class ResumeDueTest extends TestCase
{
    protected function setUp(): void
    {
        HookRegistry::reset();
        GuardRegistry::reset();
        SkillRegistry::reset();
        CountingTool::$runs = [];
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
        GuardRegistry::reset();
        SkillRegistry::reset();
    }

    private function stepPlan(int $steps): array
    {
        $plan = [];
        for ($i = 1; $i <= $steps; $i++) {
            $plan[] = ScriptedProvider::batch(ScriptedProvider::call('s'.$i, 'lookup'));
        }
        $plan[] = ['type' => 'text', 'text' => 'all steps done'];

        return $plan;
    }

    private function approvalPlan(): array
    {
        return [
            ScriptedProvider::batch(ScriptedProvider::call('c1', 'refund', ['amount' => 400])),
            ['type' => 'text', 'text' => 'refund handled'],
        ];
    }

    private function builder(ScriptedProvider $provider, MemoryInterface $memory): ClawBuilder
    {
        return Claw::builder()
            ->providerOverride($provider)
            ->memory($memory)
            ->useDefaultGuards(false)
            ->tools([new CountingTool('lookup'), new CountingMutatingTool('refund')]);
    }

    private function suspendOnBudget(MemoryInterface $memory, string $message = 'go'): string
    {
        try {
            $this->builder(new ScriptedProvider($this->stepPlan(2)), $memory)->durableRuns(stepBudget: 1)->build()->send($message);
        } catch (RunSuspendedException $e) {
            return $e->runId;
        }
        $this->fail('expected the run to suspend');
    }

    private function pauseForApproval(MemoryInterface $memory): string
    {
        try {
            $this->builder(new ScriptedProvider($this->approvalPlan()), $memory)->withSuspendableApproval()->build()->send('refund order 7');
        } catch (RunSuspendedException $e) {
            return $e->runId;
        }
        $this->fail('expected the run to pause');
    }

    private function storeRow(MemoryInterface $memory, RunState $state, int $savedAt): void
    {
        $memory->set($state->runId(), [...$state->toArray(), 'saved_at' => $savedAt], RunStore::NAMESPACE);
    }

    private function stored(MemoryInterface $memory, string $runId): RunState
    {
        return RunState::fromArray($memory->get($runId, RunStore::NAMESPACE));
    }

    private function resumer(MemoryInterface $memory, array $plan): Claw
    {
        return $this->builder(new ScriptedProvider($plan), $memory)->withSuspendableApproval()->build();
    }

    public function test_save_stamps_the_row_with_its_save_time_and_the_row_still_loads(): void
    {
        $memory = new ArrayMemory;
        $before = time();

        $runId = $this->suspendOnBudget($memory);
        $row = $memory->get($runId, RunStore::NAMESPACE);

        $this->assertGreaterThanOrEqual($before, $row['saved_at']);
        $this->assertLessThanOrEqual(time(), $row['saved_at']);
        $this->assertSame(RunStatus::Suspended, $this->stored($memory, $runId)->status);
    }

    public function test_find_due_returns_a_suspended_run(): void
    {
        $memory = new ArrayMemory;
        $runId = $this->suspendOnBudget($memory);

        $due = (new RunStore($memory))->findDue(10);

        $this->assertSame([$runId], array_map(static fn (RunState $s): string => $s->runId(), $due));
    }

    public function test_find_due_returns_a_paused_run_only_once_a_decision_is_recorded(): void
    {
        $memory = new ArrayMemory;
        $runId = $this->pauseForApproval($memory);
        $store = new RunStore($memory);

        $this->assertSame([], $store->findDue(10));

        $this->resumer($memory, $this->approvalPlan())->approve($runId, 'c1');

        $this->assertSame([$runId], array_map(static fn (RunState $s): string => $s->runId(), $store->findDue(10)));
    }

    public function test_find_due_returns_a_running_run_only_when_it_has_not_been_saved_for_the_stale_window(): void
    {
        $memory = new ArrayMemory;
        $fresh = $this->stored($memory, $this->suspendOnBudget($memory, 'fresh'))->withStatus(RunStatus::Running);
        $stale = $this->stored($memory, $this->suspendOnBudget($memory, 'stale'))->withStatus(RunStatus::Running);
        $this->storeRow($memory, $fresh, time() - RunStore::STALE_RUNNING_SECONDS + 60);
        $this->storeRow($memory, $stale, time() - RunStore::STALE_RUNNING_SECONDS - 1);

        $due = (new RunStore($memory))->findDue(10);

        $this->assertSame([$stale->runId()], array_map(static fn (RunState $s): string => $s->runId(), $due));
    }

    public function test_find_due_skips_finished_and_malformed_rows_honours_the_limit_and_returns_the_oldest_first(): void
    {
        $memory = new ArrayMemory;
        $newer = $this->stored($memory, $this->suspendOnBudget($memory, 'newer'));
        $older = $this->stored($memory, $this->suspendOnBudget($memory, 'older'));
        $done = $this->stored($memory, $this->suspendOnBudget($memory, 'done'))->withStatus(RunStatus::Completed);
        $this->storeRow($memory, $newer, 2000);
        $this->storeRow($memory, $older, 1000);
        $this->storeRow($memory, $done, 500);
        $memory->set('broken', ['status' => 'suspended'], RunStore::NAMESPACE);
        $store = new RunStore($memory);

        $ids = static fn (array $states): array => array_map(static fn (RunState $s): string => $s->runId(), $states);

        $this->assertSame([$older->runId(), $newer->runId()], $ids($store->findDue(10)));
        $this->assertSame([$older->runId()], $ids($store->findDue(1)));
        $this->assertSame([], $store->findDue(0));
    }

    public function test_resume_due_finishes_a_run_suspended_on_its_step_budget(): void
    {
        $memory = new ArrayMemory;
        $runId = $this->suspendOnBudget($memory);

        $report = $this->resumer($memory, $this->stepPlan(2))->resumeDue();

        $this->assertSame([1, 0, 0, 0], [$report->completed, $report->suspended, $report->failed, $report->skipped]);
        $this->assertSame(1, $report->total());
        $this->assertSame(['lookup' => 2], CountingTool::$runs);
        $this->assertSame(RunStatus::Completed, $this->stored($memory, $runId)->status);
    }

    public function test_resume_due_runs_an_approved_call_and_never_runs_a_denied_one(): void
    {
        $approvedMemory = new ArrayMemory;
        $approved = $this->pauseForApproval($approvedMemory);
        $this->resumer($approvedMemory, $this->approvalPlan())->approve($approved, 'c1');

        $this->assertSame(1, $this->resumer($approvedMemory, $this->approvalPlan())->resumeDue()->completed);
        $this->assertSame(['refund' => 1], CountingTool::$runs);

        CountingTool::$runs = [];
        $deniedMemory = new ArrayMemory;
        $denied = $this->pauseForApproval($deniedMemory);
        $this->resumer($deniedMemory, $this->approvalPlan())->deny($denied, 'c1', 'not allowed');

        $this->assertSame(1, $this->resumer($deniedMemory, $this->approvalPlan())->resumeDue()->completed);
        $this->assertSame([], CountingTool::$runs);
        $this->assertSame(RunStatus::Completed, $this->stored($deniedMemory, $denied)->status);
    }

    public function test_resume_due_leaves_a_run_waiting_for_a_decision_alone(): void
    {
        $memory = new ArrayMemory;
        $runId = $this->pauseForApproval($memory);

        $report = $this->resumer($memory, $this->approvalPlan())->resumeDue();

        $this->assertSame(0, $report->total());
        $this->assertSame([], CountingTool::$runs);
        $this->assertSame(RunStatus::AwaitingApproval, $this->stored($memory, $runId)->status);
    }

    public function test_resume_due_counts_a_run_that_suspends_again_as_suspended(): void
    {
        $memory = new ArrayMemory;
        try {
            $this->builder(new ScriptedProvider($this->stepPlan(3)), $memory)->durableRuns(stepBudget: 1)->build()->send('go');
        } catch (RunSuspendedException) {
        }

        $report = $this->builder(new ScriptedProvider($this->stepPlan(3)), $memory)->durableRuns(stepBudget: 1)->build()->resumeDue();

        $this->assertSame([0, 1], [$report->completed, $report->suspended]);
    }

    public function test_resume_due_claims_a_stale_running_run_and_leaves_a_fresh_one_alone(): void
    {
        $memory = new ArrayMemory;
        $fresh = $this->stored($memory, $this->suspendOnBudget($memory, 'fresh'))->withStatus(RunStatus::Running);
        $stale = $this->stored($memory, $this->suspendOnBudget($memory, 'stale'))->withStatus(RunStatus::Running);
        $this->storeRow($memory, $fresh, time());
        $this->storeRow($memory, $stale, time() - RunStore::STALE_RUNNING_SECONDS - 1);
        $resumed = [];
        HookRegistry::on('run.resumed', function (array $c) use (&$resumed): void {
            $resumed[] = $c['run_id'];
        });

        $report = $this->resumer($memory, $this->stepPlan(2))->resumeDue();

        $this->assertSame(1, $report->completed);
        $this->assertSame([$stale->runId()], $resumed);
        $this->assertSame(RunStatus::Completed, $this->stored($memory, $stale->runId())->status);
        $this->assertSame(RunStatus::Running, $this->stored($memory, $fresh->runId())->status);
    }

    public function test_resume_due_skips_a_run_another_process_saved_first(): void
    {
        $memory = new ArrayMemory;
        $runId = $this->suspendOnBudget($memory);
        HookRegistry::on('run.resumed', function (array $c) use ($memory): void {
            $row = $memory->get((string) $c['run_id'], RunStore::NAMESPACE);
            $memory->set((string) $c['run_id'], ['version' => $row['version'] + 5] + $row, RunStore::NAMESPACE);
        });

        $report = $this->resumer($memory, $this->stepPlan(2))->resumeDue();

        $this->assertSame([0, 1], [$report->completed, $report->skipped]);
        $this->assertSame(RunStatus::Running, $this->stored($memory, $runId)->status);
    }

    public function test_resume_due_counts_a_failing_run_as_failed_and_carries_on(): void
    {
        $memory = new ArrayMemory;
        $failing = $this->stored($memory, $this->suspendOnBudget($memory, 'fails'));
        $healthy = $this->stored($memory, $this->suspendOnBudget($memory, 'works'));
        $this->storeRow($memory, $failing, 1000);
        $this->storeRow($memory, $healthy, 2000);
        $provider = new ScriptedProvider([], failOnSend: 1);

        $report = $this->builder($provider, $memory)->durableRuns()->build()->resumeDue();

        $this->assertSame([1, 1], [$report->completed, $report->failed]);
        $this->assertSame(RunStatus::Failed, $this->stored($memory, $failing->runId())->status);
        $this->assertSame(RunStatus::Completed, $this->stored($memory, $healthy->runId())->status);
    }

    public function test_resume_due_stops_at_the_limit(): void
    {
        $memory = new ArrayMemory;
        $this->suspendOnBudget($memory, 'one');
        $this->suspendOnBudget($memory, 'two');

        $report = $this->resumer($memory, $this->stepPlan(2))->resumeDue(limit: 1);

        $this->assertSame(1, $report->total());
        $this->assertCount(1, (new RunStore($memory))->findDue(10));
    }

    public function test_resume_due_starts_no_further_run_once_the_time_budget_is_spent(): void
    {
        $memory = new ArrayMemory;
        $this->suspendOnBudget($memory, 'one');
        $this->suspendOnBudget($memory, 'two');
        $claw = $this->builder(new ScriptedProvider($this->stepPlan(2), delayMicroseconds: 1_100_000), $memory)->durableRuns()->build();

        $report = $claw->resumeDue(timeBudgetSeconds: 1);

        $this->assertSame(1, $report->total());
        $this->assertCount(1, (new RunStore($memory))->findDue(10));
    }

    public function test_resume_due_runs_each_resume_inside_the_around_callback_with_its_run(): void
    {
        $memory = new ArrayMemory;
        $runId = $this->suspendOnBudget($memory);
        $seen = [];

        $report = $this->resumer($memory, $this->stepPlan(2))->resumeDue(around: function (RunState $state, \Closure $resume) use (&$seen): mixed {
            $seen[] = [$state->runId(), CountingTool::$runs];
            $result = $resume();
            $seen[] = CountingTool::$runs;

            return $result;
        });

        $this->assertSame(1, $report->completed);
        $this->assertSame([[$runId, ['lookup' => 1]], ['lookup' => 2]], $seen);
    }

    public function test_resume_due_returns_an_empty_report_when_the_store_fails(): void
    {
        $memory = new FailingListMemory;

        $report = $this->builder(new ScriptedProvider([]), $memory)->durableRuns()->build()->resumeDue();

        $this->assertSame(0, $report->total());
    }

    public function test_finishing_a_malformed_saved_run_leaves_it_as_it_is_without_throwing(): void
    {
        $memory = new ArrayMemory;
        $row = ['task' => ['run_id' => 'r1'], 'status' => 'no_such_status'];
        $memory->set('r1', $row, RunStore::NAMESPACE);

        (new RunStore($memory))->finish('r1', RunStatus::Completed);

        $this->assertSame($row, $memory->get('r1', RunStore::NAMESPACE));
    }

    public function test_resume_due_needs_a_memory_driver(): void
    {
        $claw = Claw::builder()->providerOverride(new ScriptedProvider([]))->useDefaultGuards(false)->build();

        $this->expectException(RunStateException::class);
        $claw->resumeDue();
    }
}

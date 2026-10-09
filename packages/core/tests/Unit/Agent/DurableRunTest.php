<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Agent;

use PhpClaw\Agent\Agent;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\Message;
use PhpClaw\Agent\RunState;
use PhpClaw\Agent\RunStatus;
use PhpClaw\Agent\RunStore;
use PhpClaw\Agent\SuspendableApprovalGate;
use PhpClaw\Claw;
use PhpClaw\ClawBuilder;
use PhpClaw\Exceptions\AdapterException;
use PhpClaw\Exceptions\RunConflictException;
use PhpClaw\Exceptions\RunStateException;
use PhpClaw\Exceptions\RunSuspendedException;
use PhpClaw\Exceptions\TokenBudgetExceededException;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Memory\FileMemory;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Tests\Unit\Agent\Durable\CountingMutatingTool;
use PhpClaw\Tests\Unit\Agent\Durable\CountingTool;
use PhpClaw\Tests\Unit\Agent\Durable\FailingTool;
use PhpClaw\Tests\Unit\Agent\Durable\RecordingMemory;
use PhpClaw\Tests\Unit\Agent\Durable\ScriptedProvider;
use PhpClaw\Tools\Concerns\FileReadLog;
use PhpClaw\Tools\FileEditTool;
use PhpClaw\Tools\FileReadTool;
use PhpClaw\Tools\ToolRegistry;
use PHPUnit\Framework\TestCase;

final class DurableRunTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        HookRegistry::reset();
        GuardRegistry::reset();
        SkillRegistry::reset();
        CountingTool::$runs = [];
        $this->dir = sys_get_temp_dir().'/phpclaw_durable_'.uniqid();
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
        GuardRegistry::reset();
        SkillRegistry::reset();
        array_map('unlink', glob($this->dir.'/*') ?: []);
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    private function approvalPlan(): array
    {
        return [
            ScriptedProvider::batch(ScriptedProvider::call('c1', 'refund', ['amount' => 400])),
            ['type' => 'text', 'text' => 'refund done'],
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

    private function suspend(Claw $claw, string $message = 'refund order 7'): RunSuspendedException
    {
        try {
            $claw->send($message);
        } catch (RunSuspendedException $e) {
            return $e;
        }
        $this->fail('expected the run to suspend');
    }

    private function suspendStream(Claw $claw): RunSuspendedException
    {
        try {
            $claw->stream('refund order 7', static function (string $chunk): void {});
        } catch (RunSuspendedException $e) {
            return $e;
        }
        $this->fail('expected the stream to suspend');
    }

    private function storedConversation(MemoryInterface $memory, string $id): Conversation
    {
        return Conversation::fromArray($memory->get($id, Conversation::MEMORY_NAMESPACE));
    }

    private function stored(MemoryInterface $memory, string $runId): RunState
    {
        return RunState::fromArray($memory->get($runId, RunStore::NAMESPACE));
    }

    public function test_it_suspends_on_pending_approval_and_saves_the_pending_call(): void
    {
        $memory = new FileMemory($this->dir);
        $errors = [];
        HookRegistry::on('agent.error', function (array $c) use (&$errors): void {
            $errors[] = $c;
        });

        $e = $this->suspend($this->builder(new ScriptedProvider($this->approvalPlan()), $memory)->withSuspendableApproval()->build());
        $state = $this->stored($memory, $e->runId);

        $this->assertSame(RunStatus::AwaitingApproval, $e->status);
        $this->assertSame(RunStatus::AwaitingApproval, $state->status);
        $this->assertSame('refund', $state->paused?->toolName());
        $this->assertSame('c1', $state->paused?->callId());
        $this->assertSame(['amount' => 400], $state->paused?->input());
        $this->assertNull($state->paused?->decision);
        $this->assertSame([], CountingTool::$runs);
        $this->assertSame([], $errors, 'a pause is not an error');
    }

    public function test_a_second_process_can_resume_an_approved_run(): void
    {
        $e = $this->suspend($this->builder(new ScriptedProvider($this->approvalPlan()), new FileMemory($this->dir))->withSuspendableApproval()->build());

        $before = [];
        HookRegistry::on('agent.before', function (array $c) use (&$before): void {
            $before[] = $c['run_id'] ?? '';
        });
        $second = $this->builder(new ScriptedProvider($this->approvalPlan()), new FileMemory($this->dir))->withSuspendableApproval()->build();
        $this->assertCount(1, $second->pendingApprovals());
        $second->approve($e->runId, 'c1');
        $response = $second->resume($e->runId);

        $this->assertSame('refund done', $response->text);
        $this->assertSame(['refund' => 1], CountingTool::$runs);
        $this->assertSame(['refund'], $response->toolsCalled);
        $this->assertSame([$e->runId], $before, 'resume runs under the persisted run id');
        $this->assertSame(RunStatus::Completed, $this->stored(new FileMemory($this->dir), $e->runId)->status);
        $this->assertSame([], $second->pendingApprovals());
    }

    public function test_a_denied_call_is_never_executed_and_the_model_sees_the_denial(): void
    {
        $memory = new ArrayMemory;
        $claw = $this->builder(new ScriptedProvider($this->approvalPlan()), $memory)->withSuspendableApproval()->build();
        $e = $this->suspend($claw);

        $after = [];
        HookRegistry::on('tool.after', function (array $c) use (&$after): void {
            $after[] = $c;
        });
        $decided = [];
        HookRegistry::on('run.approved', function (array $c) use (&$decided): void {
            $decided[] = $c;
        });
        $claw->deny($e->runId, 'c1', 'too large');
        $response = $claw->resume($e->runId);

        $this->assertSame([], CountingTool::$runs);
        $this->assertSame('refund', $after[0]['tool_name'] ?? null);
        $this->assertSame('denied', json_decode((string) ($after[0]['tool_result'] ?? ''), true)['status'] ?? null);
        $this->assertSame('refund done', $response->text);
        $this->assertSame('denied', $decided[0]['decision'] ?? null);
        $this->assertSame('too large', $decided[0]['reason'] ?? null);
    }

    public function test_an_undecided_call_is_refused_on_resume(): void
    {
        $memory = new ArrayMemory;
        $claw = $this->builder(new ScriptedProvider($this->approvalPlan()), $memory)->withSuspendableApproval()->build();
        $e = $this->suspend($claw);

        $this->expectException(RunStateException::class);
        $claw->resume($e->runId);
    }

    public function test_resuming_a_completed_or_unknown_run_throws(): void
    {
        $memory = new ArrayMemory;
        $claw = $this->builder(new ScriptedProvider($this->approvalPlan()), $memory)->withSuspendableApproval()->build();
        $e = $this->suspend($claw);
        $claw->approve($e->runId, 'c1');
        $claw->resume($e->runId);

        foreach ([$e->runId, 'NO_SUCH_RUN'] as $id) {
            try {
                $claw->resume($id);
                $this->fail('expected RunStateException for '.$id);
            } catch (RunStateException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_suspended_batch_resumes_without_re_running_completed_calls(): void
    {
        $plan = [
            ScriptedProvider::batch(ScriptedProvider::call('a1', 'lookup'), ScriptedProvider::call('a2', 'refund'), ScriptedProvider::call('a3', 'lookup')),
            ['type' => 'text', 'text' => 'all done'],
        ];
        $memory = new ArrayMemory;
        $claw = $this->builder(new ScriptedProvider($plan), $memory)->withSuspendableApproval()->build();
        $e = $this->suspend($claw);

        $this->assertSame(['lookup' => 1], CountingTool::$runs);
        $this->assertSame(1, $this->stored($memory, $e->runId)->paused?->index);

        $claw->approve($e->runId, 'a2');
        $response = $claw->resume($e->runId);

        $this->assertSame(['lookup' => 2, 'refund' => 1], CountingTool::$runs);
        $this->assertSame(['lookup', 'refund', 'lookup'], $response->toolsCalled);
    }

    public function test_approve_and_deny_refuse_a_wrong_call_id(): void
    {
        $claw = $this->builder(new ScriptedProvider($this->approvalPlan()), new ArrayMemory)->withSuspendableApproval()->build();
        $e = $this->suspend($claw);

        $this->expectException(RunStateException::class);
        $claw->approve($e->runId, 'not-the-call');
    }

    public function test_a_stale_version_is_rejected(): void
    {
        $memory = new ArrayMemory;
        $claw = $this->builder(new ScriptedProvider($this->approvalPlan()), $memory)->withSuspendableApproval()->build();
        $e = $this->suspend($claw);
        $row = $memory->get($e->runId, RunStore::NAMESPACE);
        $row['version']++;
        $memory->set($e->runId, $row, RunStore::NAMESPACE);

        $stale = RunState::fromArray($row);
        $this->assertSame($row['version'], $stale->version);

        $this->expectException(RunConflictException::class);
        (new RunStore($memory))->save(RunState::fromArray(['version' => $row['version'] - 1] + $row));
    }

    public function test_cancelling_a_suspended_run_marks_it_cancelled_and_it_is_never_resumed(): void
    {
        $memory = new ArrayMemory;
        $claw = $this->builder(new ScriptedProvider($this->approvalPlan()), $memory)->withSuspendableApproval()->build();
        $e = $this->suspend($claw);
        $events = [];
        HookRegistry::on('run.cancelled', function (array $c) use (&$events): void {
            $events[] = $c;
        });

        $claw->cancel($e->runId, 'customer withdrew');

        $this->assertSame(RunStatus::Cancelled, $this->stored($memory, $e->runId)->status);
        $this->assertSame($e->runId, $events[0]['run_id'] ?? null);
        $this->assertSame('customer withdrew', $events[0]['reason'] ?? null);
        $this->expectException(RunStateException::class);
        $claw->resume($e->runId);
    }

    public function test_cancelling_a_completed_run_is_a_no_op(): void
    {
        $memory = new ArrayMemory;
        $claw = $this->builder(new ScriptedProvider($this->approvalPlan()), $memory)->withSuspendableApproval()->build();
        $e = $this->suspend($claw);
        $claw->approve($e->runId, 'c1');
        $claw->resume($e->runId);

        $claw->cancel($e->runId);

        $this->assertSame(RunStatus::Completed, $this->stored($memory, $e->runId)->status);
    }

    public function test_cancel_takes_effect_at_the_next_checkpoint_not_mid_tool(): void
    {
        $memory = new ArrayMemory;
        $plan = [ScriptedProvider::batch(ScriptedProvider::call('l1', 'lookup')), ScriptedProvider::batch(ScriptedProvider::call('l2', 'lookup')), ['type' => 'text', 'text' => 'end']];
        $claw = $this->builder(new ScriptedProvider($plan), $memory)->durableRuns()->build();
        HookRegistry::on('tool.after', function (array $c) use ($memory): void {
            $runId = (string) ($c['run_id'] ?? '');
            $row = $memory->get($runId, RunStore::NAMESPACE);
            if (is_array($row) && ($c['tool_name'] ?? '') === 'lookup') {
                $row['status'] = 'cancelled';
                $memory->set($runId, $row, RunStore::NAMESPACE);
            }
        });
        HookRegistry::on('agent.before', function (array $c) use ($memory): void {
            $memory->set((string) $c['run_id'], RunState::start((string) $c['run_id'], 'x', 'x', [])->toArray(), RunStore::NAMESPACE);
        });

        try {
            $claw->send('look up twice');
            $this->fail('expected the cancelled run to stop');
        } catch (RunSuspendedException $e) {
            $this->assertSame(RunStatus::Cancelled, $e->status);
        }
        $this->assertSame(['lookup' => 1], CountingTool::$runs, 'the in-flight tool completed, the next never started');
    }

    public function test_step_budget_suspends_and_a_second_process_completes_the_run(): void
    {
        $plan = [];
        for ($i = 1; $i <= 4; $i++) {
            $plan[] = ScriptedProvider::batch(ScriptedProvider::call('s'.$i, 'lookup'));
        }
        $plan[] = ['type' => 'text', 'text' => 'five steps done'];

        $reference = $this->builder(new ScriptedProvider($plan), new ArrayMemory)->build()->send('go');
        CountingTool::$runs = [];

        $e = $this->suspend($this->builder(new ScriptedProvider($plan), new FileMemory($this->dir))->durableRuns(stepBudget: 2)->build(), 'go');
        $this->assertSame(RunStatus::Suspended, $e->status);
        $this->assertSame(2, $this->stored(new FileMemory($this->dir), $e->runId)->progress->iteration);
        $this->assertSame(['lookup' => 2], CountingTool::$runs);

        $resumed = $this->builder(new ScriptedProvider($plan), new FileMemory($this->dir))->durableRuns()->build()->resume($e->runId);

        $this->assertSame('five steps done', $resumed->text);
        $this->assertSame($reference->toolsCalled, $resumed->toolsCalled);
        $this->assertSame(['lookup' => 4], CountingTool::$runs);
        $this->assertSame(5, $resumed->iterations);
    }

    public function test_iteration_and_token_counters_carry_across_resume(): void
    {
        $plan = [ScriptedProvider::batch(ScriptedProvider::call('t1', 'lookup')), ScriptedProvider::batch(ScriptedProvider::call('t2', 'lookup')), ['type' => 'text', 'text' => 'x']];
        $memory = new ArrayMemory;
        $e = $this->suspend($this->builder(new ScriptedProvider($plan, tokensPerCall: 1000), $memory)->durableRuns(stepBudget: 1)->maxTokenBudget(1500)->build(), 'go');
        $this->assertSame(1000, $this->stored($memory, $e->runId)->progress->tokensSpent);

        $this->expectException(TokenBudgetExceededException::class);
        $this->builder(new ScriptedProvider($plan, tokensPerCall: 1000), $memory)->durableRuns()->maxTokenBudget(1500)->build()->resume($e->runId);
    }

    public function test_a_checkpoint_is_written_after_every_iteration_with_the_tool_result_recorded(): void
    {
        $plan = [ScriptedProvider::batch(ScriptedProvider::call('k1', 'lookup')), ScriptedProvider::batch(ScriptedProvider::call('k2', 'lookup')), ['type' => 'text', 'text' => 'end']];
        $memory = new RecordingMemory;

        $this->builder(new ScriptedProvider($plan), $memory)->durableRuns()->build()->send('go');

        $running = array_values(array_filter($memory->saves, static fn (array $s): bool => $s['value']['status'] === 'running'));
        $this->assertCount(2, $running, 'one checkpoint per tool iteration');
        foreach ($running as $save) {
            $last = end($save['value']['progress']['messages']);
            $this->assertSame('tool_batch', $last['role'], 'the checkpoint is written after the tool result');
            $this->assertNotEmpty($last['batch_results']);
            $this->assertSame(604800, $save['ttl']);
        }
        $this->assertSame('completed', end($memory->saves)['value']['status']);
    }

    public function test_durable_runs_off_means_no_checkpoint_and_no_behaviour_change(): void
    {
        $memory = new ArrayMemory;
        $plan = [ScriptedProvider::batch(ScriptedProvider::call('o1', 'refund')), ['type' => 'text', 'text' => 'ok']];

        $response = $this->builder(new ScriptedProvider($plan), $memory)->build()->send('go');

        $this->assertSame('ok', $response->text);
        $this->assertSame([], $memory->all(RunStore::NAMESPACE));
        $this->assertSame(['refund' => 1], CountingTool::$runs);
    }

    public function test_hallucination_retry_is_not_refunded_by_a_suspend(): void
    {
        $plan = [ScriptedProvider::batch(ScriptedProvider::call('h1', 'no_such_tool')), ScriptedProvider::batch(ScriptedProvider::call('h2', 'lookup'))];
        $memory = new ArrayMemory;
        $claw = $this->builder(new ScriptedProvider($plan), $memory)->durableRuns(stepBudget: 1)->build();

        $e = $this->suspend($claw, 'go');
        $state = $this->stored($memory, $e->runId);
        $this->assertTrue($state->progress->isHallucinationRetry);

        $provider = new ScriptedProvider([ScriptedProvider::batch(ScriptedProvider::call('h3', 'no_such_tool'))]);
        $this->expectException(ToolException::class);
        $this->builder($provider, $memory)->durableRuns()->build()->resume($e->runId);
    }

    public function test_a_file_read_before_suspension_satisfies_the_edit_gate_after_resume(): void
    {
        $ws = sys_get_temp_dir().'/phpclaw_ws_'.uniqid();
        mkdir($ws);
        file_put_contents($ws.'/a.txt', "alpha\n");
        $tools = static fn (): array => [new FileReadTool($ws), new FileEditTool($ws)];
        $plan = [
            ScriptedProvider::batch(ScriptedProvider::call('r1', 'file_read', ['path' => 'a.txt'])),
            ScriptedProvider::batch(ScriptedProvider::call('e1', 'file_edit', ['path' => 'a.txt', 'old_str' => 'alpha', 'new_str' => 'beta'])),
            ['type' => 'text', 'text' => 'edited'],
        ];
        $memory = new ArrayMemory;
        $first = Claw::builder()->providerOverride(new ScriptedProvider($plan))->memory($memory)->useDefaultGuards(false)->tools($tools())->durableRuns(stepBudget: 1)->build();
        $e = $this->suspend($first, 'edit a.txt');
        FileReadLog::reset();

        $second = Claw::builder()->providerOverride(new ScriptedProvider($plan))->memory($memory)->useDefaultGuards(false)->tools($tools())->durableRuns()->build();
        $second->resume($e->runId);

        $this->assertSame("beta\n", file_get_contents($ws.'/a.txt'));
        unlink($ws.'/a.txt');
        rmdir($ws);
    }

    public function test_it_throws_at_build_without_a_memory_driver_or_with_store_messages_off(): void
    {
        foreach ([
            fn (): ClawBuilder => Claw::builder()->providerOverride(new ScriptedProvider([]))->withSuspendableApproval(),
            fn (): ClawBuilder => Claw::builder()->providerOverride(new ScriptedProvider([]))->memory(new ArrayMemory)->storeMessages(false)->durableRuns(),
        ] as $i => $make) {
            try {
                $make()->build();
                $this->fail('expected AdapterException, case '.$i);
            } catch (AdapterException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_an_agent_run_without_a_checkpoint_cannot_pause_so_the_call_is_denied_not_run(): void
    {
        $registry = new ToolRegistry;
        $registry->register([new CountingMutatingTool('refund')]);
        $agent = new Agent(provider: new ScriptedProvider($this->approvalPlan()), tools: $registry, approvalGate: new SuspendableApprovalGate);

        $response = $agent->run('refund order 7');

        $this->assertSame('refund done', $response->text);
        $this->assertSame([], CountingTool::$runs);
    }

    public function test_a_conversation_run_pauses_writes_nothing_and_its_resume_appends_the_turn(): void
    {
        $memory = new ArrayMemory;
        $claw = $this->builder(new ScriptedProvider($this->approvalPlan()), $memory)->withSuspendableApproval()->build();
        $conversation = $claw->conversation();
        $events = [];
        foreach (['agent.after', 'conversation.end'] as $name) {
            HookRegistry::on($name, function (array $c) use (&$events, $name): void {
                $events[] = [$name, $c['conversation_id'] ?? null];
            });
        }

        try {
            $claw->sendInConversation($conversation, 'refund order 7');
            $this->fail('expected the conversation run to pause');
        } catch (RunSuspendedException $e) {
            $runId = $e->runId;
        }

        $this->assertSame([], $this->storedConversation($memory, $conversation->id)->history);
        $this->assertSame($conversation->id, $this->stored($memory, $runId)->task->conversationId);
        $this->assertSame([], $events);

        $claw->approve($runId, 'c1');
        $response = $claw->resume($runId);

        $this->assertSame('refund done', $response->text);
        $this->assertSame(['refund' => 1], CountingTool::$runs);
        $this->assertSame(
            [['user', 'refund order 7'], ['assistant', 'refund done']],
            array_map(static fn (Message $m): array => [$m->role, $m->content], $this->storedConversation($memory, $conversation->id)->history),
        );
        $this->assertSame([['agent.after', $conversation->id], ['conversation.end', $conversation->id]], $events);
    }

    public function test_a_streamed_conversation_run_pauses_and_its_resume_appends_the_turn(): void
    {
        $memory = new ArrayMemory;
        $claw = $this->builder(new ScriptedProvider($this->approvalPlan()), $memory)->withSuspendableApproval()->build();
        $conversation = $claw->conversation();
        $starts = 0;
        HookRegistry::on('stream.start', function () use (&$starts): void {
            $starts++;
        });

        try {
            $claw->streamInConversation($conversation, 'refund order 7', static function (string $chunk): void {});
            $this->fail('expected the streamed conversation run to pause');
        } catch (RunSuspendedException $e) {
            $runId = $e->runId;
        }

        $this->assertSame(1, $starts);
        $this->assertSame([], CountingTool::$runs);
        $this->assertSame([], $this->storedConversation($memory, $conversation->id)->history);

        $claw->approve($runId, 'c1');
        $claw->resume($runId);

        $this->assertSame(['refund' => 1], CountingTool::$runs);
        $this->assertCount(2, $this->storedConversation($memory, $conversation->id)->history);
    }

    public function test_a_plain_stream_pauses_when_durable_runs_are_on(): void
    {
        $claw = $this->builder(new ScriptedProvider($this->approvalPlan()), new ArrayMemory)->withSuspendableApproval()->build();

        $e = $this->suspendStream($claw);

        $this->assertSame(RunStatus::AwaitingApproval, $e->status);
        $this->assertSame([], CountingTool::$runs);
    }

    public function test_a_conversation_run_suspended_on_its_budget_is_finished_by_resume_due_and_appended(): void
    {
        $memory = new ArrayMemory;
        $plan = [ScriptedProvider::batch(ScriptedProvider::call('s1', 'lookup')), ScriptedProvider::batch(ScriptedProvider::call('s2', 'lookup')), ['type' => 'text', 'text' => 'two steps done']];
        $claw = $this->builder(new ScriptedProvider($plan), $memory)->durableRuns(stepBudget: 1)->build();
        $conversation = $claw->conversation();

        try {
            $claw->sendInConversation($conversation, 'look twice');
            $this->fail('expected the conversation run to suspend');
        } catch (RunSuspendedException) {
        }

        $report = $this->builder(new ScriptedProvider($plan), $memory)->durableRuns()->build()->resumeDue();

        $this->assertSame(1, $report->completed);
        $this->assertSame(
            [['user', 'look twice'], ['assistant', 'two steps done']],
            array_map(static fn (Message $m): array => [$m->role, $m->content], $this->storedConversation($memory, $conversation->id)->history),
        );
    }

    public function test_a_conversation_deleted_while_its_run_was_paused_is_not_recreated(): void
    {
        $memory = new ArrayMemory;
        $claw = $this->builder(new ScriptedProvider($this->approvalPlan()), $memory)->withSuspendableApproval()->build();
        $conversation = $claw->conversation();
        try {
            $claw->sendInConversation($conversation, 'refund order 7');
            $this->fail('expected the conversation run to pause');
        } catch (RunSuspendedException $e) {
            $runId = $e->runId;
        }
        $memory->forget($conversation->id, Conversation::MEMORY_NAMESPACE);

        $claw->approve($runId, 'c1');

        $this->assertSame('refund done', $claw->resume($runId)->text);
        $this->assertNull($memory->get($conversation->id, Conversation::MEMORY_NAMESPACE));
    }

    public function test_resume_claims_the_run_before_running_the_approved_call_so_a_second_resumer_is_refused(): void
    {
        $memory = new ArrayMemory;
        $claw = $this->builder(new ScriptedProvider($this->approvalPlan()), $memory)->withSuspendableApproval()->build();
        $e = $this->suspend($claw);
        $claw->approve($e->runId, 'c1');
        $secondResumer = null;
        $statusDuringTool = null;
        HookRegistry::on('tool.after', function () use ($claw, $memory, $e, &$secondResumer, &$statusDuringTool): void {
            $statusDuringTool = $this->stored($memory, $e->runId)->status;
            try {
                $claw->resume($e->runId);
            } catch (\Throwable $refused) {
                $secondResumer = $refused;
            }
        });

        $this->assertSame('refund done', $claw->resume($e->runId)->text);

        $this->assertSame(RunStatus::Running, $statusDuringTool);
        $this->assertInstanceOf(RunStateException::class, $secondResumer);
        $this->assertSame(['refund' => 1], CountingTool::$runs);
        $this->assertSame(RunStatus::Completed, $this->stored($memory, $e->runId)->status);
    }

    public function test_run_events_fire_with_ids_and_no_tool_input(): void
    {
        $events = [];
        foreach (['run.suspended', 'run.resumed', 'run.approved'] as $name) {
            HookRegistry::on($name, function (array $c) use (&$events, $name): void {
                $events[$name] = $c;
            });
        }
        $claw = $this->builder(new ScriptedProvider($this->approvalPlan()), new ArrayMemory)->withSuspendableApproval()->build();
        $e = $this->suspend($claw);
        $claw->approve($e->runId, 'c1');
        $claw->resume($e->runId);

        $this->assertSame($e->runId, $events['run.suspended']['run_id'] ?? null);
        $this->assertSame('awaiting_approval', $events['run.suspended']['status'] ?? null);
        $this->assertSame('refund', $events['run.suspended']['tool_name'] ?? null);
        $this->assertSame('approved', $events['run.approved']['decision'] ?? null);
        $this->assertSame($e->runId, $events['run.resumed']['run_id'] ?? null);
        $this->assertStringNotContainsString('"amount"', (string) json_encode($events));
    }

    public function test_a_later_call_in_the_resumed_batch_pauses_the_run_again(): void
    {
        $plan = [ScriptedProvider::batch(ScriptedProvider::call('p1', 'refund', ['n' => 1]), ScriptedProvider::call('p2', 'refund', ['n' => 2])), ['type' => 'text', 'text' => 'both refunded']];
        $memory = new ArrayMemory;
        $claw = $this->builder(new ScriptedProvider($plan), $memory)->withSuspendableApproval()->build();
        $e = $this->suspend($claw);
        $claw->approve($e->runId, 'p1');

        try {
            $claw->resume($e->runId);
            $this->fail('expected the second call to pause the run again');
        } catch (RunSuspendedException $again) {
            $this->assertSame(RunStatus::AwaitingApproval, $again->status);
        }
        $state = $this->stored($memory, $e->runId);
        $this->assertSame('p2', $state->paused?->callId());
        $this->assertSame(1, $state->paused?->index);
        $this->assertSame(['refund' => 1], CountingTool::$runs);

        $claw->approve($e->runId, 'p2');
        $this->assertSame('both refunded', $claw->resume($e->runId)->text);
        $this->assertSame(['refund' => 2], CountingTool::$runs);
    }

    public function test_a_repeated_failure_in_the_resumed_batch_ends_the_run_with_the_refusal(): void
    {
        $plan = [
            ScriptedProvider::batch(ScriptedProvider::call('f1', 'flaky')),
            ScriptedProvider::batch(ScriptedProvider::call('r1', 'refund'), ScriptedProvider::call('f2', 'flaky')),
            ['type' => 'text', 'text' => 'never reached'],
        ];
        $claw = Claw::builder()->providerOverride(new ScriptedProvider($plan))->memory(new ArrayMemory)->useDefaultGuards(false)
            ->tools([new FailingTool, new CountingMutatingTool('refund')])->withSuspendableApproval()->build();
        $e = $this->suspend($claw, 'go');
        $claw->approve($e->runId, 'r1');

        $response = $claw->resume($e->runId);

        $this->assertSame('service unavailable', $response->text);
        $this->assertSame(['flaky' => 2, 'refund' => 1], CountingTool::$runs);
    }

    public function test_pending_approvals_respects_the_limit_and_skips_malformed_rows(): void
    {
        $memory = new ArrayMemory;
        $memory->set('broken', ['status' => 'awaiting_approval'], RunStore::NAMESPACE);
        $claw = $this->builder(new ScriptedProvider($this->approvalPlan()), $memory)->withSuspendableApproval()->build();
        $this->suspend($claw);
        $this->suspend($claw);

        $this->assertCount(2, $claw->pendingApprovals());
        $this->assertCount(1, $claw->pendingApprovals(1));
    }

    public function test_a_durable_run_that_answers_at_once_saves_nothing(): void
    {
        $memory = new ArrayMemory;

        $response = $this->builder(new ScriptedProvider([['type' => 'text', 'text' => 'hi']]), $memory)->durableRuns()->build()->send('hello');

        $this->assertSame('hi', $response->text);
        $this->assertSame([], $memory->all(RunStore::NAMESPACE));
    }

    public function test_a_run_saved_by_another_process_meanwhile_fails_with_a_conflict(): void
    {
        $memory = new ArrayMemory;
        $plan = [ScriptedProvider::batch(ScriptedProvider::call('x1', 'lookup')), ScriptedProvider::batch(ScriptedProvider::call('x2', 'lookup')), ScriptedProvider::batch(ScriptedProvider::call('x3', 'lookup')), ['type' => 'text', 'text' => 'end']];
        $claw = $this->builder(new ScriptedProvider($plan), $memory)->durableRuns()->build();
        $calls = 0;
        HookRegistry::on('tool.after', function (array $c) use ($memory, &$calls): void {
            if (++$calls === 2) {
                $memory->set((string) $c['run_id'], ['version' => 99], RunStore::NAMESPACE);
            }
        });

        $this->expectException(RunConflictException::class);
        $claw->send('go');
    }

    public function test_run_methods_need_a_memory_driver(): void
    {
        $claw = Claw::builder()->providerOverride(new ScriptedProvider([]))->useDefaultGuards(false)->build();

        $this->expectException(RunStateException::class);
        $claw->pendingApprovals();
    }

    public function test_a_configured_deadline_suspends_the_run(): void
    {
        $slow = new ScriptedProvider([ScriptedProvider::batch(ScriptedProvider::call('d1', 'lookup')), ['type' => 'text', 'text' => 'late']], delayMicroseconds: 1_100_000);
        $memory = new ArrayMemory;
        $claw = Claw::builder()->providerOverride($slow)->memory($memory)->useDefaultGuards(false)->tools([new CountingTool('lookup')])->durableRuns(deadlineSeconds: 1)->build();

        $e = $this->suspend($claw, 'go');

        $this->assertSame(RunStatus::Suspended, $e->status);
        $this->assertSame(1, $this->stored($memory, $e->runId)->progress->iteration);
    }
}

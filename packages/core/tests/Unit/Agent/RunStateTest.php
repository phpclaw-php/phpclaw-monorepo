<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Agent;

use PhpClaw\Agent\Message;
use PhpClaw\Agent\PausedBatch;
use PhpClaw\Agent\RunProgress;
use PhpClaw\Agent\RunState;
use PhpClaw\Agent\RunStatus;
use PhpClaw\Agent\RunTask;
use PhpClaw\Agent\RunToolLog;
use PhpClaw\Exceptions\PhpClawException;
use PhpClaw\Exceptions\RunStateException;
use PHPUnit\Framework\TestCase;

final class RunStateTest extends TestCase
{
    public function test_to_array_from_array_is_lossless(): void
    {
        $calls = [['tool_use_id' => 't1', 'tool_name' => 'a', 'tool_input' => []], ['tool_use_id' => 't2', 'tool_name' => 'b', 'tool_input' => ['y' => 2]]];
        $state = new RunState(
            task: new RunTask('01RUN', 'augmented message', 'original message', '01PARENT', '01CONVERSATION'),
            status: RunStatus::AwaitingApproval,
            progress: new RunProgress(
                [Message::user('hi'), Message::toolBatch([['tool_use_id' => 't0', 'tool_name' => 'a', 'tool_input' => ['x' => 1]]], ['t0' => 'ok'])],
                3,
                42,
                isHallucinationRetry: true,
                tools: new RunToolLog(['a', 'b'], ['a|[]' => true], ['/ws' => ['/ws/a.php']]),
            ),
            paused: new PausedBatch($calls, ['t1' => 'ok'], 1, new \DateTimeImmutable('2026-10-05T10:00:00+00:00'), PausedBatch::APPROVED),
            version: 4,
        );

        $copy = RunState::fromArray(json_decode((string) json_encode($state->toArray()), true));

        $this->assertEquals($state, $copy);
        $this->assertSame($state->toArray(), $copy->toArray());
        $this->assertSame('t2', $copy->paused?->callId());
        $this->assertSame('b', $copy->paused?->toolName());
        $this->assertSame(['y' => 2], $copy->paused?->input());
        $this->assertSame('01CONVERSATION', $copy->task->conversationId);
    }

    public function test_a_new_run_starts_running_at_step_zero_with_the_message_last(): void
    {
        $state = RunState::start('01RUN', 'msg', 'orig', [Message::assistant('earlier')]);

        $this->assertSame(RunStatus::Running, $state->status);
        $this->assertSame('01RUN', $state->runId());
        $this->assertSame(0, $state->version);
        $this->assertSame(0, $state->progress->iteration);
        $this->assertNull($state->paused);
        $this->assertNull($state->task->parentRunId);
        $this->assertSame('orig', $state->task->routingMessage);
        $messages = $state->progress->messages;
        $this->assertSame(['earlier', 'msg'], array_map(static fn (Message $m): string => $m->content, $messages));
    }

    public function test_a_malformed_row_throws_a_domain_exception(): void
    {
        foreach ([[], ['task' => ['run_id' => 'x'], 'status' => 'nope'], ['task' => ['run_id' => 'x'], 'status' => 'running', 'progress' => ['messages' => [['content' => 'no role']]]]] as $row) {
            try {
                RunState::fromArray($row);
                $this->fail('expected RunStateException for '.json_encode($row));
            } catch (RunStateException $e) {
                $this->assertInstanceOf(PhpClawException::class, $e);
            }
        }
    }

    public function test_only_suspended_and_awaiting_approval_are_resumable(): void
    {
        $this->assertSame(
            ['awaiting_approval', 'suspended'],
            array_values(array_map(static fn (RunStatus $s): string => $s->value, array_filter(RunStatus::cases(), static fn (RunStatus $s): bool => $s->isResumable()))),
        );
        $this->assertSame(
            ['cancelled', 'completed', 'failed'],
            array_values(array_map(static fn (RunStatus $s): string => $s->value, array_filter(RunStatus::cases(), static fn (RunStatus $s): bool => $s->isTerminal()))),
        );
    }

    public function test_a_run_awaits_a_decision_only_while_paused_and_undecided(): void
    {
        $state = RunState::start('r1', 'refund', 'refund');
        $paused = PausedBatch::now([['tool_use_id' => 'c1', 'tool_name' => 'refund', 'tool_input' => []]], [], 0);

        $this->assertTrue($state->withStatus(RunStatus::AwaitingApproval, $paused)->isAwaitingDecision());
        $this->assertFalse($state->withStatus(RunStatus::AwaitingApproval, $paused->withDecision(PausedBatch::APPROVED))->isAwaitingDecision());
        $this->assertFalse($state->withStatus(RunStatus::Suspended, $paused)->isAwaitingDecision());
        $this->assertFalse($state->isAwaitingDecision());
    }
}

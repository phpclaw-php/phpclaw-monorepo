<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit\Durable;

use PhpClaw\Agent\RunStatus;
use PhpClaw\Agent\RunStore;
use PhpClaw\Claw;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Exceptions\RunSuspendedException;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Symfony\Command\RunsCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class DurableRunsCommandTest extends TestCase
{
    use DurableFixture;

    protected function setUp(): void
    {
        $this->bootDurableFixture();
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
    }

    private function pausedRunOf(string $userId, Claw $claw): array
    {
        $this->actAsUser($userId);

        try {
            $claw->sendInConversation($claw->conversation(), 'refund order 7');
        } catch (RunSuspendedException $e) {
            $state = (new RunStore($claw->memory()))->load($e->runId);
            $this->actAsUser('');

            return [$e->runId, $state->task->conversationId];
        }

        self::fail('The run did not pause.');
    }

    private function runCommand(ClawInterface $claw, array $input): CommandTester
    {
        $tester = new CommandTester(new RunsCommand($claw, $this->approvals()));
        $tester->execute($input);

        return $tester;
    }

    public function test_list_shows_each_paused_run_with_its_owner_and_call(): void
    {
        $claw = $this->durableClaw(ScriptedProvider::refundPlan());
        [$runId] = $this->pausedRunOf('alice', $claw);

        $tester = $this->runCommand($claw, ['action' => 'list']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString($runId, $tester->getDisplay());
        self::assertStringContainsString('alice', $tester->getDisplay());
        self::assertStringContainsString('refund', $tester->getDisplay());
    }

    public function test_approve_runs_as_the_owner_so_the_answer_lands_in_their_conversation(): void
    {
        $claw = $this->durableClaw(ScriptedProvider::refundPlan());
        [$runId, $conversationId] = $this->pausedRunOf('alice', $claw);

        $tester = $this->runCommand($claw, ['action' => 'approve', 'run' => $runId, 'call' => 'c1']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('refund handled', $tester->getDisplay());
        self::assertSame(['refund' => 1], CountingTool::$runs);
        self::assertSame(['user', 'assistant'], $this->messageRoles($conversationId));
        self::assertFalse($this->identity->isImpersonating());
    }

    public function test_deny_records_the_reason_and_never_runs_the_tool(): void
    {
        $claw = $this->durableClaw(ScriptedProvider::refundPlan());
        [$runId] = $this->pausedRunOf('alice', $claw);

        $tester = $this->runCommand($claw, ['action' => 'deny', 'run' => $runId, 'call' => 'c1', '--reason' => 'no']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame([], CountingTool::$runs);
    }

    public function test_resume_finishes_a_run_suspended_on_its_step_budget(): void
    {
        $claw = $this->durableClaw(ScriptedProvider::lookupPlan(2), stepBudget: 1);
        [$runId] = $this->pausedRunOf('alice', $claw);

        $tester = $this->runCommand($this->durableClaw(ScriptedProvider::lookupPlan(2), stepBudget: 99), ['action' => 'resume', 'run' => $runId]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(RunStatus::Completed, (new RunStore($claw->memory()))->load($runId)->status);
    }

    public function test_resume_due_finishes_every_due_run_and_prints_the_counts(): void
    {
        $claw = $this->durableClaw(ScriptedProvider::lookupPlan(2), stepBudget: 1);
        [$runId, $conversationId] = $this->pausedRunOf('alice', $claw);

        $tester = $this->runCommand($this->durableClaw(ScriptedProvider::lookupPlan(2), stepBudget: 99), ['action' => 'resume-due']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('completed=1 suspended=0 failed=0', $tester->getDisplay());
        self::assertSame(['user', 'assistant'], $this->messageRoles($conversationId));
        self::assertSame(RunStatus::Completed, (new RunStore($claw->memory()))->load($runId)->status);
    }

    public function test_a_run_that_stops_again_is_reported_as_stopped(): void
    {
        $claw = $this->durableClaw(ScriptedProvider::twoRefundPlan());
        [$runId] = $this->pausedRunOf('alice', $claw);

        $tester = $this->runCommand($claw, ['action' => 'approve', 'run' => $runId, 'call' => 'c1']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('stopped again: awaiting_approval', $tester->getDisplay());
    }

    public function test_an_unknown_run_or_a_wrong_call_is_refused(): void
    {
        $claw = $this->durableClaw(ScriptedProvider::refundPlan());
        [$runId] = $this->pausedRunOf('alice', $claw);

        self::assertSame(Command::FAILURE, $this->runCommand($claw, ['action' => 'approve', 'run' => '01JUNKNOWNRUN0000000000000', 'call' => 'c1'])->getStatusCode());
        self::assertSame(Command::FAILURE, $this->runCommand($claw, ['action' => 'approve', 'run' => $runId, 'call' => 'nope'])->getStatusCode());
        self::assertSame([], CountingTool::$runs);
    }

    public function test_a_conflict_is_reported(): void
    {
        $claw = $this->durableClaw(ScriptedProvider::refundPlan());
        [$runId] = $this->pausedRunOf('alice', $claw);
        $this->conflicting->armed = true;

        $tester = $this->runCommand($claw, ['action' => 'approve', 'run' => $runId, 'call' => 'c1']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Another process changed this run first', $tester->getDisplay());
    }

    public function test_missing_ids_and_an_unknown_action_fail(): void
    {
        $claw = $this->durableClaw(ScriptedProvider::refundPlan());

        self::assertSame(Command::FAILURE, $this->runCommand($claw, ['action' => 'approve'])->getStatusCode());
        self::assertSame(Command::FAILURE, $this->runCommand($claw, ['action' => 'approve', 'run' => '01JUNKNOWNRUN0000000000000'])->getStatusCode());
        self::assertSame(Command::FAILURE, $this->runCommand($claw, ['action' => 'explode'])->getStatusCode());
    }

    public function test_an_engine_that_is_not_a_claw_cannot_serve_runs(): void
    {
        self::assertSame(Command::FAILURE, $this->runCommand($this->createMock(ClawInterface::class), ['action' => 'list'])->getStatusCode());
    }
}

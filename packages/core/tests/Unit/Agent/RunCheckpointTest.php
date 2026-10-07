<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Agent;

use PhpClaw\Agent\RunBudget;
use PhpClaw\Agent\RunCheckpoint;
use PhpClaw\Agent\RunStore;
use PhpClaw\Memory\ArrayMemory;
use PHPUnit\Framework\TestCase;

final class RunCheckpointTest extends TestCase
{
    private const NANOSECONDS_PER_SECOND = 1_000_000_000;

    protected function tearDown(): void
    {
        set_time_limit(0);
    }

    private function checkpointStartedUnder(int $maxExecutionTime): RunCheckpoint
    {
        ini_set('max_execution_time', (string) $maxExecutionTime);
        $checkpoint = new RunCheckpoint(new RunStore(new ArrayMemory), new RunBudget);
        set_time_limit(0);

        return $checkpoint;
    }

    public function test_a_blank_deadline_keeps_half_the_time_limit_the_run_started_with_after_a_provider_call_lifts_it(): void
    {
        $checkpoint = $this->checkpointStartedUnder(4);

        self::assertTrue($checkpoint->isBudgetSpent(0, hrtime(true) - 3 * self::NANOSECONDS_PER_SECOND));
    }

    public function test_a_blank_deadline_is_not_spent_before_half_the_time_limit(): void
    {
        $checkpoint = $this->checkpointStartedUnder(4);

        self::assertFalse($checkpoint->isBudgetSpent(0, hrtime(true) - 1 * self::NANOSECONDS_PER_SECOND));
    }

    public function test_no_time_limit_at_the_start_means_no_deadline(): void
    {
        $checkpoint = $this->checkpointStartedUnder(0);

        self::assertFalse($checkpoint->isBudgetSpent(0, hrtime(true) - 3600 * self::NANOSECONDS_PER_SECOND));
    }

    public function test_a_set_deadline_and_the_step_budget_still_apply(): void
    {
        $checkpoint = new RunCheckpoint(new RunStore(new ArrayMemory), new RunBudget(steps: 2, deadlineSeconds: 5));

        self::assertFalse($checkpoint->isBudgetSpent(1, hrtime(true)));
        self::assertTrue($checkpoint->isBudgetSpent(2, hrtime(true)));
        self::assertTrue($checkpoint->isBudgetSpent(0, hrtime(true) - 6 * self::NANOSECONDS_PER_SECOND));
    }
}

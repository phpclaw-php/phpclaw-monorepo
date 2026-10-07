<?php

declare(strict_types=1);

namespace PhpClaw\Agent;

/**
 * Counts of the saved runs one Claw::resumeDue() call resumed, by outcome.
 */
final class ResumeReport
{
    public const COMPLETED = 'completed';

    public const SUSPENDED = 'suspended';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    /**
     * Build the report.
     *
     * @param  int  $completed  Runs that finished with an answer.
     * @param  int  $suspended  Runs that paused again or spent their budget.
     * @param  int  $failed  Runs that ended with an error and are now marked failed.
     * @param  int  $skipped  Runs another process saved first, or that were no longer resumable.
     * @return void
     */
    public function __construct(
        public readonly int $completed = 0,
        public readonly int $suspended = 0,
        public readonly int $failed = 0,
        public readonly int $skipped = 0,
    ) {}

    /**
     * Number of runs this call picked up.
     *
     * @return int
     */
    public function total(): int
    {
        return $this->completed + $this->suspended + $this->failed + $this->skipped;
    }
}

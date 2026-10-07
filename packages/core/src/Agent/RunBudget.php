<?php

declare(strict_types=1);

namespace PhpClaw\Agent;

/**
 * How much one process may spend on a durable run before it suspends: a number of steps and a number of seconds.
 */
final class RunBudget
{
    /**
     * Build a budget.
     *
     * @param  int  $steps  Steps one process may run; 0 = no limit.
     * @param  int|null  $deadlineSeconds  Seconds one process may spend; null = half of max_execution_time when it is set, else none; 0 = no limit.
     * @return void
     */
    public function __construct(
        public readonly int $steps = 0,
        public readonly ?int $deadlineSeconds = null,
    ) {}

    /**
     * Seconds one process may spend: the configured value, else half of max_execution_time when it is set, else none.
     *
     * @return int Seconds; 0 = no limit.
     */
    public function seconds(): int
    {
        if ($this->deadlineSeconds !== null) {
            return max(0, $this->deadlineSeconds);
        }

        $maxExecution = (int) ini_get('max_execution_time');

        return $maxExecution > 0 ? intdiv($maxExecution, 2) : 0;
    }
}

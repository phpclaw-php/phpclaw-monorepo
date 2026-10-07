<?php

declare(strict_types=1);

namespace PhpClaw\Agent;

use PhpClaw\Exceptions\RunConflictException;
use PhpClaw\Hooks\Dispatchers\AgentEventDispatcher;

/**
 * One process's handle on a durable run: saves each step, notices a cancel, and tracks its budget.
 */
final class RunCheckpoint
{
    private const NANOSECONDS_PER_SECOND = 1_000_000_000;

    private readonly RunStore $store;

    private readonly RunBudget $budget;

    private readonly int $deadlineSeconds;

    private int $version;

    /**
     * Build a checkpoint for one process.
     *
     * @param  RunStore  $store  Where the run is saved.
     * @param  RunBudget  $budget  What this process may spend.
     * @param  int  $version  Version of the saved run this process starts from; 0 for a new run.
     * @return void
     */
    public function __construct(RunStore $store, RunBudget $budget, int $version = 0)
    {
        $this->store = $store;
        $this->budget = $budget;
        $this->deadlineSeconds = $budget->seconds();
        $this->version = $version;
    }

    /**
     * Save the run and return the status now stored: Cancelled, without saving, when another process cancelled it.
     *
     * @param  RunState  $state  State to save.
     * @return RunStatus
     *
     * @throws RunConflictException When another process saved the run meanwhile.
     */
    public function save(RunState $state): RunStatus
    {
        if ($this->store->isCancelled($state->runId())) {
            return RunStatus::Cancelled;
        }

        $this->store->save($state->withVersion($this->version));
        $this->version++;

        if ($state->status->isResumable()) {
            AgentEventDispatcher::runSuspended($state->runId(), $state->status->value, $state->progress->iteration, $state->paused?->toolName() ?? '', $state->paused?->callId() ?? '');
        }

        return $state->status;
    }

    /**
     * Whether this process has spent its step or time budget.
     *
     * @param  int  $stepsRun  Steps run in this process so far.
     * @param  int  $startNs  hrtime(true) when this process started the loop.
     * @return bool
     */
    public function isBudgetSpent(int $stepsRun, int $startNs): bool
    {
        return ($this->budget->steps > 0 && $stepsRun >= $this->budget->steps)
            || ($this->deadlineSeconds > 0 && hrtime(true) - $startNs >= $this->deadlineSeconds * self::NANOSECONDS_PER_SECOND);
    }
}

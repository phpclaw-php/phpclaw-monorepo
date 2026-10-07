<?php

declare(strict_types=1);

namespace PhpClaw\Exceptions;

use PhpClaw\Agent\RunStatus;

/**
 * Thrown when a durable run stops before its answer: paused for approval, paused on a step or time budget, or cancelled.
 */
final class RunSuspendedException extends PhpClawException
{
    public readonly string $runId;

    public readonly RunStatus $status;

    /**
     * Create a new RunSuspendedException instance.
     *
     * @param  string  $runId  Id of the saved run; pass it to resume(), approve(), deny() or cancel().
     * @param  RunStatus  $status  Status the run was saved with.
     * @return void
     */
    public function __construct(string $runId, RunStatus $status)
    {
        $this->runId = $runId;
        $this->status = $status;
        parent::__construct("Run {$runId} stopped with status {$status->value}.");
    }
}

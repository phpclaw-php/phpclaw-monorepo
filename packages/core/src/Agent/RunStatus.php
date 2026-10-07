<?php

declare(strict_types=1);

namespace PhpClaw\Agent;

/**
 * Lifecycle status of a durable run.
 */
enum RunStatus: string
{
    case Running = 'running';
    case AwaitingApproval = 'awaiting_approval';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
    case Failed = 'failed';

    /**
     * Whether a run in this status may be resumed.
     *
     * @return bool True for Suspended and AwaitingApproval.
     */
    public function isResumable(): bool
    {
        return $this === self::Suspended || $this === self::AwaitingApproval;
    }

    /**
     * Whether a run in this status has ended for good.
     *
     * @return bool True for Cancelled, Completed and Failed.
     */
    public function isTerminal(): bool
    {
        return $this === self::Cancelled || $this === self::Completed || $this === self::Failed;
    }
}

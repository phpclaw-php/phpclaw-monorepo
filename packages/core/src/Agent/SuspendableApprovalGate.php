<?php

declare(strict_types=1);

namespace PhpClaw\Agent;

use PhpClaw\Agent\Contracts\ApprovalGateInterface;
use PhpClaw\Exceptions\ApprovalPendingException;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\ToolMutability;

/**
 * Approval gate for web and queued runs: a mutating call pauses the run for a later human decision instead of blocking.
 */
final class SuspendableApprovalGate implements ApprovalGateInterface
{
    /**
     * Let read-only calls through and pause the run on a mutating one; a decided call never reaches the gate again.
     *
     * @param  string  $toolName  Tool about to execute.
     * @param  array<string, mixed>  $toolInput  Input the model supplied.
     * @param  ToolInterface|null  $tool  The resolved tool instance, or null if not found.
     * @return void Returning normally means the call may run.
     *
     * @throws ApprovalPendingException When the call needs a human decision.
     */
    public function check(string $toolName, array $toolInput, ?ToolInterface $tool = null): void
    {
        if (ToolMutability::isApprovalRequired($tool, $toolInput)) {
            throw new ApprovalPendingException($toolName, $toolInput);
        }
    }
}

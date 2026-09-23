<?php

declare(strict_types=1);

namespace PhpClaw\Agent\Contracts;

use PhpClaw\Exceptions\HumanDeniedException;
use PhpClaw\Tools\Contracts\ToolInterface;

/**
 * First-class approval gate checked by the agent before a gated tool executes.
 */
interface ApprovalGateInterface
{
    /**
     * Approve or deny a pending tool call.
     *
     * @param  string  $toolName  Tool about to execute.
     * @param  array<string, mixed>  $toolInput  Input the model supplied.
     * @param  ToolInterface|null  $tool  The resolved tool instance, or null if not found.
     * @return void Returning normally means approved.
     *
     * @throws HumanDeniedException To deny the call.
     */
    public function check(string $toolName, array $toolInput, ?ToolInterface $tool = null): void;
}

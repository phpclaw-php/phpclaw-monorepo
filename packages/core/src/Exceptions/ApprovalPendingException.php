<?php

declare(strict_types=1);

namespace PhpClaw\Exceptions;

/**
 * Thrown by an approval gate to pause the run until a human decides the call, instead of deciding it now.
 */
final class ApprovalPendingException extends PhpClawException
{
    public readonly string $toolName;

    public readonly array $toolInput;

    /**
     * Create a new ApprovalPendingException instance.
     *
     * @param  string  $toolName  Tool waiting for a decision.
     * @param  array<string, mixed>  $toolInput  Input the model supplied.
     * @return void
     */
    public function __construct(string $toolName, array $toolInput = [])
    {
        $this->toolName = $toolName;
        $this->toolInput = $toolInput;
        parent::__construct("Approval needed before running: {$toolName}");
    }
}

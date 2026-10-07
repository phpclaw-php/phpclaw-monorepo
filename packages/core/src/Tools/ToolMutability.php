<?php

declare(strict_types=1);

namespace PhpClaw\Tools;

use PhpClaw\Tools\Contracts\MutatingToolInterface;
use PhpClaw\Tools\Contracts\PerInvocationMutabilityInterface;
use PhpClaw\Tools\Contracts\ToolInterface;

/**
 * Decides whether a tool call changes state and so needs a human decision before it runs.
 */
final class ToolMutability
{
    /**
     * Whether this call needs approval: a per-call tool when this input mutates, otherwise any MutatingToolInterface tool.
     *
     * @param  ToolInterface|null  $tool  The resolved tool, or null when the name is not registered.
     * @param  array<string, mixed>  $input  Input the model supplied.
     * @return bool True when the call must be approved first.
     */
    public static function isApprovalRequired(?ToolInterface $tool, array $input): bool
    {
        if ($tool instanceof PerInvocationMutabilityInterface) {
            return $tool->isMutating($input);
        }

        return $tool instanceof MutatingToolInterface;
    }
}

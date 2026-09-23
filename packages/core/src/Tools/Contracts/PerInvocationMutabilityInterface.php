<?php

declare(strict_types=1);

namespace PhpClaw\Tools\Contracts;

/**
 * Opt-in contract for a tool whose mutating-ness depends on the specific call, not just its class, lets an approval gate fast-path a benign invocation instead of gating the whole tool.
 */
interface PerInvocationMutabilityInterface
{
    /**
     * Whether this specific invocation would mutate state, given the input it will receive.
     *
     * @param  array<string, mixed>  $input  Same input the tool is about to receive.
     * @return bool True when THIS call would mutate state.
     */
    public function isMutating(array $input): bool;
}

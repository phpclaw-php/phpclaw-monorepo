<?php

declare(strict_types=1);

namespace PhpClaw\Tools\Contracts;

/**
 * Opt-in contract for tools that hold per-run state needing a clean slate at the start of each agent run.
 */
interface ResettableInterface
{
    /**
     * Clear all per-run state so the next agent run starts from a clean slate.
     *
     * @return void
     */
    public function reset(): void;
}

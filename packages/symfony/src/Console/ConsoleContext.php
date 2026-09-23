<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Console;

/**
 * Records whether this process is running an interactive console command.
 */
final class ConsoleContext
{
    private bool $console = false;

    /**
     * Mark this process as an interactive console run.
     *
     * @return void
     */
    public function markConsole(): void
    {
        $this->console = true;
    }

    /**
     * Whether this process is an interactive console run.
     *
     * @return bool
     */
    public function isConsole(): bool
    {
        return $this->console;
    }

    /**
     * Clear the mark so a long-lived process cannot inherit an earlier command's context.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->console = false;
    }
}

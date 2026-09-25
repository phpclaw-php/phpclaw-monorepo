<?php

declare(strict_types=1);

namespace PhpClaw\Hooks\Dispatchers;

use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;

/**
 * Typed dispatchers for ShellTool execution events.
 */
final class ShellEventDispatcher
{
    /**
     * Fires when ShellTool blocks a command due to the hard-block list, allowlist, or sensitive file.
     *
     * @param  string  $command  Shell command.
     * @param  string  $cmdName  Resolved command name.
     * @param  string  $reason  Reason for the event.
     * @param  string  $file  File path, if any.
     * @return void
     */
    public static function denied(
        string $command,
        string $cmdName,
        string $reason,
        string $file = '',
    ): void {
        $ctx = [
            'command' => $command,
            'cmd_name' => $cmdName,
            'reason' => $reason,
        ];

        if ($file !== '') {
            $ctx['file'] = $file;
        }

        HookRegistry::fire(LifecycleEvent::ShellDenied->value, $ctx);
    }

    /**
     * Fires after ShellTool successfully executes an allowlisted command.
     *
     * @param  string  $command  Shell command.
     * @param  string  $cmdName  Resolved command name.
     * @return void
     */
    public static function exec(string $command, string $cmdName): void
    {
        HookRegistry::fire(LifecycleEvent::ShellExec->value, [
            'command' => $command,
            'cmd_name' => $cmdName,
        ]);
    }
}

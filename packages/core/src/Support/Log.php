<?php

declare(strict_types=1);

namespace PhpClaw\Support;

/**
 * Process-wide logging gateway: routes phpClaw diagnostics to error_log().
 */
final class Log
{
    /**
     * Record an informational message.
     *
     * @param  string  $message  Human-readable message.
     * @return void
     */
    public static function info(string $message): void
    {
        error_log($message);
    }

    /**
     * Record a warning: a recoverable or skipped condition.
     *
     * @param  string  $message  Human-readable message.
     * @return void
     */
    public static function warning(string $message): void
    {
        error_log($message);
    }

    /**
     * Record an error, a failed operation the caller worked around.
     *
     * @param  string  $message  Human-readable message.
     * @return void
     */
    public static function error(string $message): void
    {
        error_log($message);
    }
}

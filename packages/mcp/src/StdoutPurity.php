<?php

declare(strict_types=1);

namespace PhpClaw\Mcp;

/**
 * Keeps a stdio JSON-RPC stream free of PHP diagnostics without discarding them, by buffering the
 * bootstrap window and routing every later diagnostic to STDERR.
 */
final class StdoutPurity
{
    public const TARGET = 'stderr';

    /**
     * Open the capture buffer. Must run before any framework bootstrap, because a buffer cannot
     * be overwritten by an ini_set the way display_errors can.
     *
     * @return void
     */
    public static function beginCapture(): void
    {
        ob_start();
    }

    /**
     * Close the capture buffer, forward anything the bootstrap printed to STDERR, then route
     * later diagnostics to STDERR directly. Leaves error_reporting untouched.
     *
     * @param  resource|null  $errorStream  Stream that receives captured output (default STDERR).
     * @return string Whatever the bootstrap wrote to stdout, empty when it was silent.
     */
    public static function endCaptureAndRoute($errorStream = null): string
    {
        $captured = ob_get_level() > 0 ? (string) ob_get_clean() : '';

        if ($captured !== '') {
            fwrite($errorStream ?? STDERR, $captured);
        }

        ini_set('display_errors', self::TARGET);

        return $captured;
    }

    /**
     * Re-assert the routing immediately before a frame is written, and report whether it had
     * drifted. A true return means something downstream is still writing display_errors.
     *
     * @return bool True when the value had drifted and was reset.
     */
    public static function reassertBeforeFrame(): bool
    {
        if ((string) ini_get('display_errors') === self::TARGET) {
            return false;
        }

        ini_set('display_errors', self::TARGET);

        return true;
    }
}

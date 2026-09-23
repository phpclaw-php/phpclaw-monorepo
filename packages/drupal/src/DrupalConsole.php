<?php

declare(strict_types=1);

namespace PhpClaw\Drupal;

/**
 * Reports whether a phpClaw console entrypoint marked this process as a console run.
 */
final class DrupalConsole
{
    public const MARKER = 'PHPCLAW_DRUPAL_CONSOLE';

    /**
     * Mark this process as a phpClaw console run. Safe to call more than once.
     *
     * @return void
     */
    public static function mark(): void
    {
        if (! defined(self::MARKER)) {
            define(self::MARKER, true);
        }
    }

    /**
     * Whether a phpClaw console entrypoint marked this process as a console run.
     *
     * @return bool
     */
    public static function isActive(): bool
    {
        return defined(self::MARKER) && constant(self::MARKER) === true;
    }
}

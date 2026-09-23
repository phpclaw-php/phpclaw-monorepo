<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Component\Administrator\Admin;

/**
 * Admin notice helper - shows API key configuration reminder.
 */
final class AdminNotice
{
    /**
     * Returns true if the "no API key configured" notice should be shown.
     *
     * @param  array<string, mixed>  $saved  Saved plugin settings
     * @return bool True if the notice should be displayed
     */
    public static function shouldShow(array $saved): bool
    {
        return empty($saved['api_key']);
    }
}

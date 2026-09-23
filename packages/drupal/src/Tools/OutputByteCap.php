<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tools;

/**
 * Shared output-size cap and byte-boundary truncation for phpClaw tools.
 */
final class OutputByteCap
{
    public const MAX_OUTPUT_BYTES = 8192;

    /**
     * Byte-cap truncation notice, derived from MAX_OUTPUT_BYTES so the "N KB" label can't drift out of sync.
     *
     * @return string
     */
    public static function truncationNotice(): string
    {
        return "\n[... output truncated at ".(int) (self::MAX_OUTPUT_BYTES / 1024).' KB ...]';
    }

    /**
     * Cut $text to the byte cap and append the truncation notice, or return it unchanged when it already fits.
     *
     * @param  string  $text  Text to cap.
     * @return string $text unchanged, or truncated with a trailing notice.
     */
    public static function truncate(string $text): string
    {
        if (strlen($text) <= self::MAX_OUTPUT_BYTES) {
            return $text;
        }

        return substr($text, 0, self::MAX_OUTPUT_BYTES).self::truncationNotice();
    }
}

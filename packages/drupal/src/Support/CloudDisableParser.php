<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Support;

/**
 * Normalises a list setting (cloud_disable, always_include_tools) into a clean string[].
 */
final class CloudDisableParser
{
    /**
     * Parse a raw list setting, array or comma-separated string, into a clean string array.
     *
     * @param  mixed  $raw  Array or comma-separated string from config.
     * @return string[] Trimmed names, blanks dropped.
     */
    public static function parse(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values(array_filter(
                array_map(static fn (mixed $v): string => trim(is_scalar($v) ? (string) $v : ''), $raw),
                static fn (string $s): bool => $s !== '',
            ));
        }

        return array_values(array_filter(
            array_map('trim', explode(',', (string) $raw)),
            static fn (string $s): bool => $s !== '',
        ));
    }
}

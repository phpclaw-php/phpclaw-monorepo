<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Support;

/**
 * Normalises the cloud_disable setting into a clean string[].
 */
final class CloudDisableParser
{
    /**
     * Parse raw cloud_disable config value into a clean string array.
     *
     * @param  mixed  $raw  Array or comma-separated string from config.
     * @return string[] Normalised list of feature names to disable.
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

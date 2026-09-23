<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart;

/**
 * Centralised resolver for the OpenCart table prefix.
 */
final class OcTablePrefix
{
    /**
     * Resolve the effective table prefix.
     *
     * @param  string|null  $prefix  Caller-supplied prefix, or null to auto-detect.
     * @return string Resolved, non-empty table prefix.
     */
    public static function resolve(?string $prefix): string
    {
        if ($prefix !== null) {
            return $prefix;
        }

        if (defined('DB_PREFIX')) {
            return (string) constant('DB_PREFIX');
        }

        return 'oc_';
    }
}

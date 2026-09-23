<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Install;

/**
 * Splits a shipped .sql file into the statements the installer executes.
 */
final class SqlStatements
{
    /**
     * Split a SQL file into executable statements.
     *
     * @param  string  $sql  Raw file contents with PREFIX_ already substituted.
     * @return array<int, string> Trimmed, non-empty statements in file order.
     */
    public static function split(string $sql): array
    {
        return array_values(array_filter(array_map('trim', explode(';', $sql))));
    }
}

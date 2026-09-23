<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tools;

use PhpClaw\SqlGuard\SqlGuardException;
use PhpClaw\SqlGuard\SqlPolicyInterface;
use PhpClaw\SqlGuard\SqlTokenStream;

/**
 * Adapter-specific policy that blocks queries touching sensitive tables.
 */
final class BlockedTablesPolicy implements SqlPolicyInterface
{
    /**
     * Bind the lower-cased table names this policy rejects.
     *
     * @param  string[]  $blockedTables  Table names to reject; the comparison is case-insensitive.
     * @return void
     */
    public function __construct(private readonly array $blockedTables) {}

    /**
     * Throw when any blocked table name appears in the token stream.
     *
     * @param  SqlTokenStream  $tokens  Parsed word tokens from the guard.
     * @return void
     *
     * @throws SqlGuardException When a blocked table is referenced.
     */
    public function apply(SqlTokenStream $tokens): void
    {
        foreach ($this->blockedTables as $table) {
            if (in_array(strtoupper($table), $tokens->words(), true)) {
                throw new SqlGuardException("access to table '{$table}' is not permitted.");
            }
        }
    }
}

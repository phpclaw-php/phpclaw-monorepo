<?php

declare(strict_types=1);

namespace PhpClaw\SqlGuard;

/**
 * Optional adapter-specific rule applied after the core read-only-shape checks pass, layering extra constraints (blocked tables, allowed schemas, …) on top of the shared guard without re-implementing SQL validation.
 */
interface SqlPolicyInterface
{
    /**
     * Inspect the parsed tokens and throw to reject the query.
     *
     * @param  SqlTokenStream  $tokens  The guard's parsed word tokens.
     * @return void
     *
     * @throws SqlGuardException When the policy rejects the query.
     */
    public function apply(SqlTokenStream $tokens): void;
}

<?php

declare(strict_types=1);

namespace PhpClaw\SqlGuard;

/**
 * Immutable carrier for a query that has passed SqlReadOnlyGuard validation, only the guard and this class's own controlled rewrites (e.g. withLimit) may construct one, so a caller can never validate one string then execute a different one; the only runnable string is the validated one returned by sql().
 */
final class SafeSql
{
    /**
     * Create a new SafeSql instance.
     *
     * @param  string  $sql  The exact validated query string.
     * @return void
     */
    public function __construct(private readonly string $sql) {}

    /**
     * The validated query string to execute: never the raw caller input.
     *
     * @return string
     */
    public function sql(): string
    {
        return $this->sql;
    }

    /**
     * Append a row limit to the validated query, keeping the result inside the trusted type.
     *
     * @param  int  $limit  Maximum rows; values below 1 are clamped to 1.
     * @return self
     */
    public function withLimit(int $limit): self
    {
        return new self(rtrim($this->sql, "; \t\n\r").' LIMIT '.max(1, $limit));
    }
}

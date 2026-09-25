<?php

declare(strict_types=1);

namespace PhpClaw\SqlGuard;

/**
 * Immutable carrier for a validated query string; by convention only SqlReadOnlyGuard and this class's own controlled rewrites construct it, so the validated string and the executed string stay identical.
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

<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Contracts;

/**
 * Native PrestaShop DB contract the adapter binds to instead of the concrete `\Db` class (reads via executeS, writes via execute, quoting via escape).
 */
interface PsDbInterface
{
    /**
     * Run a SQL statement and return a normalized result wrapper.
     *
     * @param  string  $sql  SQL statement, optionally with `?` placeholders.
     * @param  array<int, mixed>  $params  Positional `?` bindings, scalar or null.
     * @return object
     *
     * @throws \Throwable On query error, callers MAY normalize this to a domain exception.
     */
    public function query(string $sql, array $params = []): object;

    /**
     * Escape a string value for safe single-quote-delimited embedding in SQL.
     *
     * @param  string  $value  Raw value to escape.
     * @return string
     */
    public function escape(string $value): string;
}

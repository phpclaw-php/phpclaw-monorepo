<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Contracts;

/**
 * Native OpenCart DB contract used by every tool and memory class in the adapter.
 */
interface OcDbInterface
{
    /**
     * Run a SQL statement and return OC's standard result wrapper.
     *
     * @param  string  $sql  SQL statement.
     * @param  array<int, mixed>  $params  Positional `?` bindings, scalar or null.
     * @return object
     *
     * @throws \Throwable On query error; adapter MAY normalize this to a domain exception.
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

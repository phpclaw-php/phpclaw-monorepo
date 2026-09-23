<?php

declare(strict_types=1);

/**
 * Test-only wpdb stub. Loaded by tests/bootstrap.php so source code that
 * accepts the `\wpdb` type-hint (e.g. DatabaseTool::executeSchema()) can be
 * exercised under unit tests.
 *
 * Real test wpdb instances substitute method behaviour by extending this class
 * or by setting the public `nextResults`/`nextVar` properties.
 */
if (! defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}
if (! defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (! defined('ARRAY_N')) {
    define('ARRAY_N', 'ARRAY_N');
}

class wpdb
{
    public string $prefix = 'wp_';

    public string $last_error = '';

    public ?array $nextResults = null;

    public string|int|null $nextVar = null;

    public ?array $nextRow = null;

    public function prepare(string $sql, mixed ...$args): string
    {
        return $sql;
    }

    public function get_results(string $sql, string $output = OBJECT): ?array
    {
        return $this->nextResults;
    }

    public function get_var(string $sql): string|int|null
    {
        return $this->nextVar;
    }

    public function get_row(string $sql, string $output = OBJECT): ?array
    {
        return $this->nextRow;
    }

    public function esc_like(string $s): string
    {
        return $s;
    }
}

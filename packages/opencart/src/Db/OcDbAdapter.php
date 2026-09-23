<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Db;

use PhpClaw\OpenCart\Contracts\OcDbInterface;

/**
 * Adapter wrapping OpenCart's native DB instance into the {@see OcDbInterface} contract.
 */
final class OcDbAdapter implements OcDbInterface
{
    /**
     * Bind the native OpenCart DB instance this adapter delegates to.
     *
     * @param  object  $db  OpenCart's `\DB` instance (any version).
     */
    public function __construct(private readonly object $db) {}

    /**
     * Substitute positional `?` placeholders inline and delegate to the native `\DB::query()`.
     *
     * @param  string  $sql  SQL statement, optionally with `?` placeholders.
     * @param  array<int, mixed>  $params  Positional bindings, scalar or null.
     * @return object
     */
    public function query(string $sql, array $params = []): object
    {
        if ($params !== []) {
            $sql = $this->bindInline($sql, $params);
        }

        $result = $this->db->query($sql);

        if (is_object($result)) {
            return $result;
        }

        $empty = new \stdClass;
        $empty->rows = [];
        $empty->row = [];
        $empty->num_rows = 0;

        return $empty;
    }

    /**
     * Delegate to OC's `escape()`, returning the value without surrounding quotes.
     *
     * @param  string  $value  Raw value.
     * @return string
     */
    public function escape(string $value): string
    {
        return (string) $this->db->escape($value);
    }

    /**
     * Replace every positional `?` in $sql with its escaped binding value.
     *
     * @param  string  $sql  SQL with `?` placeholders.
     * @param  array<int, mixed>  $params  Values to substitute, in order.
     * @return string
     */
    private function bindInline(string $sql, array $params): string
    {
        $db = $this->db;
        $index = 0;

        return (string) preg_replace_callback(
            '/\?/',
            static function () use ($params, $db, &$index): string {
                if (! array_key_exists($index, $params)) {
                    return '?';
                }

                $value = $params[$index++];

                if ($value === null) {
                    return 'NULL';
                }
                if (is_bool($value)) {
                    return $value ? '1' : '0';
                }
                if (is_int($value) || is_float($value)) {
                    return (string) $value;
                }

                return "'".(string) $db->escape((string) $value)."'";
            },
            $sql,
        );
    }
}

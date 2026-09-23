<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Db;

use PhpClaw\PrestaShop\Contracts\PsDbInterface;

/**
 * Adapter wrapping PrestaShop's native `\Db` singleton into the package-internal {@see PsDbInterface} contract.
 */
final class PsDbAdapter implements PsDbInterface
{
    /**
     * Create a new PsDbAdapter instance.
     *
     * @param  object  $db  PrestaShop's `\Db` instance (typically `\Db::getInstance()`).
     */
    public function __construct(private readonly object $db) {}

    /**
     * Inline-substitute `?` placeholders, dispatch reads to executeS() and writes to execute(), and return a normalized result.
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

        if ($this->isRead($sql)) {
            $rows = $this->db->executeS($sql);
            $rows = is_array($rows) ? $rows : [];
        } else {
            $this->db->execute($sql);
            $rows = [];
        }

        $result = new \stdClass;
        $result->rows = $rows;
        $result->row = $rows[0] ?? [];
        $result->num_rows = count($rows);

        return $result;
    }

    /**
     * Delegate to PrestaShop's `escape()`, returning the value without surrounding quotes.
     *
     * @param  string  $value  Raw value.
     * @return string
     */
    public function escape(string $value): string
    {
        return (string) $this->db->escape($value);
    }

    /**
     * True when the statement is a read PrestaShop's `executeS()` can run.
     *
     * @param  string  $sql  SQL statement.
     * @return bool
     */
    private function isRead(string $sql): bool
    {
        return (bool) preg_match('/^\s*\(?\s*(SELECT|SHOW|PRAGMA|EXPLAIN|DESCRIBE)\b/i', $sql);
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
        $self = $this;
        $index = 0;

        return (string) preg_replace_callback(
            '/\?/',
            static function () use ($params, $self, &$index): string {
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

                return "'".$self->escape((string) $value)."'";
            },
            $sql,
        );
    }
}

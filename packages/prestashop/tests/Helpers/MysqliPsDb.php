<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Helpers;

use PhpClaw\PrestaShop\Contracts\PsDbInterface;

final class MysqliPsDb implements PsDbInterface
{
    public function __construct(public readonly \mysqli $mysqli) {}

    public function query(string $sql, array $params = []): object
    {
        if ($params !== []) {
            $sql = $this->bindInline($sql, $params);
        }

        $result = $this->mysqli->query($sql);

        $obj = new \stdClass;
        $obj->rows = [];
        $obj->row = [];
        $obj->num_rows = 0;

        if ($result instanceof \mysqli_result) {
            $rows = $result->fetch_all(MYSQLI_ASSOC);
            $obj->rows = $rows;
            $obj->row = $rows[0] ?? [];
            $obj->num_rows = count($rows);
            $result->free();
        } elseif ($result === false) {
            throw new \RuntimeException('MySQL query failed: '.$this->mysqli->error.' | SQL: '.$sql);
        }

        return $obj;
    }

    public function escape(string $value): string
    {
        return $this->mysqli->real_escape_string($value);
    }

    private function bindInline(string $sql, array $params): string
    {
        $index = 0;
        $self = $this;

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

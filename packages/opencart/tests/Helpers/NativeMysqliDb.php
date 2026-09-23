<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tests\Helpers;

final class NativeMysqliDb
{
    public function __construct(private readonly \mysqli $mysqli) {}

    public function query(string $sql): object
    {
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
}

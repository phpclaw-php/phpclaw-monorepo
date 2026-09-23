<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tests\Helpers;

use PhpClaw\OpenCart\Contracts\OcDbInterface;
use PhpClaw\OpenCart\Db\OcDbAdapter;

final class MysqliOcDb implements OcDbInterface
{
    private readonly OcDbAdapter $adapter;

    public function __construct(public readonly \mysqli $mysqli)
    {
        $this->adapter = new OcDbAdapter(new NativeMysqliDb($mysqli));
    }

    public function query(string $sql, array $params = []): object
    {
        return $this->adapter->query($sql, $params);
    }

    public function escape(string $value): string
    {
        return $this->mysqli->real_escape_string($value);
    }
}

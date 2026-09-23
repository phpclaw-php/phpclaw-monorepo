<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Tools;

use PhpClaw\PrestaShop\Contracts\PsDbInterface;
use PhpClaw\PrestaShop\Tools\DatabaseTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DatabaseTool::class)]
final class DatabaseToolArchitectureTest extends TestCase
{
    public function test_db_query_receives_safe_sql_not_raw_input(): void
    {
        $rawInput = '  SELECT 1  ';

        $capturedSql = null;
        $stub = new class
        {
            public array $rows = [];
        };

        $db = $this->createMock(PsDbInterface::class);
        $db->method('query')
            ->willReturnCallback(function (string $sql) use (&$capturedSql, $stub): object {
                $capturedSql = $sql;

                return $stub;
            });

        $tool = new DatabaseTool($db, 'ps_', isConsole: true);
        $tool->execute(['sql' => $rawInput]);

        self::assertNotNull($capturedSql, 'db->query() was never called.');
        self::assertNotSame($rawInput, $capturedSql, 'Raw input must not be forwarded directly to db->query().');
        self::assertStringStartsWith('SELECT', $capturedSql);
        self::assertStringContainsString('LIMIT', $capturedSql);
    }
}

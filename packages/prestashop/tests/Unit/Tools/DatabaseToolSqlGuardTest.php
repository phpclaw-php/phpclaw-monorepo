<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\PrestaShop\Contracts\PsDbInterface;
use PhpClaw\PrestaShop\Tools\DatabaseTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DatabaseTool::class)]
final class DatabaseToolSqlGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        \PrestaShopLogger::reset();
    }

    public static function mutationPayloads(): array
    {
        return [
            'stacked_drop' => ['SELECT 1; DROP TABLE users'],
            'stacked_delete' => ['SELECT 1;DELETE FROM users'],
            'into_outfile' => ["SELECT * FROM users INTO OUTFILE '/tmp/p'"],
            'into_dumpfile' => ["SELECT * FROM users INTO DUMPFILE '/tmp/p'"],
            'into_split' => ["SELECT 1 INTO/**/OUTFILE '/tmp/p'"],
            'into_bang' => ["SELECT 1 /*!INTO OUTFILE '/tmp/p'*/"],
            'bang_drop' => ['SELECT * FROM users /*!12345 DROP TABLE users */'],
            'dashdash_nl' => ["SELECT 1 --\nDROP TABLE users"],
            'hash_nl' => ["SELECT 1 #\nDROP TABLE users"],
            'nested_comment' => ['SELECT 1 /*/**/DROP TABLE users*/'],
            'stacked_bang' => ['SELECT 1;/*! DROP TABLE users */'],
            'ws_lead' => ["\n\t  SELECT 1; DROP TABLE users"],
            'fullwidth' => ['ＳＥＬＥＣＴ 1; DROP TABLE users'],
            'zerowidth' => ["\u{200B}SELECT 1; DROP TABLE users"],
            'cte_delete' => ['WITH x AS (SELECT 1) DELETE FROM users'],
            'union_outfile' => ["SELECT 1 UNION SELECT * FROM users INTO OUTFILE '/tmp/p'"],
            'paren_stacked' => ['((SELECT 1)); DROP TABLE users'],
            'case_mixed' => ['SeLeCt 1; DrOp TABLE users'],
        ];
    }

    #[DataProvider('mutationPayloads')]
    public function test_sql_guard_blocks_or_flags_bypass(string $payload): void
    {
        $reached = false;
        $stub = new class
        {
            public array $rows = [];
        };

        $db = $this->createMock(PsDbInterface::class);
        $db->method('query')->willReturnCallback(function () use (&$reached, $stub): object {
            $reached = true;

            return $stub;
        });
        $db->method('escape')->willReturnArgument(0);

        $tool = new DatabaseTool($db, 'ps_', isConsole: true);
        $threw = false;

        try {
            $tool->execute(['sql' => $payload]);
        } catch (ToolException) {
            $threw = true;
        }

        $key = (string) $this->dataName();
        self::assertFalse($reached, 'BYPASSED: '.$key);
        self::assertTrue($threw);
    }
}

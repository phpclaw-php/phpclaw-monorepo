<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\SqlGuard;

use PhpClaw\SqlGuard\SafeSql;
use PhpClaw\SqlGuard\SqlDialect;
use PhpClaw\SqlGuard\SqlGuardException;
use PhpClaw\SqlGuard\SqlReadOnlyGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SqlReadOnlyGuardTest extends TestCase
{
    public static function attackPayloads(): array
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
            'semicolon_ws_write' => ["SELECT 1;  \n\t  DROP TABLE x"],
            'double_semicolon' => ['SELECT 1;;'],
            'semicolon_comment' => ['SELECT 1; -- comment'],
        ];
    }

    public static function writeInReadShapeAttacks(): array
    {
        return [
            'cte_trailing_delete' => ['WITH x AS (SELECT 1) DELETE FROM t'],
            'cte_inner_delete_pg' => ['WITH x AS (DELETE FROM t RETURNING *) SELECT * FROM x'],
            'union_into_outfile' => ["SELECT 1 UNION SELECT * FROM u INTO OUTFILE '/tmp/p'"],
            'except_into_dumpfile' => ["SELECT 1 EXCEPT SELECT 1 INTO DUMPFILE '/tmp/p'"],
            'nested_cte_write' => ['WITH a AS (WITH b AS (WITH c AS (DELETE FROM t RETURNING *) SELECT * FROM c) SELECT * FROM b) SELECT * FROM a'],
        ];
    }

    public static function legitReads(): array
    {
        return [
            'select_all' => ['SELECT * FROM users'],
            'select_columns' => ['SELECT id, name FROM users WHERE id = 5'],
            'count' => ['SELECT COUNT(*) AS c FROM orders'],
            'cte_select' => ['WITH t AS (SELECT 1 AS n) SELECT n FROM t'],
            'string_with_sep' => ["SELECT id FROM products WHERE name = 'a; b -- c'"],
            'limit' => ['SELECT * FROM users LIMIT 10'],
            'union_read' => ['SELECT id FROM a UNION SELECT id FROM b'],
            'paren_read' => ['(SELECT id FROM a)'],
            'join' => ['SELECT a.id FROM a JOIN b ON a.id = b.id WHERE b.active = 1'],
            'trailing_semi' => ["SELECT 1;   \n"],
            'except_read' => ['SELECT id FROM a EXCEPT SELECT id FROM b'],
            'intersect_read' => ['SELECT id FROM a INTERSECT SELECT id FROM b'],
        ];
    }

    #[DataProvider('attackPayloads')]
    public function test_rejects_attack_payload(string $sql): void
    {
        $this->expectException(SqlGuardException::class);
        (new SqlReadOnlyGuard)->validate($sql);
    }

    #[DataProvider('writeInReadShapeAttacks')]
    public function test_rejects_write_hidden_in_read_shape(string $sql): void
    {
        $this->expectException(SqlGuardException::class);
        (new SqlReadOnlyGuard)->validate($sql);
    }

    #[DataProvider('legitReads')]
    public function test_allows_legit_read(string $sql): void
    {
        $safe = (new SqlReadOnlyGuard)->validate($sql);

        self::assertInstanceOf(SafeSql::class, $safe);
        self::assertSame(trim($sql), $safe->sql());
    }

    public function test_safe_sql_returns_validated_string(): void
    {
        $safe = (new SqlReadOnlyGuard)->validate('SELECT 1');
        self::assertSame('SELECT 1', $safe->sql());
    }

    public function test_with_limit_appends_bounded_limit(): void
    {
        $safe = (new SqlReadOnlyGuard)->validate('SELECT * FROM t')->withLimit(50);
        self::assertSame('SELECT * FROM t LIMIT 50', $safe->sql());
    }

    public function test_mysql_dialect_treats_backslash_as_escape(): void
    {
        $safe = (new SqlReadOnlyGuard(SqlDialect::MySql))->validate("SELECT '\\'; still string'");
        self::assertInstanceOf(SafeSql::class, $safe);
    }
}

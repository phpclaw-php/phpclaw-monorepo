<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit\Tools;

use Doctrine\DBAL\Connection;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Symfony\Tests\Support\BuildsIdentity;
use PhpClaw\Symfony\Tools\DatabaseTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DatabaseToolTest extends TestCase
{
    use BuildsIdentity;

    public function test_name_and_description(): void
    {
        $tool = new DatabaseTool($this->mockConn([]));

        self::assertSame('db_query', $tool->name());
        self::assertStringContainsString('EXECUTE', $tool->description());
    }

    public function test_executes_select_and_returns_json(): void
    {
        $conn = $this->mockConn([['id' => 1, 'name' => 'Alice']]);
        $tool = new DatabaseTool(connection: $conn, identity: $this->buildIdentity('admin', true));

        $result = $tool->execute(['sql' => 'SELECT id, name FROM users LIMIT 1']);
        $data = json_decode($result, true);

        self::assertTrue($data['success']);
        self::assertSame(1, $data['meta']['count']);
        self::assertSame('Alice', $data['data']['rows'][0]['name']);
    }

    public function test_empty_sql_returns_invalid_argument_envelope(): void
    {
        $tool = new DatabaseTool(connection: $this->mockConn([]), identity: $this->buildIdentity('admin', true));
        $result = $tool->execute(['sql' => '']);
        $data = json_decode($result, true);

        self::assertFalse($data['success']);
        self::assertSame('INVALID_ARGUMENT', $data['error']['code']);
    }

    #[DataProvider('blockedSqlProvider')]
    public function test_blocks_non_select_statements(string $sql): void
    {
        $tool = new DatabaseTool(
            connection: $this->mockConn([]),
            identity: $this->buildIdentity('admin', true),
        );
        $result = $tool->execute(['sql' => $sql]);
        $data = json_decode($result, true);

        self::assertFalse($data['success']);
        self::assertSame('BLOCKED_STATEMENT', $data['error']['code']);
    }

    public static function blockedSqlProvider(): array
    {
        return [
            'insert' => ['INSERT INTO users (name) VALUES ("hack")'],
            'update' => ['UPDATE users SET name = "hack"'],
            'delete' => ['DELETE FROM users'],
            'drop' => ['DROP TABLE users'],
            'truncate' => ['TRUNCATE TABLE users'],
            'alter' => ['ALTER TABLE users ADD COLUMN evil TEXT'],
            'create' => ['CREATE TABLE evil (id INT)'],
        ];
    }

    public function test_blocks_non_select_starting_sql(): void
    {
        $tool = new DatabaseTool(
            connection: $this->mockConn([]),
            identity: $this->buildIdentity('admin', true),
        );
        $result = $tool->execute(['sql' => 'SHOW TABLES']);
        $data = json_decode($result, true);

        self::assertFalse($data['success']);
        self::assertSame('BLOCKED_STATEMENT', $data['error']['code']);
    }

    public function test_input_schema_requires_sql(): void
    {
        $tool = new DatabaseTool($this->mockConn([]));

        self::assertContains('sql', $tool->inputSchema()['required']);
    }

    public function test_throws_when_no_connection(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessageMatches('/Connection/');

        $tool = new DatabaseTool(connection: null, identity: $this->buildIdentity('admin', true));
        $tool->execute(['sql' => 'SELECT 1']);
    }

    public function test_throws_when_query_execution_fails(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('iterateAssociative')
            ->willThrowException(new \RuntimeException('DB down'));

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('Query execution failed.');

        $tool = new DatabaseTool(connection: $conn, identity: $this->buildIdentity('admin', true));
        $tool->execute(['sql' => 'SELECT * FROM users']);
    }

    public function test_auto_adds_limit_when_not_present(): void
    {
        $conn = $this->createMock(Connection::class);
        $captured = [];
        $conn->method('iterateAssociative')
            ->willReturnCallback(function (string $sql) use (&$captured): \Traversable {
                $captured[] = $sql;

                return new \ArrayIterator([]);
            });

        $tool = new DatabaseTool(connection: $conn, identity: $this->buildIdentity('admin', true));
        $tool->execute(['sql' => 'SELECT * FROM users']);
        $tool->execute(['sql' => 'SELECT * FROM users LIMIT 5']);

        self::assertStringContainsString('LIMIT 20', $captured[0], 'the default limit is appended when absent');
        self::assertStringContainsString('LIMIT 5', $captured[1]);
        self::assertStringNotContainsString('LIMIT 20', $captured[1], 'an explicit LIMIT must be left alone');
    }

    public function test_large_output_is_truncated(): void
    {
        $rows = array_fill(0, 30, ['col' => str_repeat('x', 300)]);

        $conn = $this->createMock(Connection::class);
        $conn->method('iterateAssociative')->willReturn(new \ArrayIterator($rows));

        $tool = new DatabaseTool(connection: $conn, identity: $this->buildIdentity('admin', true));
        $result = $tool->execute(['sql' => 'SELECT * FROM big_table LIMIT 100']);
        $data = json_decode($result, true);

        self::assertTrue($data['success']);
        self::assertTrue($data['meta']['truncated']);
        self::assertSame(30, $data['meta']['total']);
        self::assertLessThan(30, count($data['data']['rows']));

        $warningCodes = array_column($data['warnings'], 'code');
        self::assertContains('OUTPUT_TRUNCATED', $warningCodes);
    }

    #[DataProvider('mutationPayloads')]
    public function test_sql_guard_blocks_mutation_payloads(string $key, string $payload): void
    {
        $reached = false;

        $conn = $this->createMock(Connection::class);
        $conn->method('iterateAssociative')
            ->willReturnCallback(function () use (&$reached): \Traversable {
                $reached = true;

                return new \ArrayIterator([]);
            });

        $tool = new DatabaseTool(connection: $conn, identity: $this->buildIdentity('admin', true));
        $result = $tool->execute(['sql' => $payload]);
        $data = json_decode($result, true);

        self::assertFalse($reached, "BYPASSED: {$key}");
        self::assertFalse($data['success']);
    }

    public static function mutationPayloads(): array
    {
        return [
            'stacked_drop' => ['stacked_drop',   'SELECT 1; DROP TABLE users'],
            'stacked_delete' => ['stacked_delete',  'SELECT 1;DELETE FROM users'],
            'into_outfile' => ['into_outfile',    "SELECT * FROM users INTO OUTFILE '/tmp/p'"],
            'into_dumpfile' => ['into_dumpfile',   "SELECT * FROM users INTO DUMPFILE '/tmp/p'"],
            'into_split' => ['into_split',      "SELECT 1 INTO/**/OUTFILE '/tmp/p'"],
            'into_bang' => ['into_bang',       "SELECT 1 /*!INTO OUTFILE '/tmp/p'*/"],
            'bang_drop' => ['bang_drop',       'SELECT * FROM users /*!12345 DROP TABLE users */'],
            'dashdash_nl' => ['dashdash_nl',     "SELECT 1 --\nDROP TABLE users"],
            'hash_nl' => ['hash_nl',         "SELECT 1 #\nDROP TABLE users"],
            'nested_comment' => ['nested_comment',  'SELECT 1 /*/**/DROP TABLE users*/'],
            'stacked_bang' => ['stacked_bang',    'SELECT 1;/*! DROP TABLE users */'],
            'ws_lead' => ['ws_lead',         "\n\t  SELECT 1; DROP TABLE users"],
            'fullwidth' => ['fullwidth',       'ＳＥＬＥＣＴ 1; DROP TABLE users'],
            'zerowidth' => ['zerowidth',       "\u{200B}SELECT 1; DROP TABLE users"],
            'cte_delete' => ['cte_delete',      'WITH x AS (SELECT 1) DELETE FROM users'],
            'union_outfile' => ['union_outfile',   "SELECT 1 UNION SELECT * FROM users INTO OUTFILE '/tmp/p'"],
            'paren_stacked' => ['paren_stacked',   '((SELECT 1)); DROP TABLE users'],
            'case_mixed' => ['case_mixed',      'SeLeCt 1; DrOp TABLE users'],
        ];
    }

    public function test_fetches_via_guard_sql_not_raw_input(): void
    {
        $capturedSql = null;
        $conn = $this->createMock(Connection::class);
        $conn->method('iterateAssociative')
            ->willReturnCallback(function (string $sql) use (&$capturedSql): \Traversable {
                $capturedSql = $sql;

                return new \ArrayIterator([]);
            });

        $rawInput = 'SELECT id FROM users';
        $tool = new DatabaseTool(connection: $conn, identity: $this->buildIdentity('admin', true));
        $tool->execute(['sql' => $rawInput]);

        self::assertIsString($capturedSql, 'iterateAssociative must have been called');
        self::assertNotSame($rawInput, $capturedSql, 'iterateAssociative must not receive raw $sql');
        self::assertStringStartsWith($rawInput, $capturedSql);
        self::assertStringEndsWith('LIMIT 20', $capturedSql);
    }

    public function test_success_envelope_shape(): void
    {
        $tool = new DatabaseTool(
            connection: $this->mockConn([['id' => 1]]),
            identity: $this->buildIdentity('admin', true),
        );
        $result = $tool->execute(['sql' => 'SELECT id FROM items LIMIT 1']);
        $data = json_decode($result, true);

        self::assertArrayHasKey('success', $data);
        self::assertArrayHasKey('data', $data);
        self::assertArrayHasKey('meta', $data);
        self::assertArrayHasKey('warnings', $data);
        self::assertTrue($data['success']);
    }

    public function test_forbidden_without_manage_all_role(): void
    {
        $tool = new DatabaseTool(
            connection: $this->mockConn([]),
            identity: $this->buildIdentity('user1', false),
        );
        $result = $tool->execute(['sql' => 'SELECT 1']);
        $data = json_decode($result, true);

        self::assertFalse($data['success']);
        self::assertSame('FORBIDDEN', $data['error']['code']);
    }

    public function test_manage_all_role_reaches_query(): void
    {
        $tool = new DatabaseTool(
            connection: $this->mockConn([['n' => 1]]),
            identity: $this->buildIdentity('admin', true),
        );
        $result = $tool->execute(['sql' => 'SELECT 1 AS n LIMIT 1']);
        $data = json_decode($result, true);

        self::assertTrue($data['success']);
        self::assertSame(1, $data['meta']['count']);
    }

    public function test_forbidden_without_identity_resolver(): void
    {
        $tool = new DatabaseTool(connection: $this->mockConn([]));
        $result = $tool->execute(['sql' => 'SELECT 1']);
        $data = json_decode($result, true);

        self::assertFalse($data['success']);
        self::assertSame('FORBIDDEN', $data['error']['code']);
    }

    public function test_require_chat_role_not_sufficient_for_database_tool(): void
    {
        $tool = new DatabaseTool(
            connection: $this->mockConn([]),
            identity: $this->buildIdentity('user1', false),
            requireChatRole: true,
        );
        $result = $tool->execute(['sql' => 'SELECT 1']);
        $data = json_decode($result, true);

        self::assertFalse($data['success']);
        self::assertSame('FORBIDDEN', $data['error']['code']);
    }

    public function test_blocked_table_returns_blocked_identifier(): void
    {
        $tool = new DatabaseTool(
            connection: $this->mockConn([]),
            identity: $this->buildIdentity('admin', true),
        );
        $result = $tool->execute(['sql' => 'SELECT * FROM phpclaw_conversations LIMIT 10']);
        $data = json_decode($result, true);

        self::assertFalse($data['success']);
        self::assertSame('BLOCKED_IDENTIFIER', $data['error']['code']);
        self::assertArrayHasKey('blocked_tables', $data['error']);
    }

    public function test_non_select_returns_blocked_statement(): void
    {
        $tool = new DatabaseTool(
            connection: $this->mockConn([]),
            identity: $this->buildIdentity('admin', true),
        );
        $result = $tool->execute(['sql' => 'INSERT INTO users (name) VALUES ("test")']);
        $data = json_decode($result, true);

        self::assertFalse($data['success']);
        self::assertSame('BLOCKED_STATEMENT', $data['error']['code']);
    }

    private function mockConn(array $rows): Connection
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('iterateAssociative')->willReturn(new \ArrayIterator($rows));

        return $conn;
    }
}

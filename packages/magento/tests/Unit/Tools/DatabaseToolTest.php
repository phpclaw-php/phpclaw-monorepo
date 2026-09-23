<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Tools;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PhpClaw\Magento\Model\IdentityResolver;
use PhpClaw\Magento\Tools\DatabaseTool;
use PhpClaw\SqlGuard\SqlDialect;
use PhpClaw\SqlGuard\SqlReadOnlyGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DatabaseToolTest extends TestCase
{
    private AdapterInterface&MockObject $connection;

    private ResourceConnection&MockObject $resourceConnection;

    private DatabaseTool $tool;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);

        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->resourceConnection->method('getConnection')->willReturn($this->connection);

        $this->tool = $this->toolWith($this->resourceConnection, allowed: false, console: true);
    }

    private function toolWith(ResourceConnection $rc, bool $allowed = true, bool $console = false): DatabaseTool
    {
        $identity = $this->createMock(IdentityResolver::class);
        $identity->method('runningInConsole')->willReturn($console);

        $acl = $this->createMock(AuthorizationInterface::class);
        $acl->method('isAllowed')->willReturnCallback(
            static fn (string $resource): bool => $allowed && $resource === 'PhpClaw_Magento::phpclaw_chat',
        );

        return new DatabaseTool($rc, $identity, $acl);
    }

    private function envelope(string $result): array
    {
        $decoded = json_decode($result, true);

        self::assertIsArray($decoded, 'the tool must return a JSON envelope');

        return $decoded;
    }

    private function rowsOf(string $result): array
    {
        $envelope = $this->envelope($result);

        self::assertTrue($envelope['success'], 'expected a success envelope');

        return $envelope['data']['rows'];
    }

    private function errorCode(string $result): string
    {
        $envelope = $this->envelope($result);

        self::assertFalse($envelope['success'], 'expected an error envelope');

        return $envelope['error']['code'];
    }

    public function test_name_is_db_query(): void
    {
        self::assertSame('db_query', $this->tool->name());
    }

    public function test_required_capability_is_the_chat_resource(): void
    {
        self::assertSame('PhpClaw_Magento::phpclaw_chat', $this->tool->requiredCapability());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        self::assertStringContainsString('read-only SQL SELECT against the Magento database', $this->tool->description());
        self::assertStringContainsString('never describe SQL, run it', $this->tool->description());
    }

    public function test_input_schema_requires_query(): void
    {
        self::assertContains('query', $this->tool->inputSchema()['required']);
    }

    public function test_select_query_is_executed_and_returns_rows(): void
    {
        $this->connection->method('fetchAll')->willReturn([
            ['id' => 1, 'name' => 'Widget'],
            ['id' => 2, 'name' => 'Gadget'],
        ]);

        $rows = $this->rowsOf($this->tool->execute(['query' => 'SELECT id, name FROM catalog_product_entity']));

        self::assertCount(2, $rows);
        self::assertSame('Widget', $rows[0]['name']);
    }

    public function test_blocked_keyword_inside_string_literal_is_allowed(): void
    {
        $this->connection->method('fetchAll')->willReturn([]);

        $rows = $this->rowsOf($this->tool->execute([
            'query' => "SELECT id FROM sales_order WHERE comment = 'please merge WITH care'",
        ]));

        self::assertSame([], $rows);
    }

    public function test_bindings_are_passed_to_fetchall(): void
    {
        $capturedBindings = null;
        $this->connection->method('fetchAll')
            ->willReturnCallback(function (string $sql, array $bindings) use (&$capturedBindings): array {
                $capturedBindings = $bindings;

                return [];
            });

        $this->tool->execute(['query' => 'SELECT * FROM sales_order WHERE status = ?', 'bindings' => ['pending']]);

        self::assertSame(['pending'], $capturedBindings);
    }

    public function test_limit_is_appended_when_missing(): void
    {
        $capturedSql = null;
        $this->connection->method('fetchAll')
            ->willReturnCallback(function (string $sql) use (&$capturedSql): array {
                $capturedSql = $sql;

                return [];
            });

        $this->tool->execute(['query' => 'SELECT * FROM sales_order']);

        self::assertStringContainsString('LIMIT', strtoupper((string) $capturedSql));
    }

    public function test_limit_is_not_duplicated_when_already_present(): void
    {
        $capturedSql = null;
        $this->connection->method('fetchAll')
            ->willReturnCallback(function (string $sql) use (&$capturedSql): array {
                $capturedSql = $sql;

                return [];
            });

        $this->tool->execute(['query' => 'SELECT * FROM catalog_product_entity LIMIT 10']);

        self::assertSame(1, substr_count(strtoupper((string) $capturedSql), 'LIMIT'));
    }

    public function test_insert_is_blocked(): void
    {
        self::assertSame('SQL_REJECTED', $this->errorCode($this->tool->execute(['query' => 'INSERT INTO sales_order VALUES (1)'])));
    }

    public function test_update_is_blocked(): void
    {
        self::assertSame('SQL_REJECTED', $this->errorCode($this->tool->execute(['query' => 'UPDATE sales_order SET status = "complete"'])));
    }

    public function test_delete_is_blocked(): void
    {
        self::assertSame('SQL_REJECTED', $this->errorCode($this->tool->execute(['query' => 'DELETE FROM catalog_product_entity WHERE entity_id = 1'])));
    }

    public function test_drop_is_blocked(): void
    {
        self::assertSame('SQL_REJECTED', $this->errorCode($this->tool->execute(['query' => 'DROP TABLE sales_order'])));
    }

    public function test_union_select_is_allowed(): void
    {
        $reached = false;
        $this->connection->method('fetchAll')
            ->willReturnCallback(static function () use (&$reached): array {
                $reached = true;

                return [];
            });

        $this->tool->execute(['query' => 'SELECT id FROM sales_order UNION SELECT id FROM catalog_product_entity']);

        self::assertTrue($reached, 'fetchAll must be reached: UNION SELECT is a permitted read shape');
    }

    public function test_with_cte_select_is_allowed(): void
    {
        $reached = false;
        $this->connection->method('fetchAll')
            ->willReturnCallback(static function () use (&$reached): array {
                $reached = true;

                return [];
            });

        $this->tool->execute(['query' => 'WITH cte AS (SELECT 1) SELECT * FROM cte']);

        self::assertTrue($reached, 'fetchAll must be reached: WITH CTE SELECT is a permitted read shape');
    }

    public function test_empty_query_returns_a_missing_argument_envelope(): void
    {
        self::assertSame('MISSING_ARGUMENT', $this->errorCode($this->tool->execute(['query' => ''])));
    }

    public function test_missing_query_returns_a_missing_argument_envelope(): void
    {
        self::assertSame('MISSING_ARGUMENT', $this->errorCode($this->tool->execute([])));
    }

    public function test_an_unknown_argument_is_rejected(): void
    {
        self::assertSame('UNKNOWN_ARGUMENT', $this->errorCode($this->tool->execute(['query' => 'SELECT 1', 'nope' => 1])));
    }

    public function test_output_is_capped_and_reported_in_meta(): void
    {
        $rows = [];
        for ($i = 0; $i < 200; $i++) {
            $rows[] = ['id' => $i, 'data' => str_repeat('x', 100)];
        }

        $this->connection->method('fetchAll')->willReturn($rows);

        $envelope = $this->envelope($this->tool->execute(['query' => 'SELECT * FROM some_table']));

        self::assertTrue($envelope['meta']['truncated']);
        self::assertSame(200, $envelope['meta']['total']);
        self::assertLessThan(200, $envelope['meta']['shown']);
        self::assertSame(array_slice($rows, 0, $envelope['meta']['shown']), $envelope['data']['rows']);
    }

    public function test_array_binding_returns_an_invalid_argument_envelope(): void
    {
        self::assertSame('INVALID_ARGUMENT', $this->errorCode($this->tool->execute([
            'query' => 'SELECT * FROM sales_order WHERE status = ?',
            'bindings' => [['nested', 'array']],
        ])));
    }

    public function test_apply_limit_ignores_limi_t_keyword_inside_string_literal(): void
    {
        $capturedSql = null;
        $this->connection->method('fetchAll')
            ->willReturnCallback(function (string $sql) use (&$capturedSql): array {
                $capturedSql = $sql;

                return [];
            });

        $this->tool->execute(['query' => "SELECT id FROM products WHERE name = 'NO LIMIT'"]);

        self::assertStringContainsString('LIMIT 500', strtoupper((string) $capturedSql));
    }

    public function test_fetchall_receives_safe_sql_not_raw_query(): void
    {
        $capturedSql = null;
        $this->connection->method('fetchAll')
            ->willReturnCallback(function (string $sql) use (&$capturedSql): array {
                $capturedSql = $sql;

                return [];
            });

        $rawQuery = 'SELECT * FROM sales_order';
        $this->tool->execute(['query' => $rawQuery]);

        $expected = (new SqlReadOnlyGuard(SqlDialect::MySql))->validate($rawQuery)->withLimit(500)->sql();

        self::assertSame($expected, $capturedSql, 'fetchAll must receive $safe->sql(), not raw $query');
    }

    /**
     * @dataProvider mutationPayloads
     */
    public function test_sql_guard_blocks_mutation_payload(string $key, string $payload): void
    {
        $reached = false;

        $spy = $this->createMock(AdapterInterface::class);
        $spy->method('fetchAll')->willReturnCallback(static function () use (&$reached): array {
            $reached = true;

            return [];
        });

        $rc = $this->createMock(ResourceConnection::class);
        $rc->method('getConnection')->willReturn($spy);

        $code = $this->errorCode($this->toolWith($rc, allowed: false, console: true)->execute(['query' => $payload]));

        self::assertFalse($reached, "BYPASSED: {$key}");
        self::assertSame('SQL_REJECTED', $code, "Expected SQL_REJECTED for: {$key}");
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

    public function test_an_admin_without_the_settings_resource_is_forbidden(): void
    {
        $code = $this->errorCode(
            $this->toolWith($this->resourceConnection, allowed: false, console: false)->execute(['query' => 'SELECT 1']),
        );

        self::assertSame('FORBIDDEN', $code);
    }

    public function test_the_forbidden_message_names_the_chat_resource(): void
    {
        $envelope = $this->envelope(
            $this->toolWith($this->resourceConnection, allowed: false, console: false)->execute(['query' => 'SELECT 1']),
        );

        self::assertStringContainsString('PhpClaw_Magento::phpclaw_chat', $envelope['error']['message']);
    }

    public function test_a_caller_holding_the_chat_resource_is_allowed(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('fetchAll')->willReturn([['one' => 1]]);

        $rc = $this->createMock(ResourceConnection::class);
        $rc->method('getConnection')->willReturn($connection);
        $rc->method('getTableName')->willReturnCallback(static fn (string $t): string => 'mage_'.$t);

        $rows = $this->rowsOf($this->toolWith($rc, allowed: true, console: false)->execute(['query' => 'SELECT 1 AS one']));

        self::assertSame([['one' => 1]], $rows);
    }

    public function test_the_console_reaches_the_tool_without_the_settings_resource(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('fetchAll')->willReturn([['one' => 1]]);

        $rc = $this->createMock(ResourceConnection::class);
        $rc->method('getConnection')->willReturn($connection);
        $rc->method('getTableName')->willReturnCallback(static fn (string $t): string => 'mage_'.$t);

        $rows = $this->rowsOf($this->toolWith($rc, allowed: false, console: true)->execute(['query' => 'SELECT 1 AS one']));

        self::assertSame([['one' => 1]], $rows);
    }

    public function test_the_config_table_is_refused_and_says_no_tool_replaces_it(): void
    {
        $rc = $this->createMock(ResourceConnection::class);
        $rc->method('getConnection')->willReturn($this->connection);
        $rc->method('getTableName')->willReturnCallback(static fn (string $t): string => 'mage_'.$t);

        $envelope = $this->envelope(
            $this->toolWith($rc, allowed: false, console: true)->execute(['query' => 'SELECT * FROM mage_core_config_data']),
        );

        self::assertFalse($envelope['success']);
        self::assertSame('RESTRICTED_IDENTIFIER', $envelope['error']['code']);
        self::assertStringContainsString('mage_core_config_data', $envelope['error']['message']);
        self::assertStringContainsString('phpClaw tool that reads it', $envelope['error']['message']);
    }

    public function test_a_credential_column_is_refused(): void
    {
        $rc = $this->createMock(ResourceConnection::class);
        $rc->method('getConnection')->willReturn($this->connection);
        $rc->method('getTableName')->willReturnCallback(static fn (string $t): string => 'mage_'.$t);

        $code = $this->errorCode(
            $this->toolWith($rc, allowed: false, console: true)->execute(['query' => 'SELECT password FROM admin_user']),
        );

        self::assertSame('RESTRICTED_IDENTIFIER', $code);
    }
}

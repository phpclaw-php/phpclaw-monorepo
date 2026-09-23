<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tests\Unit\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\OpenCart\Contracts\OcDbInterface;
use PhpClaw\OpenCart\Tests\Helpers\OcDbTestCase;
use PhpClaw\OpenCart\Tools\DatabaseTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class DatabaseToolTest extends OcDbTestCase
{
    private DatabaseTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $orderTable = $this->prefix.'order';

        $this->resetTables([
            "CREATE TABLE IF NOT EXISTS `{$orderTable}` (
                order_id INT NOT NULL AUTO_INCREMENT,
                total    DECIMAL(15,4) NOT NULL DEFAULT 0,
                status   TEXT          NOT NULL,
                PRIMARY KEY (order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ]);

        $this->seed("INSERT INTO `{$orderTable}` (order_id, total, status) VALUES (1, 99.99, 'Complete')");
        $this->seed("INSERT INTO `{$orderTable}` (order_id, total, status) VALUES (2, 14.50, 'Pending')");

        $this->tool = new DatabaseTool($this->db, $this->prefix, callerMayUseModule: true, mayQueryRaw: true);
    }

    public function test_name(): void
    {
        self::assertSame('db_query', $this->tool->name());
    }

    public function test_description_contains_table_prefix(): void
    {
        self::assertStringContainsString($this->prefix, $this->tool->description());
    }

    public function test_input_schema_has_sql_property(): void
    {
        $schema = $this->tool->inputSchema();
        self::assertArrayHasKey('sql', $schema['properties']);
        self::assertSame([], $schema['required']);
    }

    public function test_select_returns_rows(): void
    {
        $orderTable = $this->prefix.'order';
        $result = $this->tool->execute(['sql' => "SELECT * FROM `{$orderTable}`"]);
        $data = json_decode($result, true);
        $rows = $data['data']['rows'] ?? [];

        self::assertCount(2, $rows);
        self::assertSame(1, (int) $rows[0]['order_id']);
    }

    public function test_select_with_bindings(): void
    {
        $orderTable = $this->prefix.'order';
        $result = $this->tool->execute([
            'sql' => "SELECT * FROM `{$orderTable}` WHERE status = ?",
            'bindings' => ['Pending'],
        ]);
        $data = json_decode($result, true);
        $rows = $data['data']['rows'] ?? [];

        self::assertCount(1, $rows);
        self::assertSame('Pending', $rows[0]['status']);
    }

    public function test_appends_limit_when_absent(): void
    {
        $orderTable = $this->prefix.'order';
        $this->seedOrders(250);

        $result = $this->tool->execute(['sql' => "SELECT order_id FROM `{$orderTable}`"]);

        $decoded = json_decode($result, true);

        self::assertSame(
            200,
            $decoded['meta']['total'],
            'a query with no LIMIT must be capped at the 200 auto-limit',
        );
    }

    public function test_returns_valid_json(): void
    {
        $result = $this->tool->execute(['sql' => 'SELECT 1 AS n']);

        self::assertNotFalse(json_decode($result));
        self::assertSame(JSON_ERROR_NONE, json_last_error());
    }

    public function test_blocks_insert(): void
    {
        $orderTable = $this->prefix.'order';
        $this->expectException(ToolException::class);
        $this->tool->execute(['sql' => "INSERT INTO `{$orderTable}` VALUES (3, 0, 'x')"]);
    }

    public function test_blocks_update(): void
    {
        $orderTable = $this->prefix.'order';
        $this->expectException(ToolException::class);
        $this->tool->execute(['sql' => "UPDATE `{$orderTable}` SET total=0 WHERE order_id=1"]);
    }

    public function test_blocks_restricted_credential_identifier(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessageMatches('/restricted table or column/');
        $this->tool->execute(['sql' => "SELECT password FROM `{$this->prefix}user`"]);
    }

    public function test_blocks_restricted_salt_identifier(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessageMatches('/restricted table or column/');
        $this->tool->execute(['sql' => "SELECT salt FROM `{$this->prefix}customer`"]);
    }

    public function test_blocks_delete(): void
    {
        $orderTable = $this->prefix.'order';
        $this->expectException(ToolException::class);
        $this->tool->execute(['sql' => "DELETE FROM `{$orderTable}` WHERE 1=1"]);
    }

    public function test_blocks_drop(): void
    {
        $orderTable = $this->prefix.'order';
        $this->expectException(ToolException::class);
        $this->tool->execute(['sql' => "DROP TABLE `{$orderTable}`"]);
    }

    public function test_allows_union(): void
    {
        $spy = new \stdClass;
        $spy->reached = false;

        $spyDb = new class($spy) implements OcDbInterface
        {
            public function __construct(private \stdClass $spy) {}

            public function query(string $sql, array $params = []): object
            {
                $this->spy->reached = true;
                $stub = new \stdClass;
                $stub->rows = [];

                return $stub;
            }

            public function escape(string $value): string
            {
                return $value;
            }
        };

        $tool = new DatabaseTool($spyDb, 'oc_', callerMayUseModule: true, mayQueryRaw: true);
        $tool->execute(['sql' => 'SELECT 1 UNION SELECT 2']);

        self::assertTrue($spy->reached, 'Guard rejected a safe UNION read query.');
    }

    public function test_allows_union_case_insensitive(): void
    {
        $spy = new \stdClass;
        $spy->reached = false;

        $spyDb = new class($spy) implements OcDbInterface
        {
            public function __construct(private \stdClass $spy) {}

            public function query(string $sql, array $params = []): object
            {
                $this->spy->reached = true;
                $stub = new \stdClass;
                $stub->rows = [];

                return $stub;
            }

            public function escape(string $value): string
            {
                return $value;
            }
        };

        $tool = new DatabaseTool($spyDb, 'oc_', callerMayUseModule: true, mayQueryRaw: true);
        $tool->execute(['sql' => 'SELECT 1 union SELECT 2']);

        self::assertTrue($spy->reached, 'Guard rejected a safe lowercase-union read query.');
    }

    public function test_allows_read_cte(): void
    {
        $spy = new \stdClass;
        $spy->reached = false;

        $spyDb = new class($spy) implements OcDbInterface
        {
            public function __construct(private \stdClass $spy) {}

            public function query(string $sql, array $params = []): object
            {
                $this->spy->reached = true;
                $stub = new \stdClass;
                $stub->rows = [];

                return $stub;
            }

            public function escape(string $value): string
            {
                return $value;
            }
        };

        $tool = new DatabaseTool($spyDb, 'oc_', callerMayUseModule: true, mayQueryRaw: true);
        $tool->execute(['sql' => 'WITH x AS (SELECT 1) SELECT * FROM x']);

        self::assertTrue($spy->reached, 'Guard rejected a safe WITH/CTE read query.');
    }

    public function test_allows_except(): void
    {
        $spy = new \stdClass;
        $spy->reached = false;

        $spyDb = new class($spy) implements OcDbInterface
        {
            public function __construct(private \stdClass $spy) {}

            public function query(string $sql, array $params = []): object
            {
                $this->spy->reached = true;
                $stub = new \stdClass;
                $stub->rows = [];

                return $stub;
            }

            public function escape(string $value): string
            {
                return $value;
            }
        };

        $tool = new DatabaseTool($spyDb, 'oc_', callerMayUseModule: true, mayQueryRaw: true);
        $tool->execute(['sql' => 'SELECT 1 EXCEPT SELECT 2']);

        self::assertTrue($spy->reached, 'Guard rejected a safe EXCEPT read query.');
    }

    public function test_rejects_non_scalar_bindings(): void
    {
        $orderTable = $this->prefix.'order';
        $this->expectException(ToolException::class);
        $this->tool->execute([
            'sql' => "SELECT * FROM `{$orderTable}` WHERE order_id = ?",
            'bindings' => [['nested', 'array']],
        ]);
    }

    public function test_throws_on_empty_query(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage("'sql' parameter is required");
        $this->tool->execute(['sql' => '']);
    }

    public function test_throws_when_db_is_null(): void
    {
        $tool = new DatabaseTool(null, $this->prefix, callerMayUseModule: true, mayQueryRaw: true);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('no database connection');

        $tool->execute(['sql' => 'SELECT 1']);
    }

    public function test_custom_limit_overrides_auto_limit(): void
    {
        $orderTable = $this->prefix.'order';
        $result = $this->tool->execute(['sql' => "SELECT order_id FROM `{$orderTable}`", 'limit' => 1]);
        $data = json_decode($result, true);
        self::assertCount(1, $data['data']['rows']);
    }

    public function test_limit_capped_at_max_1000(): void
    {
        $orderTable = $this->prefix.'order';
        $this->seedOrders(1100);

        $result = $this->tool->execute(['sql' => "SELECT order_id FROM `{$orderTable}`", 'limit' => 9999]);

        $decoded = json_decode($result, true);

        self::assertSame(
            1000,
            $decoded['meta']['total'],
            'an over-large limit must clamp to the 1000 maximum',
        );
    }

    private function seedOrders(int $upTo): void
    {
        $orderTable = $this->prefix.'order';
        $rows = [];
        for ($i = 3; $i <= $upTo; $i++) {
            $rows[] = "({$i}, 1.00, 'Complete')";
        }
        $this->seed("INSERT INTO `{$orderTable}` (order_id, total, status) VALUES ".implode(',', $rows));
    }

    public function test_blocks_truncate(): void
    {
        $orderTable = $this->prefix.'order';
        $this->expectException(ToolException::class);
        $this->tool->execute(['sql' => "TRUNCATE TABLE `{$orderTable}`"]);
    }

    public function test_blocks_alter(): void
    {
        $orderTable = $this->prefix.'order';
        $this->expectException(ToolException::class);
        $this->tool->execute(['sql' => "ALTER TABLE `{$orderTable}` ADD COLUMN x TEXT"]);
    }

    public function test_blocks_create(): void
    {
        $this->expectException(ToolException::class);
        $this->tool->execute(['sql' => 'CREATE TABLE foo (id INT)']);
    }

    public function test_allows_intersect(): void
    {
        $spy = new \stdClass;
        $spy->reached = false;

        $spyDb = new class($spy) implements OcDbInterface
        {
            public function __construct(private \stdClass $spy) {}

            public function query(string $sql, array $params = []): object
            {
                $this->spy->reached = true;
                $stub = new \stdClass;
                $stub->rows = [];

                return $stub;
            }

            public function escape(string $value): string
            {
                return $value;
            }
        };

        $tool = new DatabaseTool($spyDb, 'oc_', callerMayUseModule: true, mayQueryRaw: true);
        $tool->execute(['sql' => 'SELECT 1 INTERSECT SELECT 1']);

        self::assertTrue($spy->reached, 'Guard rejected a safe INTERSECT read query.');
    }

    public function test_blocks_rename(): void
    {
        $orderTable = $this->prefix.'order';
        $orderTable2 = $this->prefix.'order2';
        $this->expectException(ToolException::class);
        $this->tool->execute(['sql' => "RENAME TABLE `{$orderTable}` TO `{$orderTable2}`"]);
    }

    public function test_comment_stripped_union_is_still_blocked(): void
    {
        $this->expectException(ToolException::class);
        $this->tool->execute(['sql' => 'SELECT 1 /* comment */ UNION SELECT 2']);
    }

    public function test_blocks_into_outfile(): void
    {
        $orderTable = $this->prefix.'order';
        $this->expectException(ToolException::class);
        $this->tool->execute(['sql' => "SELECT * FROM `{$orderTable}` INTO OUTFILE '/tmp/phpclaw_test_exfil.txt'"]);
    }

    public function test_blocks_into_dumpfile(): void
    {
        $orderTable = $this->prefix.'order';
        $this->expectException(ToolException::class);
        $this->tool->execute(['sql' => "SELECT * FROM `{$orderTable}` INTO DUMPFILE '/tmp/phpclaw_test_exfil.txt'"]);
    }

    public function test_blocks_load_file(): void
    {
        $this->expectException(ToolException::class);
        $this->tool->execute(['sql' => "SELECT LOAD_FILE('/etc/passwd')"]);
    }

    public function test_schema_mode_returns_tables(): void
    {
        $decoded = json_decode($this->tool->execute(['schema' => true]), true);
        self::assertTrue($decoded['success']);
        self::assertIsArray($decoded['data']['tables'] ?? null);
        self::assertSame('schema', $decoded['meta']['mode']);
    }

    public function test_select_count(): void
    {
        $orderTable = $this->prefix.'order';
        $result = $this->tool->execute(['sql' => "SELECT COUNT(*) AS total FROM `{$orderTable}`"]);
        $data = json_decode($result, true);
        self::assertSame(2, (int) ($data['data']['rows'][0]['total'] ?? 0));
    }

    public function test_output_has_count_key(): void
    {
        $orderTable = $this->prefix.'order';
        $result = $this->tool->execute(['sql' => "SELECT * FROM `{$orderTable}`"]);
        $data = json_decode($result, true);
        self::assertArrayHasKey('total', $data['meta']);
        self::assertSame(2, $data['meta']['total']);
    }

    public function test_truncation_activates_at_8kb(): void
    {
        $orderTable = $this->prefix.'order';
        $longStatus = str_repeat('X', 500);
        for ($i = 3; $i <= 30; $i++) {
            $this->seed("INSERT INTO `{$orderTable}` (order_id, total, status) VALUES ($i, $i.00, '$longStatus')");
        }

        $decoded = json_decode($this->tool->execute(['sql' => "SELECT * FROM `{$orderTable}` LIMIT 1000"]), true);

        self::assertTrue($decoded['meta']['truncated']);
        self::assertLessThan($decoded['meta']['total'], $decoded['meta']['shown']);
    }

    public function test_bad_sql_throws_tool_exception(): void
    {
        $this->expectException(ToolException::class);
        $this->tool->execute(['sql' => 'SELECT * FROM nonexistent_table_xyz']);
    }

    public function test_constructor_accepts_oc_db_interface_not_pdo(): void
    {
        $param = (new \ReflectionClass(DatabaseTool::class))
            ->getConstructor()
            ?->getParameters()[0];

        self::assertNotNull($param);
        $type = $param->getType();
        self::assertInstanceOf(\ReflectionNamedType::class, $type);
        self::assertSame(OcDbInterface::class, $type->getName());
        self::assertTrue($type->allowsNull());
    }

    public function test_architecture_db_receives_safe_sql_not_raw_input(): void
    {
        $spy = new \stdClass;
        $spy->sql = null;

        $spyDb = new class($spy) implements OcDbInterface
        {
            public function __construct(private \stdClass $spy) {}

            public function query(string $sql, array $params = []): object
            {
                $this->spy->sql = $sql;
                $stub = new \stdClass;
                $stub->rows = [];

                return $stub;
            }

            public function escape(string $value): string
            {
                return $value;
            }
        };

        $tool = new DatabaseTool($spyDb, 'oc_', callerMayUseModule: true, mayQueryRaw: true);
        $rawSql = 'SELECT 1';
        $tool->execute(['sql' => $rawSql]);

        self::assertNotSame($rawSql, $spy->sql, 'DB received raw $sql instead of $safe->sql().');
        self::assertStringContainsString('LIMIT', (string) $spy->sql);
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
    public function test_mutation_payload_guard(string $payload): void
    {
        $spy = new \stdClass;
        $spy->reached = false;

        $spyDb = new class($spy) implements OcDbInterface
        {
            public function __construct(private \stdClass $spy) {}

            public function query(string $sql, array $params = []): object
            {
                $this->spy->reached = true;
                $stub = new \stdClass;
                $stub->rows = [];

                return $stub;
            }

            public function escape(string $value): string
            {
                return $value;
            }
        };

        $tool = new DatabaseTool($spyDb, 'oc_', callerMayUseModule: true, mayQueryRaw: true);
        $threw = false;

        try {
            $tool->execute(['sql' => $payload]);
        } catch (ToolException $e) {
            $threw = true;
        }

        $key = $this->dataName();

        self::assertFalse($spy->reached, "BYPASSED: {$key}");
        self::assertTrue($threw);
    }

    public function test_a_caller_without_the_module_grant_is_refused_before_the_raw_sql_gate(): void
    {
        $tool = new DatabaseTool($this->db, $this->prefix, callerMayUseModule: false, mayQueryRaw: false);

        $decoded = json_decode($tool->execute(['sql' => 'SELECT 1']), true);

        self::assertFalse($decoded['success']);
        self::assertSame('FORBIDDEN', $decoded['error']['code']);
    }

    public function test_a_module_caller_without_the_raw_sql_grant_is_refused_on_the_web_path(): void
    {
        $tool = new DatabaseTool($this->db, $this->prefix, callerMayUseModule: true, mayQueryRaw: false);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('phpClaw manage-all permission');

        $tool->execute(['sql' => 'SELECT 1']);
    }

    public function test_a_caller_holding_the_grant_is_allowed_on_the_web_path(): void
    {
        $tool = new DatabaseTool($this->db, $this->prefix, callerMayUseModule: false, mayQueryRaw: true);

        $decoded = json_decode($tool->execute(['sql' => 'SELECT 1 AS one']), true);

        self::assertIsArray($decoded);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_console_marker_exempts_a_caller_holding_neither_grant(): void
    {
        define('PHPCLAW_OC_CONSOLE', true);

        $tool = new DatabaseTool($this->db, $this->prefix, callerMayUseModule: false, mayQueryRaw: false);

        $decoded = json_decode($tool->execute(['sql' => 'SELECT 1 AS one']), true);

        self::assertTrue($decoded['success'], 'The console marker exempts both the module gate and the raw-SQL gate.');
    }

    public function test_the_setting_table_is_refused_and_says_no_tool_replaces_it(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('OpenCart ships no tool that reads it');

        $this->tool->execute(['sql' => 'SELECT `key`, value FROM '.$this->prefix.'setting']);
    }

    public function test_the_setting_table_is_refused_under_any_prefix(): void
    {
        $tool = new DatabaseTool($this->db, 'xyz_', callerMayUseModule: true, mayQueryRaw: true);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('xyz_setting');

        $tool->execute(['sql' => 'SELECT value FROM xyz_setting']);
    }

    public function test_a_table_whose_name_merely_ends_in_setting_is_not_refused(): void
    {
        $decoded = json_decode($this->tool->execute(['sql' => 'SELECT 1 AS one']), true);

        self::assertIsArray($decoded, 'the negative control must still answer');

        $tool = new DatabaseTool($this->db, $this->prefix, callerMayUseModule: true, mayQueryRaw: true);

        try {
            $tool->execute(['sql' => 'SELECT id FROM '.$this->prefix.'myextension_setting']);
            $refused = false;
        } catch (ToolException $e) {
            $refused = str_contains($e->getMessage(), 'holds extension settings');
        }

        self::assertFalse($refused, 'oc_myextension_setting is a different table and must not be blocked by the setting rule');
    }

    #[DataProvider('underscoreJoinedCredentialIdentifiers')]
    public function test_a_restricted_word_joined_by_an_underscore_is_now_blocked(string $column): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('restricted table or column');

        $this->tool->execute(['sql' => "SELECT {$column} FROM ".$this->prefix.'product']);
    }

    public static function underscoreJoinedCredentialIdentifiers(): array
    {
        return [
            ['mailserver_password'],
            ['user_password'],
            ['smtp_passwd'],
            ['stripe_secret'],
            ['customer_api_key'],
        ];
    }
}

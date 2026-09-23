<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\PrestaShop\Tests\Helpers\AssertsToolEnvelope;
use PhpClaw\PrestaShop\Tests\Helpers\PsDbTestCase;
use PhpClaw\PrestaShop\Tools\DatabaseTool;
use PhpClaw\PrestaShop\Tools\ToolOutputEncoder;
use PHPUnit\Framework\Attributes\DataProvider;

final class DatabaseToolTest extends PsDbTestCase
{
    use AssertsToolEnvelope;

    private DatabaseTool $tool;

    private string $testTable;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testTable = $this->prefix.'test_table';

        $this->resetTables([
            "CREATE TABLE IF NOT EXISTS `{$this->testTable}` (
                id   INT          NOT NULL AUTO_INCREMENT,
                name VARCHAR(64)  NOT NULL DEFAULT '',
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ]);

        $this->seed("INSERT INTO `{$this->testTable}` (id,name) VALUES (1,'alpha')");
        $this->seed("INSERT INTO `{$this->testTable}` (id,name) VALUES (2,'beta')");

        $this->tool = new DatabaseTool($this->db, $this->prefix, isConsole: true);
    }

    public function test_name_returns_database(): void
    {
        self::assertSame('database', $this->tool->name());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        self::assertStringContainsString(
            'read-only SQL SELECT against the PrestaShop datab',
            $this->tool->description(),
        );
    }

    public function test_description_contains_table_prefix(): void
    {
        self::assertStringContainsString($this->prefix, $this->tool->description());
    }

    public function test_execute_select_returns_json(): void
    {
        $rows = $this->rows($this->tool->execute(['sql' => "SELECT * FROM `{$this->testTable}`"]));

        self::assertSame(['alpha', 'beta'], array_column($rows, 'name'));
    }

    public function test_execute_select_returns_rows(): void
    {
        $rows = $this->rows($this->tool->execute(['sql' => "SELECT * FROM `{$this->testTable}`"]));

        self::assertCount(2, $rows);
    }

    public function test_execute_blocks_non_select_queries(): void
    {
        $this->expectException(ToolException::class);
        $this->tool->execute(['sql' => "DROP TABLE `{$this->testTable}`"]);
    }

    public function test_execute_blocks_delete_queries(): void
    {
        $this->expectException(ToolException::class);
        $this->tool->execute(['sql' => "DELETE FROM `{$this->testTable}`"]);
    }

    public function test_execute_blocks_insert_queries(): void
    {
        $this->expectException(ToolException::class);
        $this->tool->execute(['sql' => "INSERT INTO `{$this->testTable}` VALUES (3,'gamma')"]);
    }

    public function test_execute_throws_on_empty_sql(): void
    {
        $this->expectException(ToolException::class);
        $this->tool->execute(['sql' => '']);
    }

    public function test_execute_throws_on_null_db(): void
    {
        $tool = new DatabaseTool(null, $this->prefix, isConsole: true);

        $this->expectException(ToolException::class);
        $tool->execute(['sql' => 'SELECT 1']);
    }

    public function test_execute_schema_mode_returns_tables(): void
    {
        $rows = $this->rows($this->tool->execute(['schema' => true]));

        self::assertNotEmpty($rows);
        self::assertArrayHasKey('table', $rows[0]);
    }

    public function test_execute_schema_mode_requires_db(): void
    {
        $tool = new DatabaseTool(null, $this->prefix, isConsole: true);

        $this->expectException(ToolException::class);
        $tool->execute(['schema' => true]);
    }

    public function test_execute_with_bindings(): void
    {
        $rows = $this->rows($this->tool->execute([
            'sql' => "SELECT * FROM `{$this->testTable}` WHERE id = ?",
            'bindings' => [1],
        ]));

        self::assertCount(1, $rows);
        self::assertSame('alpha', $rows[0]['name']);
    }

    public function test_execute_auto_limit_appended(): void
    {
        $appended = $this->rows($this->tool->execute([
            'sql' => "SELECT * FROM `{$this->testTable}`",
            'limit' => 1,
        ]));

        $ownLimit = $this->rows($this->tool->execute([
            'sql' => "SELECT * FROM `{$this->testTable}` LIMIT 2",
            'limit' => 1,
        ]));

        self::assertCount(1, $appended);
        self::assertCount(2, $ownLimit);
    }

    public function test_execute_with_existing_limit_clause(): void
    {
        $rows = $this->rows($this->tool->execute(['sql' => "SELECT * FROM `{$this->testTable}` LIMIT 1"]));

        self::assertCount(1, $rows);
    }

    public function test_execute_blocks_update_keyword(): void
    {
        $this->expectException(ToolException::class);
        $this->tool->execute(['sql' => "SELECT * FROM `{$this->testTable}` WHERE UPDATE = 1"]);
    }

    public function test_execute_allows_read_union_select(): void
    {
        $rows = $this->rows($this->tool->execute(['sql' => 'SELECT 1 AS n UNION SELECT 2']));

        self::assertSame(['1', '2'], array_map('strval', array_column($rows, 'n')));
    }

    public function test_execute_allows_cte_with_select(): void
    {
        $rows = $this->rows($this->tool->execute([
            'sql' => 'WITH cte AS (SELECT 1 AS n) SELECT * FROM cte',
        ]));

        self::assertSame(['1'], array_map('strval', array_column($rows, 'n')));
    }

    public function test_execute_invalid_binding_throws(): void
    {
        $this->expectException(ToolException::class);
        $this->tool->execute([
            'sql' => "SELECT * FROM `{$this->testTable}` WHERE id = ?",
            'bindings' => [['nested' => 'array']],
        ]);
    }

    public function test_execute_custom_limit_param(): void
    {
        $rows = $this->rows($this->tool->execute([
            'sql' => "SELECT * FROM `{$this->testTable}`",
            'limit' => 1,
        ]));

        self::assertCount(1, $rows);
    }

    public function test_execute_query_key_alias(): void
    {
        $rows = $this->rows($this->tool->execute(['query' => "SELECT * FROM `{$this->testTable}`"]));

        self::assertCount(2, $rows);
    }

    public function test_a_capped_response_stays_decodable_and_inside_the_byte_budget(): void
    {
        $result = $this->tool->execute(['sql' => "SELECT * FROM `{$this->cappedTable()}` LIMIT 200"]);

        self::assertJson($result, 'A capped response must still decode as one JSON document.');
        self::assertLessThanOrEqual(
            ToolOutputEncoder::MAX_OUTPUT_BYTES,
            strlen($result),
            'A capped response must fit the output budget, envelope included.',
        );

        $meta = $this->meta($result);

        self::assertTrue($meta['truncated'], 'The seeded rows exceed the budget, so the cap must fire.');
        self::assertLessThan($meta['total'], $meta['shown']);
        self::assertContains('OUTPUT_TRUNCATED', $this->warningCodes($result));
    }

    public function test_the_truncation_notice_travels_inside_the_document(): void
    {
        $result = $this->tool->execute(['sql' => "SELECT * FROM `{$this->cappedTable()}` LIMIT 200"]);

        $decoded = json_decode($result, true);

        self::assertIsArray($decoded, 'The notice must not be appended after the JSON.');
        self::assertSame(
            $decoded['warnings'][0]['code'] ?? null,
            'OUTPUT_TRUNCATED',
            'The truncation signal belongs inside the envelope, not beside it.',
        );
    }

    private function cappedTable(): string
    {
        $hugeTable = $this->prefix.'huge_table';

        $this->resetTables([
            "CREATE TABLE IF NOT EXISTS `{$hugeTable}` (
                id  INT          NOT NULL AUTO_INCREMENT,
                val VARCHAR(255) NOT NULL DEFAULT '',
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ]);

        $longVal = str_repeat('x', 200);

        for ($i = 1; $i <= 100; $i++) {
            $this->seed("INSERT INTO `{$hugeTable}` (val) VALUES ('{$longVal}')");
        }

        return $hugeTable;
    }

    public function test_multi_statement_with_drop_is_rejected(): void
    {
        $this->expectException(ToolException::class);
        $this->tool->execute(['sql' => "SELECT 1; DROP TABLE {$this->testTable}"]);
    }

    public function test_multi_statement_with_truncate_is_rejected(): void
    {
        $this->expectException(ToolException::class);
        $this->tool->execute(['sql' => "SELECT id FROM `{$this->testTable}`; TRUNCATE {$this->testTable}"]);
    }

    public function test_semicolon_inside_string_literal_is_allowed(): void
    {
        $rows = $this->rows($this->tool->execute([
            'sql' => "SELECT * FROM `{$this->testTable}` WHERE name = 'a;b'",
            'bindings' => [],
        ]));

        self::assertSame([], $rows);
    }

    public function test_drop_keyword_blocked_anywhere_in_query(): void
    {
        $this->expectException(ToolException::class);
        $this->tool->execute(['sql' => "SELECT DROP FROM `{$this->testTable}`"]);
    }

    public function test_truncate_keyword_blocked_anywhere_in_query(): void
    {
        $this->expectException(ToolException::class);
        $this->tool->execute(['sql' => "SELECT TRUNCATE(1,0) FROM `{$this->testTable}`"]);
    }

    public function test_create_keyword_blocked_anywhere_in_query(): void
    {
        $this->expectException(ToolException::class);
        $this->tool->execute(['sql' => "SELECT CREATE FROM `{$this->testTable}`"]);
    }

    public function test_rename_keyword_blocked_anywhere_in_query(): void
    {
        $this->expectException(ToolException::class);
        $this->tool->execute(['sql' => "SELECT RENAME FROM `{$this->testTable}`"]);
    }

    public function test_into_outfile_is_blocked(): void
    {
        $this->expectException(ToolException::class);
        $this->tool->execute(['sql' => "SELECT * FROM `{$this->testTable}` INTO OUTFILE '/tmp/x.txt'"]);
    }

    public function test_into_dumpfile_is_blocked(): void
    {
        $this->expectException(ToolException::class);
        $this->tool->execute(['sql' => "SELECT * FROM `{$this->testTable}` INTO DUMPFILE '/tmp/x.bin'"]);
    }

    public function test_load_file_function_is_blocked(): void
    {
        $this->expectException(ToolException::class);
        $this->tool->execute(['sql' => "SELECT LOAD_FILE('/etc/passwd')"]);
    }

    public function test_underscore_is_a_boundary_so_underscore_separated_superstrings_are_refused(): void
    {
        $allowed = ['xwholesale_price', 'wholesale_pricex'];
        $refused = ['note_wholesale_price', 'wholesale_price_note'];

        foreach ($allowed as $identifier) {
            $this->tool->execute(['sql' => "SELECT 1 AS {$identifier}"]);
        }

        foreach ($refused as $identifier) {
            try {
                $this->tool->execute(['sql' => "SELECT 1 AS {$identifier}"]);
                self::fail("{$identifier} was accepted; the underscore boundary has been widened.");
            } catch (ToolException $e) {
                self::assertStringContainsString('restricted table or column', $e->getMessage());
            }
        }

        try {
            $this->tool->execute(['sql' => "SELECT original_wholesale_price FROM `{$this->prefix}order_detail`"]);
            self::fail('original_wholesale_price was accepted; order-time cost basis is reachable again.');
        } catch (ToolException $e) {
            self::assertStringContainsString('restricted table or column', $e->getMessage());
        }
    }

    public function test_raw_sql_naming_product_cost_is_blocked(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessageMatches('/restricted table or column/');
        $this->tool->execute(['sql' => "SELECT wholesale_price FROM `{$this->prefix}product`"]);
    }

    public function test_raw_sql_naming_the_supplier_purchase_price_is_blocked(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessageMatches('/restricted table or column/');
        $this->tool->execute(['sql' => "SELECT product_supplier_price_te FROM `{$this->prefix}product_supplier`"]);
    }

    public function test_restricted_credential_identifier_is_blocked(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessageMatches('/restricted table or column/');
        $this->tool->execute(['sql' => "SELECT passwd FROM `{$this->prefix}employee`"]);
    }

    public function test_restricted_secure_key_identifier_is_blocked(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessageMatches('/restricted table or column/');
        $this->tool->execute(['sql' => "SELECT secure_key FROM `{$this->prefix}customer`"]);
    }

    public function test_normal_select_with_semicolon_only_at_end_is_allowed(): void
    {
        $rows = $this->rows($this->tool->execute([
            'sql' => "SELECT * FROM `{$this->testTable}` LIMIT 2;",
        ]));

        self::assertSame(['alpha', 'beta'], array_column($rows, 'name'));
    }

    public function test_a_caller_below_the_chat_tier_is_refused_by_the_tab_gate(): void
    {
        $tool = new DatabaseTool($this->db, $this->prefix, isConsole: false);

        self::assertSame(
            'FORBIDDEN',
            $this->errorCode($tool->execute(['sql' => "SELECT id FROM `{$this->testTable}`"])),
        );
    }

    public function test_a_chat_tier_employee_passes_the_tab_gate_and_is_stopped_by_the_raw_sql_gate(): void
    {
        $this->withChatTierEmployee(function (): void {
            $tool = new DatabaseTool($this->db, $this->prefix, isConsole: false);

            $this->expectException(ToolException::class);
            $this->expectExceptionMessage('requires a PrestaShop SuperAdmin employee');

            $tool->execute(['sql' => "SELECT id FROM `{$this->testTable}`"]);
        });
    }

    public function test_the_console_entrypoint_is_exempt_from_the_capability_check(): void
    {
        $tool = new DatabaseTool($this->db, $this->prefix, isConsole: true);

        self::assertCount(2, $this->rows($tool->execute(['sql' => "SELECT id FROM `{$this->testTable}` ORDER BY id"])));
    }

    public function test_the_configuration_table_is_refused_and_the_refusal_names_the_tool_that_answers(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('ps_config tool');

        $this->tool->execute(['sql' => 'SELECT name, value FROM '.$this->prefix.'configuration']);
    }

    public function test_the_configuration_table_is_refused_under_any_prefix(): void
    {
        $tool = new DatabaseTool($this->db, 'xyz_', isConsole: true);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('xyz_configuration');

        $tool->execute(['sql' => 'SELECT value FROM xyz_configuration']);
    }

    public function test_an_ordinary_business_table_still_answers(): void
    {
        $rows = $this->rows($this->tool->execute(['sql' => "SELECT id, name FROM `{$this->testTable}` ORDER BY id"]));

        self::assertNotSame([], $rows);
    }

    public function test_a_table_whose_name_merely_ends_in_configuration_is_not_refused(): void
    {
        $table = $this->prefix.'mymodule_configuration';

        $this->resetTables([
            "CREATE TABLE IF NOT EXISTS `{$table}` (id INT NOT NULL AUTO_INCREMENT, PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ]);

        self::assertSame([], $this->rows($this->tool->execute(['sql' => "SELECT id FROM `{$table}`"])));
    }

    #[DataProvider('underscoreJoinedCredentialIdentifiers')]
    public function test_a_restricted_word_joined_by_an_underscore_is_now_blocked(string $column): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('restricted table or column');

        $this->tool->execute(['sql' => "SELECT {$column} FROM `{$this->testTable}`"]);
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

    public function test_a_super_admin_is_allowed_on_the_web_path(): void
    {
        $this->withStubbedSuperAdmin(true, function (): void {
            $tool = new DatabaseTool($this->db, $this->prefix, isConsole: false);

            self::assertNotSame(
                [],
                $this->rows($tool->execute(['sql' => "SELECT id FROM `{$this->testTable}` ORDER BY id"])),
            );
        });
    }

    public function test_a_non_super_admin_is_refused_on_the_web_path(): void
    {
        $this->withStubbedSuperAdmin(false, function (): void {
            $tool = new DatabaseTool($this->db, $this->prefix, isConsole: false);

            self::assertSame(
                'FORBIDDEN',
                $this->errorCode($tool->execute(['sql' => "SELECT id FROM `{$this->testTable}`"])),
            );
        });
    }

    private function withChatTierEmployee(callable $body): void
    {
        $context = \Context::getContext();
        $previous = $context->employee ?? null;

        \Tab::$idsByClass['AdminPhpClawDebug'] = 7;
        \Profile::grant(4, 7, 'view');

        $employee = new \Employee;
        $employee->id = 42;
        $employee->id_profile = 4;
        $employee->superAdmin = false;

        $context->employee = $employee;

        try {
            $body();
        } finally {
            $context->employee = $previous;
            \Tab::reset();
            \Profile::reset();
        }
    }

    private function withStubbedSuperAdmin(bool $isSuperAdmin, callable $body): void
    {
        if (! class_exists(\Context::class)) {
            self::markTestSkipped('PrestaShop Context is not available in this environment.');
        }

        $context = \Context::getContext();
        $previous = $context->employee ?? null;

        $employee = new \Employee;
        $employee->id = 1;
        $employee->superAdmin = $isSuperAdmin;

        $context->employee = $employee;

        try {
            $body();
        } finally {
            $context->employee = $previous;
        }
    }

    public function test_input_schema_offers_every_parameter_execute_accepts(): void
    {
        $schema = $this->tool->inputSchema();

        self::assertSame('object', $schema['type']);
        self::assertSame([], $schema['required']);

        $offered = array_keys($schema['properties']);
        sort($offered);

        self::assertSame(
            ['bindings', 'limit', 'schema', 'sql'],
            $offered,
            'A parameter execute() honours but the schema omits is one no model will ever send.'
        );
    }

    public function test_input_schema_teaches_the_model_this_install_s_table_prefix(): void
    {
        $description = $this->tool->inputSchema()['properties']['sql']['description'];

        self::assertStringContainsString(
            $this->prefix,
            $description,
            'a model that is not told the prefix writes queries against tables that do not exist'
        );
    }

    public function test_input_schema_limit_bounds_match_the_enforced_limit(): void
    {
        $limit = $this->tool->inputSchema()['properties']['limit'];

        self::assertSame(1, $limit['minimum']);
        self::assertSame(500, $limit['maximum']);
    }

    public function test_schema_mode_reports_tables_with_row_counts(): void
    {
        $rows = $this->rows($this->tool->execute(['schema' => true]));

        self::assertNotEmpty($rows, 'schema mode must list the tables this install actually has');

        $names = array_column($rows, 'table');
        self::assertContains($this->testTable, $names);

        foreach ($rows as $row) {
            self::assertArrayHasKey('rows', $row);
            self::assertArrayHasKey('engine', $row);
            self::assertArrayHasKey('size_kb', $row);
        }
    }
}

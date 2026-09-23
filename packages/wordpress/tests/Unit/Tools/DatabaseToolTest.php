<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\Tools;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\SqlGuard\SqlDialect;
use PhpClaw\SqlGuard\SqlReadOnlyGuard;
use PhpClaw\WordPress\Tests\Concerns\StubsCapabilities;
use PhpClaw\WordPress\Tools\DatabaseTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DatabaseTool::class)]
final class DatabaseToolTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use StubsCapabilities;

    private object $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->grantCapability('phpclaw_use_chat');

        $this->wpdb = new class
        {
            public string $prefix = 'wp_';

            public string $last_error = '';

            public ?array $nextResults = null;

            public ?string $lastSql = null;

            public function prepare(string $sql, mixed ...$args): string
            {
                return $sql;
            }

            public function get_results(string $sql, string $output = OBJECT): ?array
            {
                $this->lastSql = $sql;

                return $this->nextResults;
            }
        };

        $GLOBALS['wpdb'] = $this->wpdb;
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_name_is_db_query(): void
    {
        self::assertSame('db_query', (new DatabaseTool)->name());
    }

    public function test_input_schema_has_sql_property(): void
    {
        $schema = (new DatabaseTool)->inputSchema();
        self::assertArrayHasKey('sql', $schema['properties']);
    }

    public function test_it_blocks_insert_statement(): void
    {

        $decoded = json_decode((new DatabaseTool)->execute(['sql' => 'INSERT INTO users VALUES (1)']), true);

        self::assertFalse($decoded['success']);
        self::assertSame('BLOCKED_STATEMENT', $decoded['error']['code']);
    }

    public function test_it_blocks_delete_statement(): void
    {

        $decoded = json_decode((new DatabaseTool)->execute(['sql' => 'DELETE FROM posts']), true);

        self::assertFalse($decoded['success']);
        self::assertSame('BLOCKED_STATEMENT', $decoded['error']['code']);
    }

    public function test_it_blocks_drop_statement(): void
    {

        $decoded = json_decode((new DatabaseTool)->execute(['sql' => 'DROP TABLE users']), true);

        self::assertFalse($decoded['success']);
        self::assertSame('BLOCKED_STATEMENT', $decoded['error']['code']);
    }

    public function test_it_allows_union_in_read_select(): void
    {
        $this->wpdb->nextResults = [];

        (new DatabaseTool)->execute(['sql' => 'SELECT 1 UNION SELECT ID FROM posts']);

        self::assertStringContainsString('UNION', (string) $this->wpdb->lastSql);
    }

    public function test_it_allows_cte_read_query(): void
    {
        $this->wpdb->nextResults = [];

        (new DatabaseTool)->execute(['sql' => 'WITH x AS (SELECT 1) SELECT * FROM x']);

        self::assertStringStartsWith('WITH x AS', (string) $this->wpdb->lastSql);
    }

    public function test_it_rejects_empty_query(): void
    {

        $decoded = json_decode((new DatabaseTool)->execute(['sql' => '']), true);

        self::assertFalse($decoded['success']);
        self::assertSame('INVALID_ARGUMENT', $decoded['error']['code']);
    }

    public function test_it_returns_json_results(): void
    {
        $this->wpdb->nextResults = [
            ['id' => 1, 'title' => 'Hello'],
            ['id' => 2, 'title' => 'World'],
        ];

        $result = (new DatabaseTool)->execute(['sql' => 'SELECT id, title FROM posts LIMIT 2']);

        $decoded = json_decode($result, associative: true);
        self::assertCount(2, $decoded['data']['rows']);
        self::assertSame('Hello', $decoded['data']['rows'][0]['title']);
    }

    public function test_it_returns_empty_array_json_when_no_results(): void
    {
        $this->wpdb->nextResults = [];

        $result = (new DatabaseTool)->execute(['sql' => 'SELECT * FROM posts WHERE 0=1']);

        $decoded = json_decode($result, associative: true);
        self::assertSame([], $decoded['data']['rows']);
        self::assertSame(0, $decoded['meta']['count']);
    }

    public function test_it_throws_on_wpdb_error(): void
    {
        $this->wpdb->nextResults = null;
        $this->wpdb->last_error = 'Table not found';

        $this->expectException(ToolException::class);
        $this->expectExceptionMessageMatches('/db_query execution failed/');

        (new DatabaseTool)->execute(['sql' => 'SELECT * FROM nonexistent']);
    }

    public function test_blocks_alter_statement(): void
    {

        $decoded = json_decode((new DatabaseTool)->execute(['sql' => 'ALTER TABLE x ADD col VARCHAR(50)']), true);

        self::assertFalse($decoded['success']);
        self::assertSame('BLOCKED_STATEMENT', $decoded['error']['code']);
    }

    public function test_blocks_update_statement(): void
    {

        $decoded = json_decode((new DatabaseTool)->execute(['sql' => 'UPDATE posts SET title = "evil" WHERE 1=1']), true);

        self::assertFalse($decoded['success']);
        self::assertSame('BLOCKED_STATEMENT', $decoded['error']['code']);
    }

    public function test_blocks_truncate_statement(): void
    {

        $decoded = json_decode((new DatabaseTool)->execute(['sql' => 'TRUNCATE TABLE posts']), true);

        self::assertFalse($decoded['success']);
        self::assertSame('BLOCKED_STATEMENT', $decoded['error']['code']);
    }

    public function test_auto_limit_appended_when_no_limit_in_query(): void
    {
        $this->wpdb->nextResults = [];

        (new DatabaseTool)->execute(['sql' => 'SELECT * FROM posts WHERE 1=1']);

        self::assertStringContainsString('LIMIT 200', (string) $this->wpdb->lastSql);
    }

    public function test_limit_above_the_maximum_is_rejected_not_clamped(): void
    {
        $this->wpdb->nextResults = [];

        $r = json_decode((new DatabaseTool)->execute(['sql' => 'SELECT * FROM posts', 'limit' => 99999]), true);

        self::assertFalse($r['success']);
        self::assertSame('INVALID_LIMIT', $r['error']['code']);
        self::assertSame('', (string) $this->wpdb->lastSql);
    }

    public function test_maximum_limit_is_applied_to_a_query_without_one(): void
    {
        $this->wpdb->nextResults = [];

        (new DatabaseTool)->execute(['sql' => 'SELECT * FROM posts', 'limit' => 1000]);

        self::assertStringContainsString('LIMIT 1000', (string) $this->wpdb->lastSql);
    }

    public function test_bindings_with_scalar_values_work(): void
    {
        $this->wpdb->nextResults = [['id' => 1]];

        $r = json_decode((new DatabaseTool)->execute([
            'sql' => 'SELECT id FROM posts WHERE id = %d',
            'bindings' => [42],
        ]), true);

        self::assertCount(1, $r['data']['rows']);
    }

    public function test_bindings_with_non_scalar_return_invalid_binding(): void
    {
        $r = json_decode((new DatabaseTool)->execute([
            'sql' => 'SELECT * FROM posts WHERE id = %d',
            'bindings' => [['nested' => 'array']],
        ]), true);

        self::assertFalse($r['success']);
        self::assertSame('INVALID_BINDING', $r['error']['code']);
    }

    public function test_throws_when_wpdb_throws(): void
    {
        $this->wpdb = new class
        {
            public string $prefix = 'wp_';

            public string $last_error = '';

            public function prepare(string $sql, mixed ...$args): string
            {
                return $sql;
            }

            public function get_results(string $sql, string $output = OBJECT): never
            {
                throw new \RuntimeException('crash');
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;

        $this->expectException(ToolException::class);
        $this->expectExceptionMessageMatches('/db_query execution failed/');

        (new DatabaseTool)->execute(['sql' => 'SELECT 1']);
    }

    public function test_blocks_load_data_infile(): void
    {

        $decoded = json_decode((new DatabaseTool)->execute(['sql' => "LOAD DATA INFILE '/etc/passwd' INTO TABLE x"]), true);

        self::assertFalse($decoded['success']);
        self::assertSame('BLOCKED_STATEMENT', $decoded['error']['code']);
    }

    public function test_blocks_grant_statement(): void
    {

        $decoded = json_decode((new DatabaseTool)->execute(['sql' => 'GRANT ALL ON *.* TO root@localhost']), true);

        self::assertFalse($decoded['success']);
        self::assertSame('BLOCKED_STATEMENT', $decoded['error']['code']);
    }

    public function test_blocks_information_schema_access(): void
    {

        $decoded = json_decode((new DatabaseTool)->execute(['sql' => 'SELECT * FROM information_schema.tables']), true);

        self::assertFalse($decoded['success']);
        self::assertSame('BLOCKED_STATEMENT', $decoded['error']['code']);
    }

    public function test_blocks_into_outfile(): void
    {

        $decoded = json_decode((new DatabaseTool)->execute(['sql' => "SELECT * FROM users INTO OUTFILE '/tmp/dump'"]), true);

        self::assertFalse($decoded['success']);
        self::assertSame('BLOCKED_STATEMENT', $decoded['error']['code']);
    }

    public function test_blocks_restricted_credential_identifier(): void
    {

        $decoded = json_decode((new DatabaseTool)->execute(['sql' => 'SELECT user_pass FROM wp_users']), true);

        self::assertFalse($decoded['success']);
        self::assertSame('BLOCKED_IDENTIFIER', $decoded['error']['code']);
    }

    public function test_blocks_restricted_token_identifier(): void
    {

        $decoded = json_decode((new DatabaseTool)->execute(['sql' => 'SELECT access_token FROM wp_options']), true);

        self::assertFalse($decoded['success']);
        self::assertSame('BLOCKED_IDENTIFIER', $decoded['error']['code']);
    }

    public function test_query_with_limit_already_present_does_not_double_append(): void
    {
        $this->wpdb->nextResults = [];

        (new DatabaseTool)->execute(['sql' => 'SELECT * FROM posts LIMIT 5']);

        $sql = (string) $this->wpdb->lastSql;

        self::assertSame(1, substr_count(strtoupper($sql), 'LIMIT'));
        self::assertStringContainsString('LIMIT 5', $sql);
    }

    public function test_format_output_truncates_when_over_8kb(): void
    {
        $bigRows = [];
        for ($i = 0; $i < 200; $i++) {
            $bigRows[] = ['id' => $i, 'content' => str_repeat('X', 100)];
        }
        $this->wpdb->nextResults = $bigRows;

        $result = (new DatabaseTool)->execute(['sql' => 'SELECT * FROM big LIMIT 200']);

        $r = json_decode($result, true);

        self::assertTrue($r['meta']['truncated']);
        self::assertSame('OUTPUT_TRUNCATED', $r['warnings'][0]['code']);
    }

    public function test_format_output_returns_empty_results(): void
    {
        $this->wpdb->nextResults = [];

        $result = (new DatabaseTool)->execute(['sql' => 'SELECT 1 LIMIT 1']);
        $r = json_decode($result, true);

        self::assertSame([], $r['data']['rows']);
        self::assertSame(0, $r['meta']['count']);
    }

    public function test_description_states_what_the_tool_does(): void
    {
        self::assertStringContainsString(
            'read-only SQL SELECT',
            (new DatabaseTool)->description(),
        );
    }

    public function test_schema_mode_lists_tables(): void
    {
        $schemaWpdb = new \wpdb;
        $schemaWpdb->nextResults = [
            ['Name' => 'wp_posts',   'Rows' => 100, 'Engine' => 'InnoDB'],
            ['Name' => 'wp_options', 'Rows' => 50,  'Engine' => 'InnoDB'],
        ];
        $GLOBALS['wpdb'] = $schemaWpdb;

        $r = json_decode((new DatabaseTool)->execute(['schema' => true]), true);

        self::assertArrayHasKey('tables', $r['data']);
        self::assertSame(2, $r['meta']['count']);
        self::assertSame('wp_posts', $r['data']['tables'][0]['table']);
        self::assertSame(100, $r['data']['tables'][0]['rows']);
        self::assertSame('InnoDB', $r['data']['tables'][0]['engine']);
    }

    public function test_schema_mode_throws_on_db_error(): void
    {
        $brokenWpdb = new \wpdb;
        $brokenWpdb->nextResults = null;
        $brokenWpdb->last_error = 'connection lost';
        $GLOBALS['wpdb'] = $brokenWpdb;

        $this->expectException(ToolException::class);
        $this->expectExceptionMessageMatches('/db_query schema failed/');

        (new DatabaseTool)->execute(['schema' => true]);
    }

    public function test_architecture_guard_sql_reaches_wpdb_not_raw_input(): void
    {
        $capture = new \stdClass;
        $capture->sql = null;

        $spy = new class($capture)
        {
            public string $prefix = 'wp_';

            public string $last_error = '';

            private \stdClass $cap;

            public function __construct(\stdClass $cap)
            {
                $this->cap = $cap;
            }

            public function prepare(string $sql, mixed ...$args): string
            {
                return $sql;
            }

            public function get_results(string $sql, string $output = 'ARRAY_A'): array
            {
                $this->cap->sql = $sql;

                return [];
            }
        };
        $GLOBALS['wpdb'] = $spy;

        (new DatabaseTool)->execute(['sql' => 'SELECT 1']);

        $expected = (new SqlReadOnlyGuard(SqlDialect::MySql))
            ->validate('SELECT 1')
            ->withLimit(200)
            ->sql();

        self::assertSame(
            $expected,
            $capture->sql,
            'DatabaseTool must pass guard->sql() to $wpdb, not the raw $sql variable',
        );
    }

    #[DataProvider('mutationPayloads')]
    public function test_sql_guard_blocks_mutation(string $key, string $payload): void
    {
        $spy = new \stdClass;
        $spy->reached = false;

        $wpdbSpy = new class($spy)
        {
            public string $prefix = 'wp_';

            public string $last_error = '';

            private \stdClass $s;

            public function __construct(\stdClass $s)
            {
                $this->s = $s;
            }

            public function prepare(string $sql, mixed ...$args): string
            {
                $this->s->reached = true;

                return $sql;
            }

            public function get_results(string $query, string $output = OBJECT): array
            {
                $this->s->reached = true;

                return [];
            }
        };
        $GLOBALS['wpdb'] = $wpdbSpy;

        $decoded = json_decode((new DatabaseTool)->execute(['sql' => $payload]), true);

        self::assertFalse($spy->reached, "BYPASSED: {$key}");
        self::assertFalse($decoded['success'], "Expected a blocked envelope for: {$key}");
        self::assertContains(
            $decoded['error']['code'],
            ['BLOCKED_STATEMENT', 'BLOCKED_IDENTIFIER'],
            "Unexpected error code for: {$key}",
        );
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

    public function test_description_lists_actually_blocked_keywords(): void
    {
        $GLOBALS['wpdb'] = new class
        {
            public string $prefix = 'wp_';
        };

        $description = (new DatabaseTool)->description();

        self::assertStringContainsString('WITH (CTEs) is allowed', $description);

        $blockedWords = (new \ReflectionClassConstant(SqlReadOnlyGuard::class, 'BLOCKED_WORDS'))->getValue();

        foreach (['INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'TRUNCATE', 'GRANT', 'REVOKE'] as $blocked) {
            self::assertStringContainsString($blocked, $description);
            self::assertContains($blocked, $blockedWords);
        }

        unset($GLOBALS['wpdb']);
    }

    public function test_it_returns_forbidden_without_the_required_capability(): void
    {
        $this->denyAllCapabilities();

        $this->assertForbiddenEnvelope((new DatabaseTool)->execute(['sql' => 'SELECT 1 LIMIT 1']));
    }

    public function test_it_returns_unknown_argument_instead_of_throwing(): void
    {
        $decoded = json_decode((new DatabaseTool)->execute(['phpclaw_bogus_arg' => 1]), true);

        self::assertFalse($decoded['success']);
        self::assertSame('UNKNOWN_ARGUMENT', $decoded['error']['code']);
        self::assertNotEmpty($decoded['error']['accepted_arguments']);
    }

    public function test_successful_envelope_has_exactly_the_contract_keys(): void
    {
        $this->wpdb->nextResults = [['n' => 1]];
        $decoded = json_decode((new DatabaseTool)->execute(['sql' => 'SELECT 1 LIMIT 1']), true);

        self::assertTrue($decoded['success']);
        self::assertSame(['success', 'data', 'meta', 'warnings'], array_keys($decoded));
        self::assertArrayHasKey('mode', $decoded['meta']);
    }

    public function test_the_prefix_placeholder_is_resolved_before_the_query_runs(): void
    {
        $this->wpdb->prefix = 'acme_';
        $this->wpdb->nextResults = [];

        (new DatabaseTool)->execute([
            'sql' => 'SELECT post_title FROM {prefix}posts WHERE post_type = %s',
            'bindings' => ['page'],
        ]);

        self::assertIsString($this->wpdb->lastSql);
        self::assertStringContainsString('acme_posts', $this->wpdb->lastSql);
        self::assertStringNotContainsString('{prefix}', $this->wpdb->lastSql);
    }

    public function test_the_shipped_examples_carry_no_hardcoded_table_prefix(): void
    {
        foreach (DatabaseTool::examples() as $example) {
            $sql = (string) ($example['arguments']['sql'] ?? '');

            self::assertDoesNotMatchRegularExpression(
                '/\bFROM\s+wp_/i',
                $sql,
                'an example must not teach the model a hardcoded table prefix',
            );
        }
    }

    public function test_the_options_table_is_refused_and_the_refusal_names_the_tool_that_answers(): void
    {
        $decoded = json_decode((new DatabaseTool)->execute(['sql' => 'SELECT * FROM wp_options']), true);

        self::assertFalse($decoded['success']);
        self::assertSame('BLOCKED_IDENTIFIER', $decoded['error']['code']);
        self::assertSame('wp_option', $decoded['error']['use_instead']);
        self::assertStringContainsString('wp_option tool', $decoded['error']['message']);
        self::assertNull($this->wpdb->lastSql, 'the query must never reach the database');
    }

    public function test_the_options_table_is_refused_through_the_prefix_placeholder(): void
    {
        $decoded = json_decode((new DatabaseTool)->execute(['sql' => 'SELECT option_value FROM {prefix}options']), true);

        self::assertSame('BLOCKED_IDENTIFIER', $decoded['error']['code']);
    }

    public function test_the_options_table_is_refused_under_a_custom_prefix(): void
    {
        $this->wpdb->prefix = 'xyz_';

        $decoded = json_decode((new DatabaseTool)->execute(['sql' => 'SELECT * FROM xyz_options']), true);

        self::assertSame('BLOCKED_IDENTIFIER', $decoded['error']['code']);
    }

    public function test_an_ordinary_business_table_still_answers(): void
    {
        $this->wpdb->nextResults = [(object) ['ID' => 1, 'post_title' => 'Hello']];

        $decoded = json_decode((new DatabaseTool)->execute(['sql' => 'SELECT ID, post_title FROM wp_posts LIMIT 1']), true);

        self::assertTrue($decoded['success']);
        self::assertStringContainsString('wp_posts', (string) $this->wpdb->lastSql);
    }

    public function test_a_table_whose_name_merely_ends_in_options_is_not_refused(): void
    {
        $this->wpdb->nextResults = [];

        $decoded = json_decode((new DatabaseTool)->execute(['sql' => 'SELECT id FROM wp_myplugin_options LIMIT 1']), true);

        self::assertTrue($decoded['success']);
    }

    #[DataProvider('underscoreJoinedCredentialIdentifiers')]
    public function test_a_restricted_word_joined_by_an_underscore_is_now_blocked(string $column): void
    {
        $decoded = json_decode((new DatabaseTool)->execute(['sql' => "SELECT {$column} FROM wp_posts"]), true);

        self::assertFalse($decoded['success']);
        self::assertSame('BLOCKED_IDENTIFIER', $decoded['error']['code']);
        self::assertNull($this->wpdb->lastSql, 'the query must never reach the database');
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

    #[DataProvider('identifiersThatMerelyContainARestrictedWord')]
    public function test_widening_the_boundary_does_not_block_a_legitimate_identifier(string $column): void
    {
        $this->wpdb->nextResults = [];

        $decoded = json_decode((new DatabaseTool)->execute(['sql' => "SELECT {$column} FROM wp_posts LIMIT 1"]), true);

        self::assertTrue($decoded['success'], $column.' must stay readable');
    }

    public static function identifiersThatMerelyContainARestrictedWord(): array
    {
        return [
            ['saltwater'],
            ['secretary'],
            ['assault'],
            ['post_title'],
        ];
    }

    public function test_description_names_the_capability_the_tool_checks(): void
    {
        $GLOBALS['wpdb'] = new class
        {
            public string $prefix = 'wp_';
        };

        $tool = (new DatabaseTool);

        self::assertStringContainsString('"'.$tool->requiredCapability().'"', $tool->description());
    }
}

<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit\Tools;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Orchestra\Testbench\TestCase;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Laravel\LaravelIdentityResolver;
use PhpClaw\Laravel\PhpClawServiceProvider;
use PhpClaw\Laravel\Tools\AbstractLaravelTool;
use PhpClaw\Laravel\Tools\DatabaseTool;
use PHPUnit\Framework\Attributes\DataProvider;

final class DatabaseToolTest extends TestCase
{
    private DatabaseTool $tool;

    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('phpclaw.api_key', 'test-key');
        $app['config']->set('phpclaw.memory_driver', 'array');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = new DatabaseTool;
    }

    public function test_name_returns_db_query(): void
    {
        $this->assertSame('db_query', $this->tool->name());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        $description = $this->tool->description();

        $this->assertStringContainsString('EXECUTE a read-only SQL SELECT against the live Laravel DB', $description);
        $this->assertStringContainsString('never describe SQL, run it', $description);
    }

    public function test_input_schema_has_required_query(): void
    {
        $schema = $this->tool->inputSchema();

        $this->assertIsArray($schema);
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('query', $schema['properties']);

        $this->assertArrayHasKey('required', $schema);
        $this->assertContains('query', $schema['required']);
    }

    public function test_required_capability_is_manage_all_ability(): void
    {
        $this->assertSame(LaravelIdentityResolver::MANAGE_ALL_ABILITY, $this->tool->requiredCapability());
    }

    public function test_success_envelope_has_correct_shape(): void
    {
        DB::shouldReceive('select')
            ->once()
            ->with('SELECT 1 AS value', [])
            ->andReturn([['value' => 1]]);

        $raw = $this->tool->execute(['query' => 'SELECT 1 AS value']);
        $envelope = json_decode($raw, associative: true);

        $this->assertTrue($envelope['success']);
        $this->assertArrayHasKey('data', $envelope);
        $this->assertArrayHasKey('meta', $envelope);
        $this->assertArrayHasKey('warnings', $envelope);
        $this->assertArrayHasKey('rows', $envelope['data']);
        $this->assertSame('query', $envelope['meta']['mode']);
    }

    public function test_execute_runs_select_query(): void
    {
        DB::shouldReceive('select')
            ->once()
            ->with('SELECT 1 AS value', [])
            ->andReturn([['value' => 1]]);

        $envelope = json_decode($this->tool->execute(['query' => 'SELECT 1 AS value']), associative: true);

        $this->assertTrue($envelope['success']);
        $this->assertSame([['value' => 1]], $envelope['data']['rows']);
    }

    public function test_execute_rejects_insert(): void
    {
        $envelope = json_decode(
            $this->tool->execute(['query' => 'INSERT INTO users (name) VALUES ("hacker")']),
            associative: true,
        );

        $this->assertFalse($envelope['success']);
        $this->assertSame('BLOCKED_STATEMENT', $envelope['error']['code']);
    }

    public function test_execute_rejects_update(): void
    {
        $envelope = json_decode(
            $this->tool->execute(['query' => 'UPDATE users SET admin = 1']),
            associative: true,
        );

        $this->assertFalse($envelope['success']);
        $this->assertSame('BLOCKED_STATEMENT', $envelope['error']['code']);
    }

    public function test_execute_rejects_delete(): void
    {
        $envelope = json_decode(
            $this->tool->execute(['query' => 'DELETE FROM users']),
            associative: true,
        );

        $this->assertFalse($envelope['success']);
        $this->assertSame('BLOCKED_STATEMENT', $envelope['error']['code']);
    }

    public function test_execute_rejects_drop(): void
    {
        $envelope = json_decode(
            $this->tool->execute(['query' => 'DROP TABLE users']),
            associative: true,
        );

        $this->assertFalse($envelope['success']);
        $this->assertSame('BLOCKED_STATEMENT', $envelope['error']['code']);
    }

    public function test_execute_rejects_truncate(): void
    {
        $envelope = json_decode(
            $this->tool->execute(['query' => 'TRUNCATE TABLE users']),
            associative: true,
        );

        $this->assertFalse($envelope['success']);
        $this->assertSame('BLOCKED_STATEMENT', $envelope['error']['code']);
    }

    public function test_execute_rejects_query_starting_with_whitespace_before_insert(): void
    {
        $envelope = json_decode(
            $this->tool->execute(['query' => '  INSERT INTO users (name) VALUES ("bypass")']),
            associative: true,
        );

        $this->assertFalse($envelope['success']);
        $this->assertSame('BLOCKED_STATEMENT', $envelope['error']['code']);
    }

    public function test_execute_rejects_create_statement(): void
    {
        $envelope = json_decode(
            $this->tool->execute(['query' => 'CREATE TABLE evil (id INT)']),
            associative: true,
        );

        $this->assertFalse($envelope['success']);
        $this->assertSame('BLOCKED_STATEMENT', $envelope['error']['code']);
    }

    public function test_execute_rejects_alter_statement(): void
    {
        $envelope = json_decode(
            $this->tool->execute(['query' => 'ALTER TABLE users ADD COLUMN is_evil TINYINT']),
            associative: true,
        );

        $this->assertFalse($envelope['success']);
        $this->assertSame('BLOCKED_STATEMENT', $envelope['error']['code']);
    }

    public function test_execute_rejects_case_insensitive_insert(): void
    {
        $envelope = json_decode(
            $this->tool->execute(['query' => 'insert into users (name) values ("case-bypass")']),
            associative: true,
        );

        $this->assertFalse($envelope['success']);
        $this->assertSame('BLOCKED_STATEMENT', $envelope['error']['code']);
    }

    public function test_execute_allows_union_in_select(): void
    {
        DB::shouldReceive('select')
            ->once()
            ->with('SELECT id FROM users UNION SELECT id FROM admins', [])
            ->andReturn([]);

        $envelope = json_decode(
            $this->tool->execute(['query' => 'SELECT id FROM users UNION SELECT id FROM admins']),
            associative: true,
        );

        $this->assertTrue($envelope['success']);
        $this->assertSame([], $envelope['data']['rows']);
    }

    public function test_execute_allows_except_in_select(): void
    {
        DB::shouldReceive('select')
            ->once()
            ->with('SELECT id FROM users EXCEPT SELECT id FROM banned', [])
            ->andReturn([]);

        $envelope = json_decode(
            $this->tool->execute(['query' => 'SELECT id FROM users EXCEPT SELECT id FROM banned']),
            associative: true,
        );

        $this->assertTrue($envelope['success']);
        $this->assertSame([], $envelope['data']['rows']);
    }

    public function test_execute_allows_intersect_in_select(): void
    {
        DB::shouldReceive('select')
            ->once()
            ->with('SELECT id FROM users INTERSECT SELECT id FROM active', [])
            ->andReturn([]);

        $envelope = json_decode(
            $this->tool->execute(['query' => 'SELECT id FROM users INTERSECT SELECT id FROM active']),
            associative: true,
        );

        $this->assertTrue($envelope['success']);
        $this->assertSame([], $envelope['data']['rows']);
    }

    public function test_execute_allows_with_cte(): void
    {
        DB::shouldReceive('select')
            ->once()
            ->with('WITH cte AS (SELECT id FROM users) SELECT * FROM cte', [])
            ->andReturn([]);

        $envelope = json_decode(
            $this->tool->execute(['query' => 'WITH cte AS (SELECT id FROM users) SELECT * FROM cte']),
            associative: true,
        );

        $this->assertTrue($envelope['success']);
        $this->assertSame([], $envelope['data']['rows']);
    }

    public function test_execute_rejects_into_outfile(): void
    {
        $envelope = json_decode(
            $this->tool->execute(['query' => "SELECT * FROM users INTO OUTFILE '/tmp/dump.txt'"]),
            associative: true,
        );

        $this->assertFalse($envelope['success']);
        $this->assertSame('BLOCKED_STATEMENT', $envelope['error']['code']);
        $this->assertStringContainsString('INTO', $envelope['error']['message']);
    }

    public function test_execute_rejects_array_binding(): void
    {
        $envelope = json_decode(
            $this->tool->execute(['query' => 'SELECT * FROM users WHERE id = ?', 'bindings' => [[1, 2, 3]]]),
            associative: true,
        );

        $this->assertFalse($envelope['success']);
        $this->assertSame('INVALID_BINDING', $envelope['error']['code']);
        $this->assertStringContainsString('index 0', $envelope['error']['message']);
    }

    public function test_execute_rejects_object_binding(): void
    {
        $envelope = json_decode(
            $this->tool->execute(['query' => 'SELECT * FROM users WHERE id = ?', 'bindings' => [new \stdClass]]),
            associative: true,
        );

        $this->assertFalse($envelope['success']);
        $this->assertSame('INVALID_BINDING', $envelope['error']['code']);
    }

    public function test_execute_allows_null_binding(): void
    {
        DB::shouldReceive('select')
            ->once()
            ->with('SELECT * FROM users WHERE deleted_at = ?', [null])
            ->andReturn([]);

        $envelope = json_decode(
            $this->tool->execute(['query' => 'SELECT * FROM users WHERE deleted_at = ?', 'bindings' => [null]]),
            associative: true,
        );

        $this->assertTrue($envelope['success']);
        $this->assertSame([], $envelope['data']['rows']);
    }

    public function test_execute_rejects_empty_query(): void
    {
        $envelope = json_decode(
            $this->tool->execute(['query' => '']),
            associative: true,
        );

        $this->assertFalse($envelope['success']);
        $this->assertSame('INVALID_ARGUMENT', $envelope['error']['code']);
        $this->assertStringContainsString('required', $envelope['error']['message']);
    }

    public function test_execute_rejects_insert_hidden_after_comment(): void
    {
        $envelope = json_decode(
            $this->tool->execute(['query' => '/* harmless */ INSERT INTO users (name) VALUES ("x")']),
            associative: true,
        );

        $this->assertFalse($envelope['success']);
        $this->assertSame('BLOCKED_STATEMENT', $envelope['error']['code']);
        $this->assertStringContainsString('comments are not permitted', $envelope['error']['message']);
    }

    public function test_execute_blocks_restricted_credential_identifier(): void
    {
        $envelope = json_decode(
            $this->tool->execute(['query' => 'SELECT password FROM users']),
            associative: true,
        );

        $this->assertFalse($envelope['success']);
        $this->assertSame('BLOCKED_IDENTIFIER', $envelope['error']['code']);
        $this->assertStringContainsString('restricted table or column', $envelope['error']['message']);
    }

    public function test_execute_blocks_restricted_token_identifier(): void
    {
        $envelope = json_decode(
            $this->tool->execute(['query' => 'SELECT remember_token FROM users']),
            associative: true,
        );

        $this->assertFalse($envelope['success']);
        $this->assertSame('BLOCKED_IDENTIFIER', $envelope['error']['code']);
    }

    public function test_execute_wraps_query_exception_in_tool_exception(): void
    {
        DB::shouldReceive('select')
            ->times(2)
            ->andThrow(new \Exception('SQLSTATE[HY000]: General error'));

        $this->expectException(ToolException::class);

        $this->tool->execute(['query' => 'SELECT * FROM nonexistent_table']);
    }

    public function test_db_select_receives_safe_sql_output_not_raw_input(): void
    {
        DB::shouldReceive('select')
            ->once()
            ->with('SELECT 1', [])
            ->andReturn([]);

        $this->tool->execute(['query' => '   SELECT 1   ']);
    }

    #[DataProvider('mutationPayloads')]
    public function test_sql_guard_blocks_mutation_payload(string $key, string $payload): void
    {
        $reached = false;

        DB::shouldReceive('select')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function () use (&$reached): array {
                $reached = true;

                return [];
            });

        $raw = $this->tool->execute(['query' => $payload]);

        $this->assertFalse($reached, "BYPASSED: {$key}");

        $envelope = json_decode($raw, associative: true);
        $this->assertFalse($envelope['success'], "Expected error envelope for: {$key}");
    }

    public static function mutationPayloads(): array
    {
        return [
            'stacked_drop' => ['stacked_drop',    'SELECT 1; DROP TABLE users'],
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

    public function test_select_star_redacts_credential_columns_from_the_result(): void
    {
        DB::shouldReceive('select')
            ->once()
            ->with('SELECT * FROM users LIMIT 1', [])
            ->andReturn([[
                'id' => 1,
                'name' => 'Akash',
                'password' => '$2y$12$abcdefghijklmnopqrstuv',
                'remember_token' => 'S3cr3tT0ken',
            ]]);

        $envelope = json_decode(
            $this->tool->execute(['query' => 'SELECT * FROM users LIMIT 1']),
            associative: true,
        );

        $this->assertSame('[redacted]', $envelope['data']['rows'][0]['password']);
        $this->assertSame('[redacted]', $envelope['data']['rows'][0]['remember_token']);
        $this->assertSame('Akash', $envelope['data']['rows'][0]['name']);
        $this->assertSame(1, $envelope['data']['rows'][0]['id']);
    }

    public function test_suffixed_credential_columns_are_redacted_despite_passing_the_sql_text_check(): void
    {
        DB::shouldReceive('select')
            ->once()
            ->andReturn([[
                'id' => 1,
                'password_hash' => '$2y$12$deadbeefdeadbeefdeadbe',
                'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            ]]);

        $envelope = json_decode(
            $this->tool->execute(['query' => 'SELECT id, password_hash, two_factor_secret FROM accounts']),
            associative: true,
        );

        $this->assertSame('[redacted]', $envelope['data']['rows'][0]['password_hash']);
        $this->assertSame('[redacted]', $envelope['data']['rows'][0]['two_factor_secret']);
    }

    public function test_stdclass_rows_from_the_real_driver_are_redacted(): void
    {
        DB::shouldReceive('select')
            ->once()
            ->andReturn([(object) ['id' => 1, 'api_token' => 'live-token-value']]);

        $envelope = json_decode(
            $this->tool->execute(['query' => 'SELECT * FROM clients']),
            associative: true,
        );

        $this->assertSame('[redacted]', $envelope['data']['rows'][0]['api_token']);
        $this->assertSame(1, $envelope['data']['rows'][0]['id']);
    }

    public function test_forbidden_envelope_returned_for_authenticated_caller_without_manage_all(): void
    {
        $this->actingAs(new User);

        $double = new DatabaseToolCapabilityDouble;
        $envelope = json_decode($double->execute([]), associative: true);

        $this->assertFalse($envelope['success']);
        $this->assertSame('FORBIDDEN', $envelope['error']['code']);
    }

    public function test_manage_all_granted_caller_reaches_query_in_http_context(): void
    {
        $this->actingAs(new User);
        Gate::define(LaravelIdentityResolver::MANAGE_ALL_ABILITY, fn () => true);

        $double = new DatabaseToolCapabilityDouble;
        $envelope = json_decode($double->execute([]), associative: true);

        $this->assertTrue($envelope['success']);
    }

    public function test_blocked_table_returns_blocked_identifier_error_code(): void
    {
        $envelope = json_decode(
            $this->tool->execute(['query' => 'SELECT * FROM phpclaw_conversations']),
            associative: true,
        );

        $this->assertFalse($envelope['success']);
        $this->assertSame('BLOCKED_IDENTIFIER', $envelope['error']['code']);
    }

    public function test_non_select_statement_returns_blocked_statement_error_code(): void
    {
        $envelope = json_decode(
            $this->tool->execute(['query' => 'INSERT INTO logs (msg) VALUES ("x")']),
            associative: true,
        );

        $this->assertFalse($envelope['success']);
        $this->assertSame('BLOCKED_STATEMENT', $envelope['error']['code']);
    }

    public function test_password_column_redacted_in_envelope_data_rows(): void
    {
        DB::shouldReceive('select')
            ->once()
            ->andReturn([['id' => 99, 'email' => 'u@example.com', 'password' => 'hashed-secret']]);

        $envelope = json_decode(
            $this->tool->execute(['query' => 'SELECT id, email FROM users LIMIT 1']),
            associative: true,
        );

        $this->assertTrue($envelope['success']);
        $this->assertSame('[redacted]', $envelope['data']['rows'][0]['password']);
        $this->assertSame('u@example.com', $envelope['data']['rows'][0]['email']);
    }
}

final class DatabaseToolCapabilityDouble extends AbstractLaravelTool
{
    public function name(): string
    {
        return 'db_query_capability_double';
    }

    public function description(): string
    {
        return 'Test double for the DatabaseTool capability guard.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function requiredCapability(): string
    {
        return LaravelIdentityResolver::MANAGE_ALL_ABILITY;
    }

    protected function runningInConsole(): bool
    {
        return false;
    }

    protected function plan(array $input): array
    {
        $forbidden = $this->guardCapability('run a raw SQL query');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        return ['input' => $input, 'result' => null];
    }

    protected function perform(array $input): array
    {
        return ['rows' => [['probe' => 'ok']], 'total' => 1, 'truncated' => false];
    }

    protected function verify(array $execution, array $input): array
    {
        if (! is_array($execution['rows'])) {
            throw new ToolException('capability_double: invalid rows.');
        }

        return ['result' => null];
    }

    protected function complete(array $execution, array $input): string
    {
        return $this->success(
            ['rows' => $execution['rows']],
            ['mode' => 'query', 'count' => count($execution['rows']), 'total' => $execution['total'], 'truncated' => false],
        );
    }
}

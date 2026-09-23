<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\SqlGuard\SafeSql;
use PhpClaw\Tools\DatabaseQueryTool;
use PhpClaw\Tools\ShellTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DatabaseQueryToolTest extends TestCase
{
    private \PDO $pdo;

    private DatabaseQueryTool $tool;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)');
        $this->pdo->exec("INSERT INTO users (name, email) VALUES ('Alice', 'alice@example.com')");
        $this->pdo->exec("INSERT INTO users (name, email) VALUES ('Bob',   'bob@example.com')");

        $this->tool = new DatabaseQueryTool($this->pdo);
    }

    public function test_name_returns_db_query(): void
    {
        $this->assertSame('db_query', $this->tool->name());
    }

    public function test_description_is_non_empty(): void
    {
        $this->assertNotEmpty($this->tool->description());
    }

    public function test_input_schema_requires_sql(): void
    {
        $schema = $this->tool->inputSchema();
        $this->assertArrayHasKey('sql', $schema['properties']);
        $this->assertContains('sql', $schema['required']);
    }

    public function test_execute_returns_json_with_rows_and_count(): void
    {
        $result = $this->tool->execute(['sql' => 'SELECT * FROM users']);
        $data = ((array) json_decode($result, true))['data'];

        $this->assertIsArray($data);
        $this->assertArrayHasKey('rows', $data);
        $this->assertArrayHasKey('count', $data);
        $this->assertSame(2, $data['count']);
    }

    public function test_execute_returns_correct_row_data(): void
    {
        $result = $this->tool->execute(['sql' => 'SELECT name FROM users ORDER BY name']);
        $data = ((array) json_decode($result, true))['data'];

        $this->assertSame('Alice', $data['rows'][0]['name']);
        $this->assertSame('Bob', $data['rows'][1]['name']);
    }

    public function test_execute_respects_bound_params(): void
    {
        $result = $this->tool->execute([
            'sql' => 'SELECT name FROM users WHERE name = ?',
            'params' => ['Alice'],
        ]);
        $data = ((array) json_decode($result, true))['data'];

        $this->assertSame(1, $data['count']);
        $this->assertSame('Alice', $data['rows'][0]['name']);
    }

    public function test_execute_returns_empty_rows_for_no_matches(): void
    {
        $result = $this->tool->execute(['sql' => "SELECT * FROM users WHERE name = 'Nobody'"]);
        $data = ((array) json_decode($result, true))['data'];

        $this->assertSame(0, $data['count']);
        $this->assertSame([], $data['rows']);
    }

    public function test_execute_does_not_set_truncated_flag_when_under_limit(): void
    {
        $result = $this->tool->execute(['sql' => 'SELECT * FROM users']);
        $data = ((array) json_decode($result, true))['data'];

        $this->assertArrayNotHasKey('truncated', $data);
    }

    public function test_execute_truncates_results_at_100_rows(): void
    {
        $this->pdo->exec('CREATE TABLE big (n INTEGER)');
        $stmt = $this->pdo->prepare('INSERT INTO big (n) VALUES (?)');
        for ($i = 1; $i <= 110; $i++) {
            $stmt->execute([$i]);
        }

        $result = $this->tool->execute(['sql' => 'SELECT * FROM big']);
        $data = ((array) json_decode($result, true))['data'];

        $this->assertSame(100, $data['count']);
        $this->assertTrue($data['truncated']);
        $this->assertSame(100, $data['limit']);
    }

    #[DataProvider('blockedStatementProvider')]
    public function test_execute_blocks_non_select_statements(string $sql): void
    {
        $this->expectException(ToolException::class);
        $this->tool->execute(['sql' => $sql]);
    }

    public function test_execute_blocks_credential_column(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessageMatches('/restricted table or column/');
        $this->tool->execute(['sql' => 'SELECT password FROM users']);
    }

    public function test_execute_blocks_api_token_column(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessageMatches('/restricted table or column/');
        $this->tool->execute(['sql' => 'SELECT api_token FROM users']);
    }

    public static function blockedStatementProvider(): array
    {
        return [
            ['INSERT INTO users (name) VALUES ("Evil")'],
            ['UPDATE users SET name = "Hacked"'],
            ['DELETE FROM users'],
            ['DROP TABLE users'],
            ['CREATE TABLE evil (x TEXT)'],
            ['ALTER TABLE users ADD COLUMN phone TEXT'],
            ['TRUNCATE TABLE users'],
            ['REPLACE INTO users (name) VALUES ("x")'],
            ['EXEC xp_cmdshell("dir")'],
            ['EXECUTE something'],
            ['GRANT ALL ON users TO evil'],
            ['REVOKE SELECT ON users FROM alice'],
        ];
    }

    public function test_execute_blocks_statement_hidden_behind_comment(): void
    {
        $this->expectException(ToolException::class);
        $this->tool->execute(['sql' => '/* safe */ DELETE FROM users']);
    }

    public function test_execute_rejects_leading_comment(): void
    {
        $this->expectException(ToolException::class);
        $this->tool->execute(['sql' => '-- fetch all users'."\n".'SELECT * FROM users']);
    }

    public function test_execute_blocks_union_insert(): void
    {
        $this->expectException(ToolException::class);
        $this->tool->execute(['sql' => "SELECT * FROM users UNION INSERT INTO users (name) VALUES ('x')"]);
    }

    public function test_execute_throws_when_sql_is_empty(): void
    {
        $this->expectException(ToolException::class);
        $this->tool->execute(['sql' => '']);
    }

    public function test_execute_throws_when_sql_key_missing(): void
    {
        $this->expectException(ToolException::class);
        $this->tool->execute([]);
    }

    public function test_execute_allows_with_cte(): void
    {
        $result = $this->tool->execute([
            'sql' => 'WITH u AS (SELECT name FROM users) SELECT name FROM u ORDER BY name',
        ]);
        $data = ((array) json_decode($result, true))['data'];

        $this->assertSame(2, $data['count']);
    }

    #[DataProvider('mutationPayloads')]
    public function test_mutation_payload_is_blocked_by_guard(string $key, string $payload): void
    {
        $stmtStub = new class extends \PDOStatement
        {
            public function __construct() {}

            public function execute(?array $params = null): bool
            {
                return true;
            }

            public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, mixed ...$args): array
            {
                return [];
            }
        };

        $spy = new class extends \PDO
        {
            public bool $reached = false;

            public ?\PDOStatement $stub = null;

            public function __construct() {}

            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                $this->reached = true;

                return $this->stub;
            }
        };
        $spy->stub = $stmtStub;

        $tool = new DatabaseQueryTool($spy);
        $threw = false;

        try {
            $tool->execute(['sql' => $payload]);
        } catch (ToolException $e) {
            $threw = true;
        }

        self::assertFalse($spy->reached, "BYPASSED: {$key}");
        self::assertTrue($threw);
    }

    public static function mutationPayloads(): array
    {
        return [
            'stacked_drop' => ['stacked_drop',   'SELECT 1; DROP TABLE users'],
            'stacked_delete' => ['stacked_delete', 'SELECT 1;DELETE FROM users'],
            'into_outfile' => ['into_outfile',   "SELECT * FROM users INTO OUTFILE '/tmp/p'"],
            'into_dumpfile' => ['into_dumpfile',  "SELECT * FROM users INTO DUMPFILE '/tmp/p'"],
            'into_split' => ['into_split',     "SELECT 1 INTO/**/OUTFILE '/tmp/p'"],
            'into_bang' => ['into_bang',      "SELECT 1 /*!INTO OUTFILE '/tmp/p'*/"],
            'bang_drop' => ['bang_drop',      'SELECT * FROM users /*!12345 DROP TABLE users */'],
            'dashdash_nl' => ['dashdash_nl',    "SELECT 1 --\nDROP TABLE users"],
            'hash_nl' => ['hash_nl',        "SELECT 1 #\nDROP TABLE users"],
            'nested_comment' => ['nested_comment', 'SELECT 1 /*/**/DROP TABLE users*/'],
            'stacked_bang' => ['stacked_bang',   'SELECT 1;/*! DROP TABLE users */'],
            'ws_lead' => ['ws_lead',        "\n\t  SELECT 1; DROP TABLE users"],
            'fullwidth' => ['fullwidth',      'ＳＥＬＥＣＴ 1; DROP TABLE users'],
            'zerowidth' => ['zerowidth',      "\u{200B}SELECT 1; DROP TABLE users"],
            'cte_delete' => ['cte_delete',     'WITH x AS (SELECT 1) DELETE FROM users'],
            'union_outfile' => ['union_outfile',  "SELECT 1 UNION SELECT * FROM users INTO OUTFILE '/tmp/p'"],
            'paren_stacked' => ['paren_stacked',  '((SELECT 1)); DROP TABLE users'],
            'case_mixed' => ['case_mixed',     'SeLeCt 1; DrOp TABLE users'],
        ];
    }

    public function test_prepare_receives_guard_safe_sql_not_raw_input(): void
    {
        $ref = new \ReflectionMethod(DatabaseQueryTool::class, 'runQuery');
        $first = $ref->getParameters()[0];
        $this->assertSame(SafeSql::class, $first->getType()->getName());

        $stmtStub = new class extends \PDOStatement
        {
            public function __construct() {}

            public function execute(?array $params = null): bool
            {
                return true;
            }

            public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, mixed ...$args): array
            {
                return [];
            }
        };

        $spy = new class extends \PDO
        {
            public ?string $capturedSql = null;

            public ?\PDOStatement $stub = null;

            public function __construct() {}

            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                $this->capturedSql = $query;

                return $this->stub;
            }
        };
        $spy->stub = $stmtStub;

        $rawInput = '  SELECT * FROM users  ';
        (new DatabaseQueryTool($spy))->execute(['sql' => $rawInput]);

        $this->assertNotSame($rawInput, $spy->capturedSql, 'raw padded input must not reach prepare()');
        $this->assertSame('SELECT * FROM users', $spy->capturedSql, 'prepare() must receive safe->sql()');
    }

    private function seedWide(int $rows): void
    {
        $this->pdo->exec('CREATE TABLE posts (id INTEGER, title TEXT, content TEXT)');
        $ins = $this->pdo->prepare('INSERT INTO posts VALUES (?, ?, ?)');
        for ($i = 0; $i < $rows; $i++) {
            $ins->execute([$i, "Post {$i}", str_repeat('Lorem ipsum dolor sit amet. ', 200)]);
        }
    }

    private function seedNarrow(int $rows): void
    {
        $this->pdo->exec('CREATE TABLE items (id INTEGER, title TEXT)');
        $ins = $this->pdo->prepare('INSERT INTO items VALUES (?, ?)');
        for ($i = 0; $i < $rows; $i++) {
            $ins->execute([$i, "Item {$i}"]);
        }
    }

    private function data(string $json): array
    {
        return json_decode($json, true)['data'] ?? [];
    }

    public function test_narrow_result_is_returned_inline_and_unchanged(): void
    {
        $this->seedNarrow(100);
        $data = $this->data((new DatabaseQueryTool($this->pdo))->execute(['sql' => 'SELECT * FROM items']));

        $this->assertSame(100, $data['count']);
        $this->assertCount(100, $data['rows']);
        $this->assertArrayNotHasKey('truncated', $data);
        $this->assertArrayNotHasKey('spill_path', $data);
    }

    public function test_wide_result_spills_and_returns_preview_with_path(): void
    {
        $this->seedWide(100);
        $json = (new DatabaseQueryTool($this->pdo))->execute(['sql' => 'SELECT * FROM posts']);
        $data = $this->data($json);

        $this->assertLessThan(9000, strlen($json));
        $this->assertTrue($data['truncated']);
        $this->assertSame(100, $data['total_rows']);
        $this->assertGreaterThan(0, $data['count']);
        $this->assertLessThan(100, $data['count']);
        $this->assertFileExists($data['spill_path']);
        $this->assertStringContainsString('shell_exec', $data['hint']);

        @unlink($data['spill_path']);
    }

    public function test_spill_file_holds_every_row_as_json_lines(): void
    {
        $this->seedWide(100);
        $data = $this->data((new DatabaseQueryTool($this->pdo))->execute(['sql' => 'SELECT * FROM posts ORDER BY id']));
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($data['spill_path']))));

        $this->assertCount(100, $lines);
        $this->assertSame(0, json_decode($lines[0], true)['id']);
        $this->assertSame('Post 0', json_decode($lines[0], true)['title']);
        $this->assertSame(99, json_decode($lines[99], true)['id']);

        @unlink($data['spill_path']);
    }

    public function test_spill_path_is_readable_by_shell_exec_head(): void
    {
        $this->seedWide(100);
        $data = $this->data((new DatabaseQueryTool($this->pdo))->execute(['sql' => 'SELECT * FROM posts']));

        $out = (new ShellTool(allowlist: ['head']))->execute(['command' => 'head -n 1 '.$data['spill_path']]);
        $stdout = (string) (json_decode($out, true)['data']['output'] ?? '');
        $rows = array_values(array_filter(explode("\n", $stdout)));

        $this->assertCount(1, $rows);
        $this->assertSame(0, json_decode($rows[0], true)['id']);
        $this->assertStringNotContainsString('[truncated', $stdout);

        @unlink($data['spill_path']);
    }

    public function test_write_failure_falls_back_to_inline_preview(): void
    {
        $this->seedWide(100);
        $data = $this->data((new DatabaseQueryTool($this->pdo, '/nonexistent-phpclaw-dir'))->execute(['sql' => 'SELECT * FROM posts']));

        $this->assertTrue($data['truncated']);
        $this->assertArrayNotHasKey('spill_path', $data);
        $this->assertStringContainsString('more rows discarded', $data['hint']);
        $this->assertGreaterThan(0, $data['count']);
    }

    public function test_description_mentions_the_spill(): void
    {
        $this->assertStringContainsString('spill_path', (new DatabaseQueryTool($this->pdo))->description());
    }
}

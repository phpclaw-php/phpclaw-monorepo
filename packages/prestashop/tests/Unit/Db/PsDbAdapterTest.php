<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Db;

use PhpClaw\PrestaShop\Contracts\PsDbInterface;
use PhpClaw\PrestaShop\Db\PsDbAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PsDbAdapter::class)]
final class PsDbAdapterTest extends TestCase
{
    private function makeDb(array $config = []): object
    {
        $executeS = $config['executeS'] ?? [];
        $execute = $config['execute'] ?? true;
        $escape = $config['escape'] ?? null;

        $db = new class($executeS, $execute, $escape)
        {
            public function __construct(
                private array $executeS,
                private bool $execute,
                private ?string $escapeReturn,
            ) {}

            public function executeS(string $sql): array
            {
                return $this->executeS;
            }

            public function execute(string $sql): bool
            {
                return $this->execute;
            }

            public function escape(string $value): string
            {
                return $this->escapeReturn ?? addslashes($value);
            }
        };

        return $db;
    }

    public function test_escape_delegates_to_db(): void
    {
        $db = $this->makeDb(['escape' => 'escaped_value']);
        $adapter = new PsDbAdapter($db);

        self::assertSame('escaped_value', $adapter->escape('raw value'));
    }

    public function test_query_select_returns_result_object(): void
    {
        $rows = [
            ['id' => '01', 'name' => 'Widget'],
            ['id' => '02', 'name' => 'Gadget'],
        ];
        $db = $this->makeDb(['executeS' => $rows]);
        $adapter = new PsDbAdapter($db);

        $result = $adapter->query('SELECT id, name FROM ps_product');

        self::assertIsObject($result);
        self::assertCount(2, $result->rows);
        self::assertSame($rows[0], $result->row);
        self::assertSame(2, $result->num_rows);
    }

    public function test_query_select_returns_first_row(): void
    {
        $rows = [['id' => '42', 'title' => 'First']];
        $db = $this->makeDb(['executeS' => $rows]);
        $adapter = new PsDbAdapter($db);

        $result = $adapter->query('SELECT id, title FROM ps_order');

        self::assertSame(['id' => '42', 'title' => 'First'], $result->row);
    }

    public function test_query_select_with_no_rows(): void
    {
        $db = $this->makeDb(['executeS' => []]);
        $adapter = new PsDbAdapter($db);

        $result = $adapter->query('SELECT * FROM ps_product WHERE id = ?', ['999']);

        self::assertSame([], $result->rows);
        self::assertSame([], $result->row);
        self::assertSame(0, $result->num_rows);
    }

    public function test_query_insert_uses_execute(): void
    {
        $db = $this->makeDb(['execute' => true]);
        $adapter = new PsDbAdapter($db);

        $result = $adapter->query("INSERT INTO ps_phpclaw_memory (id) VALUES ('abc')");

        self::assertSame([], $result->rows);
        self::assertSame(0, $result->num_rows);
    }

    public function test_query_update_uses_execute(): void
    {
        $db = $this->makeDb(['execute' => true]);
        $adapter = new PsDbAdapter($db);

        $result = $adapter->query("UPDATE ps_phpclaw_memory SET value = 'x' WHERE id = 'y'");

        self::assertSame([], $result->rows);
    }

    public function test_query_delete_uses_execute(): void
    {
        $db = $this->makeDb(['execute' => true]);
        $adapter = new PsDbAdapter($db);

        $result = $adapter->query("DELETE FROM ps_phpclaw_memory WHERE id = 'z'");

        self::assertSame([], $result->rows);
    }

    public function test_query_binds_string_param(): void
    {
        $capturedSql = '';
        $db = new class($capturedSql)
        {
            public string $lastSql = '';

            public function executeS(string $sql): array
            {
                $this->lastSql = $sql;

                return [];
            }

            public function execute(string $sql): bool
            {
                return true;
            }

            public function escape(string $value): string
            {
                return addslashes($value);
            }
        };

        $adapter = new PsDbAdapter($db);
        $adapter->query('SELECT * FROM ps_product WHERE name = ?', ["O'Brien"]);

        self::assertStringContainsString("O\\'Brien", $db->lastSql);
    }

    public function test_query_binds_null_param(): void
    {
        $capturedSql = '';
        $db = new class($capturedSql)
        {
            public string $lastSql = '';

            public function executeS(string $sql): array
            {
                $this->lastSql = $sql;

                return [];
            }

            public function execute(string $sql): bool
            {
                return true;
            }

            public function escape(string $value): string
            {
                return addslashes($value);
            }
        };

        $adapter = new PsDbAdapter($db);
        $adapter->query('SELECT * FROM t WHERE expires_at = ?', [null]);

        self::assertStringContainsString('NULL', $db->lastSql);
    }

    public function test_query_binds_bool_true_as_one(): void
    {
        $db = new class
        {
            public string $lastSql = '';

            public function executeS(string $sql): array
            {
                $this->lastSql = $sql;

                return [];
            }

            public function execute(string $sql): bool
            {
                return true;
            }

            public function escape(string $value): string
            {
                return $value;
            }
        };

        $adapter = new PsDbAdapter($db);
        $adapter->query('SELECT * FROM t WHERE active = ?', [true]);

        self::assertStringContainsString('1', $db->lastSql);
    }

    public function test_query_binds_bool_false_as_zero(): void
    {
        $db = new class
        {
            public string $lastSql = '';

            public function executeS(string $sql): array
            {
                $this->lastSql = $sql;

                return [];
            }

            public function execute(string $sql): bool
            {
                return true;
            }

            public function escape(string $value): string
            {
                return $value;
            }
        };

        $adapter = new PsDbAdapter($db);
        $adapter->query('SELECT * FROM t WHERE active = ?', [false]);

        self::assertStringContainsString('0', $db->lastSql);
    }

    public function test_query_binds_integer_param(): void
    {
        $db = new class
        {
            public string $lastSql = '';

            public function executeS(string $sql): array
            {
                $this->lastSql = $sql;

                return [];
            }

            public function execute(string $sql): bool
            {
                return true;
            }

            public function escape(string $value): string
            {
                return $value;
            }
        };

        $adapter = new PsDbAdapter($db);
        $adapter->query('SELECT * FROM ps_orders WHERE id_order = ?', [42]);

        self::assertStringContainsString('42', $db->lastSql);
    }

    public function test_query_binds_float_param(): void
    {
        $db = new class
        {
            public string $lastSql = '';

            public function executeS(string $sql): array
            {
                $this->lastSql = $sql;

                return [];
            }

            public function execute(string $sql): bool
            {
                return true;
            }

            public function escape(string $value): string
            {
                return $value;
            }
        };

        $adapter = new PsDbAdapter($db);
        $adapter->query('SELECT * FROM ps_orders WHERE total > ?', [9.99]);

        self::assertStringContainsString('9.99', $db->lastSql);
    }

    public function test_query_show_is_treated_as_read(): void
    {
        $rows = [['Tables_in_ps' => 'ps_phpclaw_memory']];
        $db = $this->makeDb(['executeS' => $rows]);
        $adapter = new PsDbAdapter($db);

        $result = $adapter->query('SHOW TABLES LIKE "ps_phpclaw%"');

        self::assertCount(1, $result->rows);
    }

    public function test_query_explain_is_treated_as_read(): void
    {
        $rows = [['id' => 1, 'select_type' => 'SIMPLE']];
        $db = $this->makeDb(['executeS' => $rows]);
        $adapter = new PsDbAdapter($db);

        $result = $adapter->query('EXPLAIN SELECT * FROM ps_product');

        self::assertCount(1, $result->rows);
    }

    public function test_query_select_with_leading_parens(): void
    {
        $rows = [['c' => '5']];
        $db = $this->makeDb(['executeS' => $rows]);
        $adapter = new PsDbAdapter($db);

        $result = $adapter->query('(SELECT COUNT(*) AS c FROM ps_product)');

        self::assertSame(1, $result->num_rows);
    }

    public function test_query_returns_stdclass_with_rows_row_and_num_rows(): void
    {
        $db = $this->makeDb(['executeS' => [['id' => 1], ['id' => 2]]]);
        $adapter = new PsDbAdapter($db);

        $result = $adapter->query('SELECT 1');

        self::assertSame([['id' => 1], ['id' => 2]], $result->rows);
        self::assertSame(['id' => 1], $result->row);
        self::assertSame(2, $result->num_rows);
    }

    public function test_query_multiple_placeholders(): void
    {
        $db = new class
        {
            public string $lastSql = '';

            public function executeS(string $sql): array
            {
                $this->lastSql = $sql;

                return [];
            }

            public function execute(string $sql): bool
            {
                return true;
            }

            public function escape(string $value): string
            {
                return $value;
            }
        };

        $adapter = new PsDbAdapter($db);
        $adapter->query('SELECT * FROM t WHERE ns = ? AND lookup_key = ?', ['default', 'my_key']);

        self::assertStringContainsString("'default'", $db->lastSql);
        self::assertStringContainsString("'my_key'", $db->lastSql);
    }

    public function test_implements_ps_db_interface(): void
    {
        $db = $this->makeDb();
        $adapter = new PsDbAdapter($db);

        self::assertInstanceOf(PsDbInterface::class, $adapter);
    }
}

<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tests\Unit\Memory;

use PhpClaw\OpenCart\Memory\OcDbMemory;
use PhpClaw\OpenCart\Tests\Helpers\MysqliOcDb;
use PhpClaw\OpenCart\Tests\Helpers\OcDbTestCase;

final class OpenCartMemoryTest extends OcDbTestCase
{
    private OcDbMemory $memory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetTables([
            "CREATE TABLE IF NOT EXISTS `{$this->prefix}phpclaw_memory` (
                id          VARCHAR(26)  NOT NULL,
                namespace   VARCHAR(100) NOT NULL DEFAULT 'default',
                lookup_key  VARCHAR(255) NOT NULL,
                value       LONGTEXT     NOT NULL,
                expires_at  DATETIME     NULL,
                created_at  DATETIME     NOT NULL,
                updated_at  DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_ns_key (namespace, lookup_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ]);

        $this->memory = new OcDbMemory($this->db, $this->prefix);
    }

    public function test_get_returns_null_for_missing_key(): void
    {
        self::assertNull($this->memory->get('missing'));
    }

    public function test_get_returns_stored_string(): void
    {
        $this->memory->set('greeting', 'hello opencart');

        self::assertSame('hello opencart', $this->memory->get('greeting'));
    }

    public function test_get_returns_stored_array(): void
    {
        $this->memory->set('config', ['version' => 4, 'debug' => false]);

        self::assertSame(['version' => 4, 'debug' => false], $this->memory->get('config'));
    }

    public function test_get_respects_namespace_isolation(): void
    {
        $this->memory->set('key', 'ns1_value', 'ns1');
        $this->memory->set('key', 'ns2_value', 'ns2');

        self::assertSame('ns1_value', $this->memory->get('key', 'ns1'));
        self::assertSame('ns2_value', $this->memory->get('key', 'ns2'));
    }

    public function test_get_returns_null_for_expired_key(): void
    {
        $this->memory->set('gone', 'old', 'default', -1);

        self::assertNull($this->memory->get('gone'));
    }

    public function test_get_returns_value_for_live_ttl(): void
    {
        $this->memory->set('live', 'fresh', 'default', 3600);

        self::assertSame('fresh', $this->memory->get('live'));
    }

    public function test_set_inserts_new_key(): void
    {
        $this->memory->set('new_key', 'value');

        self::assertSame('value', $this->memory->get('new_key'));
    }

    public function test_set_updates_existing_key(): void
    {
        $this->memory->set('updatable', 'first');
        $this->memory->set('updatable', 'second');

        self::assertSame('second', $this->memory->get('updatable'));
    }

    public function test_set_serializes_array(): void
    {
        $this->memory->set('arr', ['x' => 1]);

        self::assertSame(['x' => 1], $this->memory->get('arr'));
    }

    public function test_uses_table_prefix(): void
    {
        $host = (string) (getenv('PHPCLAW_TEST_DB_HOST') ?: '127.0.0.1');
        $user = (string) (getenv('PHPCLAW_TEST_DB_USER') ?: 'root');
        $pass = (string) (getenv('PHPCLAW_TEST_DB_PASS') ?: '');
        $dbName = (string) (getenv('PHPCLAW_TEST_DB_NAME') ?: 'phpclaw_oc_unit_test');

        $conn = new \mysqli($host, $user, $pass, $dbName);
        $conn->set_charset('utf8mb4');
        $conn->query('CREATE TABLE IF NOT EXISTS myprefix_phpclaw_memory (
            id          VARCHAR(26)  NOT NULL,
            namespace   VARCHAR(100) NOT NULL DEFAULT \'default\',
            lookup_key  VARCHAR(255) NOT NULL,
            value       LONGTEXT     NOT NULL,
            expires_at  DATETIME     NULL,
            created_at  DATETIME     NOT NULL,
            updated_at  DATETIME     NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_ns_key (namespace, lookup_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        $memory = new OcDbMemory(new MysqliOcDb($conn), 'myprefix_');
        $memory->set('k', 'v');

        self::assertSame('v', $memory->get('k'));

        $conn->query('DROP TABLE IF EXISTS myprefix_phpclaw_memory');
        $conn->close();
    }

    public function test_forget_removes_key(): void
    {
        $this->memory->set('to_delete', 'bye');
        $this->memory->forget('to_delete');

        self::assertNull($this->memory->get('to_delete'));
    }

    public function test_forget_is_namespace_scoped(): void
    {
        $this->memory->set('shared', 'ns1', 'ns1');
        $this->memory->set('shared', 'ns2', 'ns2');
        $this->memory->forget('shared', 'ns1');

        self::assertNull($this->memory->get('shared', 'ns1'));
        self::assertSame('ns2', $this->memory->get('shared', 'ns2'));
    }

    public function test_flush_clears_namespace(): void
    {
        $this->memory->set('a', '1', 'ns');
        $this->memory->set('b', '2', 'ns');
        $this->memory->flush('ns');

        self::assertNull($this->memory->get('a', 'ns'));
        self::assertNull($this->memory->get('b', 'ns'));
    }

    public function test_flush_does_not_affect_other_namespaces(): void
    {
        $this->memory->set('x', 'safe', 'other');
        $this->memory->flush('ns');

        self::assertSame('safe', $this->memory->get('x', 'other'));
    }

    public function test_all_returns_empty_for_unknown_namespace(): void
    {
        self::assertSame([], $this->memory->all('empty'));
    }

    public function test_all_returns_non_expired_entries(): void
    {
        $this->memory->set('one', 'val1', 'ns');
        $this->memory->set('two', 'val2', 'ns');
        $this->memory->set('exp', 'old', 'ns', -1);

        $result = $this->memory->all('ns');

        self::assertCount(2, $result);
        self::assertSame('val1', $result['one']);
        self::assertSame('val2', $result['two']);
        self::assertArrayNotHasKey('exp', $result);
    }

    public function test_all_deserializes_values(): void
    {
        $this->memory->set('prefs', ['theme' => 'light'], 'ui');

        self::assertSame(['theme' => 'light'], $this->memory->all('ui')['prefs']);
    }

    public function test_has_returns_true_for_existing_key(): void
    {
        $this->memory->set('present', 'yes');

        self::assertTrue($this->memory->has('present'));
    }

    public function test_has_returns_false_for_missing_key(): void
    {
        self::assertFalse($this->memory->has('absent'));
    }

    public function test_has_returns_false_for_expired_key(): void
    {
        $this->memory->set('exp', 'old', 'default', -1);

        self::assertFalse($this->memory->has('exp'));
    }
}

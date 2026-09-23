<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tests\Unit\Memory;

use PhpClaw\OpenCart\Memory\OcDbMemory;
use PhpClaw\OpenCart\Tests\Helpers\OcDbTestCase;

final class OcDbMemoryTest extends OcDbTestCase
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

    public function test_set_and_get_string(): void
    {
        $this->memory->set('greeting', 'hello');
        self::assertSame('hello', $this->memory->get('greeting'));
    }

    public function test_set_and_get_array(): void
    {
        $this->memory->set('config', ['key' => 'value']);
        self::assertSame(['key' => 'value'], $this->memory->get('config'));
    }

    public function test_upsert_updates_existing_key(): void
    {
        $this->memory->set('k', 'first');
        $this->memory->set('k', 'second');
        self::assertSame('second', $this->memory->get('k'));
    }

    public function test_all_returns_namespace_entries(): void
    {
        $this->memory->set('a', '1', 'ns');
        $this->memory->set('b', '2', 'ns');
        $result = $this->memory->all('ns');
        self::assertCount(2, $result);
    }

    public function test_null_db_returns_null_on_get(): void
    {
        $mem = new OcDbMemory(null, $this->prefix);
        self::assertNull($mem->get('k'));
    }

    public function test_flush_removes_all_keys_in_namespace(): void
    {
        $this->memory->set('x', 'one', 'flush-ns');
        $this->memory->set('y', 'two', 'flush-ns');
        $this->memory->flush('flush-ns');
        self::assertSame([], $this->memory->all('flush-ns'));
    }

    public function test_null_db_flush_is_silent(): void
    {
        $mem = new OcDbMemory(null, $this->prefix);
        $mem->flush('default');

        self::assertSame([], $mem->all('ns'), 'a null db must read back as empty, not crash');
    }

    public function test_null_db_forget_is_silent(): void
    {
        $mem = new OcDbMemory(null, $this->prefix);
        $mem->forget('key');

        self::assertNull($mem->get('key'), 'a null db must read back as empty, not crash');
    }

    public function test_null_db_all_returns_empty(): void
    {
        $mem = new OcDbMemory(null, $this->prefix);
        self::assertSame([], $mem->all());
    }
}

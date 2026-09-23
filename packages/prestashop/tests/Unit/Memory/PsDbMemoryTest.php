<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Memory;

use PhpClaw\PrestaShop\Memory\PsDbMemory;
use PhpClaw\PrestaShop\Tests\Helpers\PsDbTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(PsDbMemory::class)]
final class PsDbMemoryTest extends PsDbTestCase
{
    private PsDbMemory $memory;

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

        $this->memory = new PsDbMemory($this->db, $this->prefix);
    }

    public function test_get_returns_null_for_missing_key(): void
    {
        self::assertNull($this->memory->get('missing'));
    }

    public function test_set_and_get_string(): void
    {
        $this->memory->set('hello', 'world');

        self::assertSame('world', $this->memory->get('hello'));
    }

    public function test_set_and_get_array(): void
    {
        $this->memory->set('data', ['foo' => 'bar']);

        self::assertSame(['foo' => 'bar'], $this->memory->get('data'));
    }

    public function test_set_overwrites_existing_value(): void
    {
        $this->memory->set('k', 'v1');
        $this->memory->set('k', 'v2');

        self::assertSame('v2', $this->memory->get('k'));
    }

    public function test_forget_removes_key(): void
    {
        $this->memory->set('remove_me', 'gone');
        $this->memory->forget('remove_me');

        self::assertNull($this->memory->get('remove_me'));
    }

    public function test_forget_non_existent_does_not_throw(): void
    {
        $this->memory->forget('ghost');

        self::assertNull($this->memory->get('ghost'), 'forgetting an absent key leaves it absent');
    }

    public function test_flush_removes_all_keys_in_namespace(): void
    {
        $this->memory->set('a', 'va', 'flush_ns');
        $this->memory->set('b', 'vb', 'flush_ns');
        $this->memory->set('keep', 'vk', 'other_ns');

        $this->memory->flush('flush_ns');

        self::assertNull($this->memory->get('a', 'flush_ns'));
        self::assertSame('vk', $this->memory->get('keep', 'other_ns'));
    }

    public function test_all_returns_map_for_namespace(): void
    {
        $this->memory->set('p', 'pv', 'allns');
        $this->memory->set('q', 'qv', 'allns');

        self::assertSame(['p' => 'pv', 'q' => 'qv'], $this->memory->all('allns'));
    }

    public function test_has_returns_true_for_existing_key(): void
    {
        $this->memory->set('present', 'yes');

        self::assertTrue($this->memory->has('present'));
    }

    public function test_has_returns_false_for_missing_key(): void
    {
        self::assertFalse($this->memory->has('nope'));
    }

    public function test_namespace_isolation(): void
    {
        $this->memory->set('key', 'ns_a_val', 'ns_a');
        $this->memory->set('key', 'ns_b_val', 'ns_b');

        self::assertSame('ns_a_val', $this->memory->get('key', 'ns_a'));
        self::assertSame('ns_b_val', $this->memory->get('key', 'ns_b'));
    }

    public function test_expired_key_returns_null(): void
    {
        $this->memory->set('exp_key', 'old', 'default', -10);

        self::assertNull($this->memory->get('exp_key'));
    }

    public function test_all_excludes_expired_keys(): void
    {
        $this->memory->set('fresh', 'fv', 'expns', 9999);
        $this->memory->set('stale', 'sv', 'expns', -10);

        $all = $this->memory->all('expns');

        self::assertArrayHasKey('fresh', $all);
        self::assertArrayNotHasKey('stale', $all);
    }
}

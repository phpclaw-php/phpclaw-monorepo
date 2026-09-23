<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Memory;

use PhpClaw\PrestaShop\Memory\PsSettingMemory;
use PhpClaw\PrestaShop\Tests\Helpers\PsDbTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(PsSettingMemory::class)]
final class PsSettingMemoryTest extends PsDbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->resetTables([
            "CREATE TABLE IF NOT EXISTS `{$this->prefix}configuration` (
                id_configuration INT          NOT NULL AUTO_INCREMENT,
                name             VARCHAR(254) NOT NULL DEFAULT '',
                value            TEXT,
                date_add         DATETIME     NOT NULL DEFAULT '2024-01-01 00:00:00',
                date_upd         DATETIME     NOT NULL DEFAULT '2024-01-01 00:00:00',
                PRIMARY KEY (id_configuration),
                UNIQUE KEY uq_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ]);
    }

    public function test_get_returns_null_for_missing_key(): void
    {
        $memory = new PsSettingMemory($this->db, $this->prefix);

        self::assertNull($memory->get('nonexistent'));
    }

    public function test_set_and_get_string_value(): void
    {
        $memory = new PsSettingMemory($this->db, $this->prefix);
        $memory->set('greeting', 'Hello World');

        self::assertSame('Hello World', $memory->get('greeting'));
    }

    public function test_set_and_get_array_value(): void
    {
        $memory = new PsSettingMemory($this->db, $this->prefix);
        $memory->set('arr', ['a' => 1, 'b' => 2]);

        self::assertSame(['a' => 1, 'b' => 2], $memory->get('arr'));
    }

    public function test_set_overwrites_existing_value(): void
    {
        $memory = new PsSettingMemory($this->db, $this->prefix);
        $memory->set('key', 'first');
        $memory->set('key', 'second');

        self::assertSame('second', $memory->get('key'));
    }

    public function test_forget_removes_key(): void
    {
        $memory = new PsSettingMemory($this->db, $this->prefix);
        $memory->set('temp', 'value');
        $memory->forget('temp');

        self::assertNull($memory->get('temp'));
    }

    public function test_forget_non_existent_key_does_not_throw(): void
    {
        $memory = new PsSettingMemory($this->db, $this->prefix);
        $memory->forget('ghost');

        self::assertNull($memory->get('ghost'), 'forgetting an absent key leaves it absent');
    }

    public function test_flush_removes_all_keys_in_namespace(): void
    {
        $memory = new PsSettingMemory($this->db, $this->prefix);
        $memory->set('k1', 'v1', 'ns1');
        $memory->set('k2', 'v2', 'ns1');
        $memory->set('other', 'stays', 'ns2');

        $memory->flush('ns1');

        self::assertNull($memory->get('k1', 'ns1'));
        self::assertNull($memory->get('k2', 'ns1'));
        self::assertSame('stays', $memory->get('other', 'ns2'));
    }

    public function test_all_returns_all_keys_in_namespace(): void
    {
        $memory = new PsSettingMemory($this->db, $this->prefix);
        $memory->set('x', 'xval', 'myns');
        $memory->set('y', 'yval', 'myns');

        self::assertSame(['x' => 'xval', 'y' => 'yval'], $memory->all('myns'));
    }

    public function test_has_returns_true_for_existing_key(): void
    {
        $memory = new PsSettingMemory($this->db, $this->prefix);
        $memory->set('exists', true);

        self::assertTrue($memory->has('exists'));
    }

    public function test_has_returns_false_for_missing_key(): void
    {
        $memory = new PsSettingMemory($this->db, $this->prefix);

        self::assertFalse($memory->has('absent'));
    }

    public function test_namespace_isolation(): void
    {
        $memory = new PsSettingMemory($this->db, $this->prefix);
        $memory->set('key', 'ns_a_val', 'ns_a');
        $memory->set('key', 'ns_b_val', 'ns_b');

        self::assertSame('ns_a_val', $memory->get('key', 'ns_a'));
        self::assertSame('ns_b_val', $memory->get('key', 'ns_b'));
    }

    public function test_null_pdo_returns_null_on_get(): void
    {
        $memory = new PsSettingMemory(null, $this->prefix);

        self::assertNull($memory->get('anything'));
    }

    public function test_null_pdo_set_does_not_throw(): void
    {
        $memory = new PsSettingMemory(null, $this->prefix);
        $memory->set('key', 'value');

        self::assertNull($memory->get('key'), 'set() with no pdo must store nothing');
    }

    public function test_ttl_expiry_returns_null_after_expiry(): void
    {
        $memory = new PsSettingMemory($this->db, $this->prefix);
        $memory->set('expired_key', 'old_value', 'default', -1);

        self::assertNull($memory->get('expired_key'));
    }
}

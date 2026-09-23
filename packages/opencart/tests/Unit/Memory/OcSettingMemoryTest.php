<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tests\Unit\Memory;

use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\OpenCart\Memory\OcSettingMemory;
use PhpClaw\OpenCart\Tests\Helpers\MysqliOcDb;
use PhpClaw\OpenCart\Tests\Helpers\OcDbTestCase;

final class OcSettingMemoryTest extends OcDbTestCase
{
    private function settingDdl(): string
    {
        return "CREATE TABLE IF NOT EXISTS `{$this->prefix}setting` (
            setting_id INT NOT NULL AUTO_INCREMENT,
            store_id   INT NOT NULL DEFAULT 0,
            code       VARCHAR(255) NOT NULL DEFAULT '',
            `key`      VARCHAR(255) NOT NULL DEFAULT '',
            value      LONGTEXT     NOT NULL,
            serialized INT NOT NULL DEFAULT 0,
            PRIMARY KEY (setting_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    private function makeDb(): MysqliOcDb
    {
        $this->resetTables([$this->settingDdl()]);

        return $this->db;
    }

    public function test_implements_memory_interface(): void
    {
        $mem = new OcSettingMemory($this->makeDb(), $this->prefix);
        self::assertInstanceOf(MemoryInterface::class, $mem);
    }

    public function test_class_is_final(): void
    {
        $ref = new \ReflectionClass(OcSettingMemory::class);
        self::assertTrue($ref->isFinal());
    }

    public function test_set_and_get_round_trip(): void
    {
        $mem = new OcSettingMemory($this->makeDb(), $this->prefix);
        $mem->set('alpha', ['n' => 1], 'demo');

        self::assertSame(['n' => 1], $mem->get('alpha', 'demo'));
    }

    public function test_get_missing_returns_null(): void
    {
        $mem = new OcSettingMemory($this->makeDb(), $this->prefix);
        self::assertNull($mem->get('missing', 'demo'));
    }

    public function test_get_without_pdo_returns_null(): void
    {
        $mem = new OcSettingMemory(null, $this->prefix);
        self::assertNull($mem->get('anything'));
    }

    public function test_set_without_pdo_is_noop(): void
    {
        $mem = new OcSettingMemory(null, $this->prefix);
        $mem->set('k', 'v');
        self::assertNull($mem->get('k'));
    }

    public function test_forget_removes_key(): void
    {
        $mem = new OcSettingMemory($this->makeDb(), $this->prefix);
        $mem->set('k', 'v', 'ns');
        self::assertSame('v', $mem->get('k', 'ns'));
        $mem->forget('k', 'ns');
        self::assertNull($mem->get('k', 'ns'));
    }

    public function test_has_returns_true_when_present(): void
    {
        $mem = new OcSettingMemory($this->makeDb(), $this->prefix);
        $mem->set('present', 1, 'ns');
        self::assertTrue($mem->has('present', 'ns'));
    }

    public function test_has_returns_false_when_missing(): void
    {
        $mem = new OcSettingMemory($this->makeDb(), $this->prefix);
        self::assertFalse($mem->has('nope', 'ns'));
    }

    public function test_flush_clears_namespace(): void
    {
        $mem = new OcSettingMemory($this->makeDb(), $this->prefix);
        $mem->set('a', 1, 'ns');
        $mem->set('b', 2, 'ns');
        $mem->set('z', 99, 'other');

        $mem->flush('ns');

        self::assertNull($mem->get('a', 'ns'));
        self::assertNull($mem->get('b', 'ns'));
        self::assertSame(99, $mem->get('z', 'other'));
    }

    public function test_all_returns_array_for_namespace(): void
    {
        $mem = new OcSettingMemory($this->makeDb(), $this->prefix);
        $mem->set('one', 1, 'pack');
        $mem->set('two', 2, 'pack');

        $all = $mem->all('pack');
        self::assertArrayHasKey('one', $all);
        self::assertArrayHasKey('two', $all);
        self::assertSame(1, $all['one']);
    }

    public function test_set_with_ttl_expires_after_lookup(): void
    {
        $mem = new OcSettingMemory($this->makeDb(), $this->prefix);
        $mem->set('temp', 'gone', 'ns', 1);
        self::assertSame('gone', $mem->get('temp', 'ns'));
        sleep(2);
        self::assertNull($mem->get('temp', 'ns'));
    }

    public function test_has_without_pdo_returns_false(): void
    {
        $mem = new OcSettingMemory(null, $this->prefix);
        self::assertFalse($mem->has('whatever'));
    }

    public function test_all_without_pdo_returns_empty_array(): void
    {
        $mem = new OcSettingMemory(null, $this->prefix);
        self::assertSame([], $mem->all('ns'));
    }

    public function test_forget_without_pdo_is_noop(): void
    {
        $mem = new OcSettingMemory(null, $this->prefix);
        $mem->forget('k');

        self::assertNull($mem->get('key'), 'a null pdo must read back as empty, not crash');
    }

    public function test_flush_without_pdo_is_noop(): void
    {
        $mem = new OcSettingMemory(null, $this->prefix);
        $mem->flush('ns');

        self::assertSame([], $mem->all('ns'), 'a null pdo must read back as empty, not crash');
    }

    public function test_get_returns_null_when_envelope_has_invalid_format(): void
    {
        $db = $this->makeDb();

        $mem = new OcSettingMemory($db, $this->prefix);
        $ocKey = 'mem_'.rawurlencode('ns').'_'.rawurlencode('badkey');

        $escaped = $db->escape($ocKey);
        $table = $this->prefix.'setting';
        $this->seed("INSERT INTO `{$table}` (store_id, code, `key`, value, serialized)
            VALUES (0, 'phpclaw', '$escaped', '{\"wrong\":1}', 0)");

        self::assertNull($mem->get('badkey', 'ns'));
    }

    public function test_set_update_path_when_key_already_exists(): void
    {
        $mem = new OcSettingMemory($this->makeDb(), $this->prefix);
        $mem->set('upd', 'first', 'ns');
        $mem->set('upd', 'second', 'ns');
        self::assertSame('second', $mem->get('upd', 'ns'));
    }

    public function test_all_skips_rows_with_invalid_envelope(): void
    {
        $db = $this->makeDb();

        $mem = new OcSettingMemory($db, $this->prefix);
        $prefix = 'mem_'.rawurlencode('ns').'_';
        $key = $db->escape($prefix.'corrupted');
        $table = $this->prefix.'setting';
        $this->seed("INSERT INTO `{$table}` (store_id, code, `key`, value, serialized)
            VALUES (0, 'phpclaw', '$key', 'not-json-envelope', 0)");

        $mem->set('valid', 99, 'ns');
        $all = $mem->all('ns');
        self::assertArrayHasKey('valid', $all);
        self::assertArrayNotHasKey('corrupted', $all);
    }

    public function test_all_skips_expired_rows(): void
    {
        $db = $this->makeDb();

        $mem = new OcSettingMemory($db, $this->prefix);
        $prefix = 'mem_'.rawurlencode('ns').'_';
        $expiredEnvelope = json_encode(['v' => base64_encode(serialize('old')), 'e' => 1]);
        $key = $db->escape($prefix.'expired');
        $val = $db->escape((string) $expiredEnvelope);
        $table = $this->prefix.'setting';
        $this->seed("INSERT INTO `{$table}` (store_id, code, `key`, value, serialized)
            VALUES (0, 'phpclaw', '$key', '$val', 0)");

        $mem->set('fresh', 'ok', 'ns');
        $all = $mem->all('ns');
        self::assertArrayHasKey('fresh', $all);
        self::assertArrayNotHasKey('expired', $all);
    }

    public function test_decode_envelope_returns_null_when_v_key_missing(): void
    {
        $mem = new OcSettingMemory(null, $this->prefix);
        $ref = new \ReflectionClass($mem);
        $method = $ref->getMethod('decodeEnvelope');
        $method->setAccessible(true);

        self::assertNull($method->invoke($mem, '{"e":null}'));
        self::assertNull($method->invoke($mem, '{"v":"abc"}'));
        self::assertNull($method->invoke($mem, '"just-a-string"'));
    }

    public function test_fetch_row_returns_null_when_no_matching_row(): void
    {
        $mem = new OcSettingMemory($this->makeDb(), $this->prefix);
        $ref = new \ReflectionClass($mem);
        $method = $ref->getMethod('fetchRow');
        $method->setAccessible(true);

        $result = $method->invoke($mem, 'nonexistent_oc_key');
        self::assertNull($result);
    }
}

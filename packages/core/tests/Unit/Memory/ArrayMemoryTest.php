<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Memory;

use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PHPUnit\Framework\TestCase;

final class ArrayMemoryTest extends TestCase
{
    private ArrayMemory $memory;

    protected function setUp(): void
    {
        HookRegistry::reset();
        $this->memory = new ArrayMemory;
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
    }

    public function test_implements_memory_interface(): void
    {
        $this->assertInstanceOf(MemoryInterface::class, $this->memory);
    }

    public function test_get_returns_null_for_missing_key(): void
    {
        $this->assertNull($this->memory->get('nonexistent'));
    }

    public function test_set_and_get_string_value(): void
    {
        $this->memory->set('name', 'Alice');
        $this->assertSame('Alice', $this->memory->get('name'));
    }

    public function test_set_and_get_array_value(): void
    {
        $this->memory->set('config', ['key' => 'value', 'count' => 42]);
        $this->assertSame(['key' => 'value', 'count' => 42], $this->memory->get('config'));
    }

    public function test_set_and_get_integer_value(): void
    {
        $this->memory->set('counter', 99);
        $this->assertSame(99, $this->memory->get('counter'));
    }

    public function test_set_and_get_null_value(): void
    {
        $this->memory->set('empty', null);
        $this->assertNull($this->memory->get('empty'));
    }

    public function test_set_overwrites_existing_value(): void
    {
        $this->memory->set('key', 'first');
        $this->memory->set('key', 'second');
        $this->assertSame('second', $this->memory->get('key'));
    }

    public function test_different_namespaces_are_isolated(): void
    {
        $this->memory->set('key', 'value-a', 'ns_a');
        $this->memory->set('key', 'value-b', 'ns_b');

        $this->assertSame('value-a', $this->memory->get('key', 'ns_a'));
        $this->assertSame('value-b', $this->memory->get('key', 'ns_b'));
    }

    public function test_default_namespace_is_isolated_from_named_namespace(): void
    {
        $this->memory->set('key', 'default-val');
        $this->memory->set('key', 'named-val', 'other');

        $this->assertSame('default-val', $this->memory->get('key'));
        $this->assertSame('named-val', $this->memory->get('key', 'other'));
    }

    public function test_has_returns_true_for_existing_key(): void
    {
        $this->memory->set('exists', 'yes');
        $this->assertTrue($this->memory->has('exists'));
    }

    public function test_has_returns_false_for_missing_key(): void
    {
        $this->assertFalse($this->memory->has('missing'));
    }

    public function test_has_is_namespace_aware(): void
    {
        $this->memory->set('key', 'val', 'ns_x');
        $this->assertTrue($this->memory->has('key', 'ns_x'));
        $this->assertFalse($this->memory->has('key', 'ns_y'));
    }

    public function test_forget_removes_key(): void
    {
        $this->memory->set('to_delete', 'bye');
        $this->memory->forget('to_delete');
        $this->assertNull($this->memory->get('to_delete'));
        $this->assertFalse($this->memory->has('to_delete'));
    }

    public function test_forget_only_removes_specified_namespace(): void
    {
        $this->memory->set('key', 'a', 'ns_a');
        $this->memory->set('key', 'b', 'ns_b');
        $this->memory->forget('key', 'ns_a');

        $this->assertNull($this->memory->get('key', 'ns_a'));
        $this->assertSame('b', $this->memory->get('key', 'ns_b'));
    }

    public function test_forget_nonexistent_key_does_not_throw(): void
    {
        $this->expectNotToPerformAssertions();
        $this->memory->forget('ghost');
    }

    public function test_flush_clears_all_keys_in_namespace(): void
    {
        $this->memory->set('a', '1');
        $this->memory->set('b', '2');
        $this->memory->set('c', '3');

        $this->memory->flush();

        $this->assertNull($this->memory->get('a'));
        $this->assertNull($this->memory->get('b'));
        $this->assertNull($this->memory->get('c'));
    }

    public function test_flush_only_affects_target_namespace(): void
    {
        $this->memory->set('key', 'keep', 'keep_ns');
        $this->memory->set('key', 'flush_me', 'flush_ns');

        $this->memory->flush('flush_ns');

        $this->assertSame('keep', $this->memory->get('key', 'keep_ns'));
        $this->assertNull($this->memory->get('key', 'flush_ns'));
    }

    public function test_all_returns_all_keys_in_namespace(): void
    {
        $this->memory->set('x', 1, 'ns');
        $this->memory->set('y', 2, 'ns');
        $this->memory->set('z', 3, 'ns');

        $all = $this->memory->all('ns');

        $this->assertSame(['x' => 1, 'y' => 2, 'z' => 3], $all);
    }

    public function test_all_returns_empty_for_empty_namespace(): void
    {
        $this->assertSame([], $this->memory->all('empty_ns'));
    }

    public function test_all_does_not_include_other_namespaces(): void
    {
        $this->memory->set('key', 'val', 'ns_a');
        $this->memory->set('other', 'other_val', 'ns_b');

        $all = $this->memory->all('ns_a');

        $this->assertArrayHasKey('key', $all);
        $this->assertArrayNotHasKey('other', $all);
    }

    public function test_ttl_entry_is_accessible_before_expiry(): void
    {
        $this->memory->set('temp', 'value', 'default', ttl: 60);
        $this->assertSame('value', $this->memory->get('temp'));
        $this->assertTrue($this->memory->has('temp'));
    }

    public function test_expired_entry_returns_null_on_get(): void
    {
        $this->memory->set('expired', 'old', 'default', ttl: -1);
        $this->assertNull($this->memory->get('expired'));
    }

    public function test_expired_entry_returns_false_on_has(): void
    {
        $this->memory->set('expired', 'old', 'default', ttl: -1);
        $this->assertFalse($this->memory->has('expired'));
    }

    public function test_expired_entry_excluded_from_all(): void
    {
        $this->memory->set('live', 'yes', 'ns', ttl: 60);
        $this->memory->set('dead', 'no', 'ns', ttl: -1);

        $all = $this->memory->all('ns');

        $this->assertArrayHasKey('live', $all);
        $this->assertArrayNotHasKey('dead', $all);
    }

    public function test_no_ttl_entry_never_expires(): void
    {
        $this->memory->set('permanent', 'forever');
        $this->assertSame('forever', $this->memory->get('permanent'));
    }

    public function test_set_fires_memory_write_event(): void
    {
        $captured = null;
        HookRegistry::on('memory.write', function (array $ctx) use (&$captured): void {
            $captured = $ctx;
        });

        $this->memory->set('my_key', 'some value', 'my_ns', ttl: 60);

        $this->assertNotNull($captured, 'memory.write did not fire');
        $this->assertSame('my_key', $captured['key']);
        $this->assertSame('my_ns', $captured['namespace']);
        $this->assertSame('array', $captured['driver']);
        $this->assertSame(60, $captured['ttl']);
        $this->assertArrayNotHasKey('value', $captured);
    }

    public function test_get_fires_memory_read_event_with_hit_true_when_found(): void
    {
        $this->memory->set('found_key', 'value');

        $captured = null;
        HookRegistry::on('memory.read', function (array $ctx) use (&$captured): void {
            $captured = $ctx;
        });

        $this->memory->get('found_key');

        $this->assertNotNull($captured, 'memory.read did not fire');
        $this->assertSame('found_key', $captured['key']);
        $this->assertSame('array', $captured['driver']);
        $this->assertTrue($captured['hit']);
        $this->assertArrayNotHasKey('value', $captured);
    }

    public function test_get_fires_memory_read_event_with_hit_false_when_missing(): void
    {
        $captured = null;
        HookRegistry::on('memory.read', function (array $ctx) use (&$captured): void {
            $captured = $ctx;
        });

        $this->memory->get('missing_key');

        $this->assertNotNull($captured);
        $this->assertFalse($captured['hit']);
    }

    public function test_get_fires_memory_read_event_with_hit_false_when_expired(): void
    {
        $this->memory->set('expired_key', 'value', 'default', ttl: -1);

        $captured = null;
        HookRegistry::on('memory.read', function (array $ctx) use (&$captured): void {
            $captured = $ctx;
        });

        $this->memory->get('expired_key');

        $this->assertNotNull($captured);
        $this->assertFalse($captured['hit']);
    }

    public function test_forget_fires_memory_forget_event(): void
    {
        $this->memory->set('doomed', 'value');

        $captured = null;
        HookRegistry::on('memory.forget', function (array $ctx) use (&$captured): void {
            $captured = $ctx;
        });

        $this->memory->forget('doomed');

        $this->assertNotNull($captured, 'memory.forget did not fire');
        $this->assertSame('doomed', $captured['key']);
        $this->assertSame('array', $captured['driver']);
    }
}

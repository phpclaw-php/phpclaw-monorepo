<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Memory;

use PhpClaw\Exceptions\MemoryException;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Memory\FileMemory;
use PHPUnit\Framework\TestCase;

final class FileMemoryTest extends TestCase
{
    private string $storageDir;

    private FileMemory $memory;

    protected function setUp(): void
    {
        HookRegistry::reset();
        $this->storageDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpclaw_mem_test_'.uniqid();
        $this->memory = new FileMemory($this->storageDir);
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
        $this->removeDir($this->storageDir);
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function test_implements_memory_interface(): void
    {
        $this->assertInstanceOf(MemoryInterface::class, $this->memory);
    }

    public function test_constructor_creates_storage_directory(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpclaw_auto_'.uniqid();
        $memory = new FileMemory($dir);

        $this->assertDirectoryExists($dir);

        $this->removeDir($dir);
    }

    public function test_get_returns_null_for_missing_key(): void
    {
        $this->assertNull($this->memory->get('ghost'));
    }

    public function test_set_and_get_string(): void
    {
        $this->memory->set('greeting', 'hello');
        $this->assertSame('hello', $this->memory->get('greeting'));
    }

    public function test_set_and_get_array(): void
    {
        $data = ['model' => 'gpt-4o', 'tokens' => 500];
        $this->memory->set('config', $data);
        $this->assertSame($data, $this->memory->get('config'));
    }

    public function test_set_and_get_integer(): void
    {
        $this->memory->set('count', 42);
        $this->assertSame(42, $this->memory->get('count'));
    }

    public function test_set_overwrites_existing_value(): void
    {
        $this->memory->set('key', 'v1');
        $this->memory->set('key', 'v2');
        $this->assertSame('v2', $this->memory->get('key'));
    }

    public function test_data_persists_across_instances(): void
    {
        $this->memory->set('persistent', 'still here');

        $fresh = new FileMemory($this->storageDir);
        $this->assertSame('still here', $fresh->get('persistent'));
    }

    public function test_namespaces_are_stored_in_separate_files(): void
    {
        $this->memory->set('key', 'ns1-val', 'ns1');
        $this->memory->set('key', 'ns2-val', 'ns2');

        $this->assertFileExists($this->storageDir.DIRECTORY_SEPARATOR.'ns1.json');
        $this->assertFileExists($this->storageDir.DIRECTORY_SEPARATOR.'ns2.json');
    }

    public function test_different_namespaces_are_isolated(): void
    {
        $this->memory->set('key', 'a', 'alpha');
        $this->memory->set('key', 'b', 'beta');

        $this->assertSame('a', $this->memory->get('key', 'alpha'));
        $this->assertSame('b', $this->memory->get('key', 'beta'));
    }

    public function test_has_returns_true_after_set(): void
    {
        $this->memory->set('present', 'yes');
        $this->assertTrue($this->memory->has('present'));
    }

    public function test_has_returns_false_for_unknown_key(): void
    {
        $this->assertFalse($this->memory->has('unknown'));
    }

    public function test_forget_removes_key(): void
    {
        $this->memory->set('del', 'val');
        $this->memory->forget('del');

        $this->assertNull($this->memory->get('del'));
        $this->assertFalse($this->memory->has('del'));
    }

    public function test_forget_does_not_touch_other_keys(): void
    {
        $this->memory->set('keep', 'safe');
        $this->memory->set('remove', 'gone');
        $this->memory->forget('remove');

        $this->assertSame('safe', $this->memory->get('keep'));
    }

    public function test_forget_nonexistent_key_does_not_throw(): void
    {
        $this->expectNotToPerformAssertions();
        $this->memory->forget('never_existed');
    }

    public function test_flush_removes_namespace_file(): void
    {
        $this->memory->set('x', '1', 'to_flush');
        $this->memory->flush('to_flush');

        $this->assertFileDoesNotExist($this->storageDir.DIRECTORY_SEPARATOR.'to_flush.json');
        $this->assertNull($this->memory->get('x', 'to_flush'));
    }

    public function test_flush_does_not_affect_other_namespaces(): void
    {
        $this->memory->set('keep', 'yes', 'safe_ns');
        $this->memory->set('lose', 'bye', 'flush_ns');

        $this->memory->flush('flush_ns');

        $this->assertSame('yes', $this->memory->get('keep', 'safe_ns'));
    }

    public function test_all_returns_all_keys(): void
    {
        $this->memory->set('a', 1, 'ns');
        $this->memory->set('b', 2, 'ns');
        $this->memory->set('c', 3, 'ns');

        $this->assertSame(['a' => 1, 'b' => 2, 'c' => 3], $this->memory->all('ns'));
    }

    public function test_all_returns_empty_array_for_unknown_namespace(): void
    {
        $this->assertSame([], $this->memory->all('nonexistent_ns'));
    }

    public function test_ttl_entry_accessible_before_expiry(): void
    {
        $this->memory->set('tmp', 'val', 'default', ttl: 300);
        $this->assertSame('val', $this->memory->get('tmp'));
    }

    public function test_expired_entry_returns_null(): void
    {
        $this->memory->set('dead', 'gone', 'default', ttl: -1);
        $this->assertNull($this->memory->get('dead'));
    }

    public function test_expired_entry_returns_false_from_has(): void
    {
        $this->memory->set('dead', 'gone', 'default', ttl: -1);
        $this->assertFalse($this->memory->has('dead'));
    }

    public function test_expired_entries_excluded_from_all(): void
    {
        $this->memory->set('alive', 'yes', 'ns', ttl: 300);
        $this->memory->set('dead', 'no', 'ns', ttl: -1);

        $all = $this->memory->all('ns');

        $this->assertArrayHasKey('alive', $all);
        $this->assertArrayNotHasKey('dead', $all);
    }

    public function test_expired_entry_is_evicted_from_file_on_next_read(): void
    {
        $this->memory->set('tmp', 'val', 'default', ttl: -1);

        $this->memory->get('tmp');

        $fresh = new FileMemory($this->storageDir);
        $this->assertFalse($fresh->has('tmp'));
    }

    public function test_namespace_file_contains_valid_json(): void
    {
        $this->memory->set('foo', 'bar', 'integrity_ns');

        $file = $this->storageDir.DIRECTORY_SEPARATOR.'integrity_ns.json';
        $content = file_get_contents($file);

        $this->assertNotFalse($content);
        $decoded = json_decode($content, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('foo', $decoded);
    }

    public function test_namespace_sanitisation_prevents_path_traversal(): void
    {
        $this->expectException(MemoryException::class);
        $this->expectExceptionMessage('illegal path traversal');

        $this->memory->set('key', 'val', '../../../etc');
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
        $this->assertSame('file', $captured['driver']);
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
        $this->assertSame('file', $captured['driver']);
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
        $this->assertSame('file', $captured['driver']);
    }
}

<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit\Memory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Laravel\Memory\DatabaseMemory;
use PhpClaw\Laravel\PhpClawServiceProvider;

final class DatabaseMemoryTest extends TestCase
{
    use RefreshDatabase;

    private DatabaseMemory $memory;

    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('phpclaw.api_key', 'test-key');
        $app['config']->set('phpclaw.memory_driver', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->memory = new DatabaseMemory;
    }

    public function test_set_and_get_string_value(): void
    {
        $this->memory->set('greeting', 'hello world');

        $result = $this->memory->get('greeting');

        $this->assertSame('hello world', $result);
    }

    public function test_set_and_get_array_value(): void
    {
        $data = ['name' => 'Alice', 'role' => 'admin', 'scores' => [1, 2, 3]];
        $this->memory->set('user', $data);

        $result = $this->memory->get('user');

        $this->assertSame($data, $result);
    }

    public function test_get_returns_null_for_missing_key(): void
    {
        $result = $this->memory->get('nonexistent');

        $this->assertNull($result);
    }

    public function test_set_overwrites_existing_value(): void
    {
        $this->memory->set('counter', 1);
        $this->memory->set('counter', 42);

        $result = $this->memory->get('counter');

        $this->assertSame(42, $result);
    }

    public function test_has_returns_true_for_existing_key(): void
    {
        $this->memory->set('present', 'yes');

        $this->assertTrue($this->memory->has('present'));
    }

    public function test_has_returns_false_for_missing_key(): void
    {
        $this->assertFalse($this->memory->has('absent'));
    }

    public function test_forget_removes_key(): void
    {
        $this->memory->set('removable', 'value');
        $this->memory->forget('removable');

        $this->assertNull($this->memory->get('removable'));
        $this->assertFalse($this->memory->has('removable'));
    }

    public function test_forget_nonexistent_key_does_not_throw(): void
    {
        $this->memory->set('kept', 'value');

        $this->memory->forget('does_not_exist');

        $this->assertNull($this->memory->get('does_not_exist'));
        $this->assertSame('value', $this->memory->get('kept'), 'forgetting an absent key must not touch its neighbours');
    }

    public function test_flush_clears_namespace(): void
    {
        $this->memory->set('a', '1');
        $this->memory->set('b', '2');
        $this->memory->set('c', '3');

        $this->memory->flush();

        $this->assertSame([], $this->memory->all());
    }

    public function test_flush_does_not_affect_other_namespaces(): void
    {
        $this->memory->set('key', 'default-value', 'default');
        $this->memory->set('key', 'other-value', 'other');

        $this->memory->flush('default');

        $this->assertNull($this->memory->get('key', 'default'));
        $this->assertSame('other-value', $this->memory->get('key', 'other'));
    }

    public function test_all_returns_all_keys_in_namespace(): void
    {
        $this->memory->set('x', 10);
        $this->memory->set('y', 20);
        $this->memory->set('z', 30);

        $all = $this->memory->all();

        $this->assertCount(3, $all);
        $this->assertSame(10, $all['x']);
        $this->assertSame(20, $all['y']);
        $this->assertSame(30, $all['z']);
    }

    public function test_all_returns_empty_for_unknown_namespace(): void
    {
        $result = $this->memory->all('nonexistent_namespace');

        $this->assertSame([], $result);
    }

    public function test_namespace_isolation(): void
    {
        $this->memory->set('shared_key', 'namespace-A-value', 'namespace_a');
        $this->memory->set('shared_key', 'namespace-B-value', 'namespace_b');

        $this->assertSame('namespace-A-value', $this->memory->get('shared_key', 'namespace_a'));
        $this->assertSame('namespace-B-value', $this->memory->get('shared_key', 'namespace_b'));
    }

    public function test_ttl_entry_accessible_before_expiry(): void
    {
        $this->memory->set('ttl_key', 'still-alive', ttl: 60);

        $result = $this->memory->get('ttl_key');

        $this->assertSame('still-alive', $result);
    }

    public function test_expired_entry_returns_null(): void
    {
        DB::table('phpclaw_memory')->insert([
            'id' => str_pad('1', 26, '0', STR_PAD_LEFT),
            'namespace' => 'default',
            'lookup_key' => 'expired_key',
            'value' => json_encode('expired-value'),
            'expires_at' => date('Y-m-d H:i:s', time() - 10),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $result = $this->memory->get('expired_key');

        $this->assertNull($result);
    }

    public function test_expired_entry_not_returned_from_has(): void
    {
        DB::table('phpclaw_memory')->insert([
            'id' => str_pad('2', 26, '0', STR_PAD_LEFT),
            'namespace' => 'default',
            'lookup_key' => 'expired_has_key',
            'value' => json_encode('some-value'),
            'expires_at' => date('Y-m-d H:i:s', time() - 1),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertFalse($this->memory->has('expired_has_key'));
    }

    public function test_expired_entries_excluded_from_all(): void
    {
        $this->memory->set('valid_key', 'valid-value');

        DB::table('phpclaw_memory')->insert([
            'id' => str_pad('3', 26, '0', STR_PAD_LEFT),
            'namespace' => 'default',
            'lookup_key' => 'expired_all_key',
            'value' => json_encode('expired-value'),
            'expires_at' => date('Y-m-d H:i:s', time() - 5),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $all = $this->memory->all();

        $this->assertArrayHasKey('valid_key', $all);
        $this->assertArrayNotHasKey('expired_all_key', $all);
    }

    public function test_value_persists_across_instances(): void
    {
        $writer = new DatabaseMemory;
        $writer->set('persistent_key', 'persistent-value');

        $reader = new DatabaseMemory;
        $result = $reader->get('persistent_key');

        $this->assertSame('persistent-value', $result);
    }

    public function test_set_with_zero_ttl_stores_without_expiry(): void
    {
        $this->memory->set('no_expiry', 'value', ttl: 0);

        $row = DB::table('phpclaw_memory')
            ->where('lookup_key', 'no_expiry')
            ->first();

        $this->assertNotNull($row);
        $this->assertNull($row->expires_at, 'ttl=0 must result in NULL expires_at');
        $this->assertSame('value', $this->memory->get('no_expiry'));
    }

    public function test_flush_empty_namespace_does_not_throw(): void
    {
        $this->memory->flush('empty_ns');

        $this->assertSame([], $this->memory->all('empty_ns'));
    }

    public function test_has_returns_false_for_expired_entry(): void
    {
        DB::table('phpclaw_memory')->insert([
            'id' => str_pad('9', 26, '0', STR_PAD_LEFT),
            'namespace' => 'default',
            'lookup_key' => 'has_expired_key',
            'value' => json_encode('old-value'),
            'expires_at' => date('Y-m-d H:i:s', time() - 60),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertFalse($this->memory->has('has_expired_key'));
    }

    public function test_set_fires_memory_write_event_with_key_payload(): void
    {
        $captured = null;
        HookRegistry::on('memory.write', function (array $ctx) use (&$captured): void {
            $captured = $ctx;
        });

        $this->memory->set('my_key', 'my_value', 'my_ns', ttl: 60);

        $this->assertNotNull($captured, 'memory.write did not fire');
        $this->assertSame('my_key', $captured['key']);
        $this->assertArrayNotHasKey('lookup_key', $captured, 'hook payload must use "key", not the DB column name "lookup_key"');
        $this->assertSame('my_ns', $captured['namespace']);
        $this->assertSame('database', $captured['driver']);
        $this->assertSame(60, $captured['ttl']);
        $this->assertArrayNotHasKey('value', $captured, 'stored value must NOT leak through hook context');
    }

    public function test_get_fires_memory_read_event_with_key_payload(): void
    {
        $this->memory->set('found_key', 'v');

        $captured = null;
        HookRegistry::on('memory.read', function (array $ctx) use (&$captured): void {
            $captured = $ctx;
        });

        $this->memory->get('found_key');

        $this->assertNotNull($captured);
        $this->assertSame('found_key', $captured['key']);
        $this->assertArrayNotHasKey('lookup_key', $captured);
        $this->assertSame('database', $captured['driver']);
        $this->assertTrue($captured['hit']);
    }

    public function test_forget_fires_memory_forget_event_with_key_payload(): void
    {
        $this->memory->set('gone_key', 'v');

        $captured = null;
        HookRegistry::on('memory.forget', function (array $ctx) use (&$captured): void {
            $captured = $ctx;
        });

        $this->memory->forget('gone_key');

        $this->assertNotNull($captured);
        $this->assertSame('gone_key', $captured['key']);
        $this->assertArrayNotHasKey('lookup_key', $captured);
        $this->assertSame('database', $captured['driver']);
    }
}

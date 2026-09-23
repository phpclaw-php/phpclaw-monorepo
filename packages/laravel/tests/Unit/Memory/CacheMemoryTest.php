<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit\Memory;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Lock;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Orchestra\Testbench\TestCase;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Laravel\Memory\CacheMemory;
use PhpClaw\Laravel\PhpClawServiceProvider;
use PhpClaw\Memory\Contracts\MemoryInterface;

final class CacheMemoryTest extends TestCase
{
    private CacheMemory $memory;

    private Repository $cache;

    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        HookRegistry::reset();

        $this->cache = new Repository(new ArrayStore);
        $this->memory = new CacheMemory(store: $this->cache);
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
        parent::tearDown();
    }

    public function test_implements_memory_interface(): void
    {
        $this->assertInstanceOf(MemoryInterface::class, $this->memory);
    }

    public function test_default_prefix_is_phpclaw(): void
    {
        $this->assertSame('phpclaw:', $this->memory->prefix());
    }

    public function test_custom_prefix_accepted(): void
    {
        $mem = new CacheMemory(store: $this->cache, prefix: 'myapp:');
        $this->assertSame('myapp:', $mem->prefix());
    }

    public function test_set_and_get_string_value(): void
    {
        $this->memory->set('greeting', 'hello world');

        $this->assertSame('hello world', $this->memory->get('greeting'));
    }

    public function test_set_and_get_array_value(): void
    {
        $payload = ['name' => 'Alice', 'tier' => 'pro'];
        $this->memory->set('user', $payload);

        $this->assertSame($payload, $this->memory->get('user'));
    }

    public function test_get_returns_null_for_missing_key(): void
    {
        $this->assertNull($this->memory->get('nonexistent'));
    }

    public function test_set_is_namespaced_in_cache_keys(): void
    {
        $this->memory->set('key1', 'v1', 'ns_one');
        $this->memory->set('key1', 'v2', 'ns_two');

        $this->assertSame('v1', $this->memory->get('key1', 'ns_one'));
        $this->assertSame('v2', $this->memory->get('key1', 'ns_two'));
    }

    public function test_set_with_zero_ttl_means_forever(): void
    {
        $mem = new CacheMemory(store: $this->cache, defaultTtl: 0);
        $mem->set('permanent', 'forever');

        $this->assertSame('forever', $mem->get('permanent'));
    }

    public function test_explicit_ttl_overrides_default(): void
    {
        $mem = new CacheMemory(store: $this->cache, defaultTtl: 3600);
        $mem->set('short', 'v', ttl: 60);

        $this->assertSame('v', $mem->get('short'));
    }

    public function test_forget_removes_key(): void
    {
        $this->memory->set('doomed', 'bye');
        $this->memory->forget('doomed');

        $this->assertNull($this->memory->get('doomed'));
    }

    public function test_forget_noop_on_missing_key(): void
    {
        $this->memory->set('kept', 'value');

        $this->memory->forget('never_set');

        $this->assertNull($this->memory->get('never_set'));
        $this->assertSame('value', $this->memory->get('kept'), 'forgetting an absent key must not touch its neighbours');
    }

    public function test_flush_removes_only_phpclaw_keys_in_namespace(): void
    {
        $this->memory->set('a', 'ns1_a', 'ns1');
        $this->memory->set('b', 'ns1_b', 'ns1');
        $this->memory->set('c', 'ns2_c', 'ns2');

        $this->cache->forever('my_app_setting', 'keep_me');

        $this->memory->flush('ns1');

        $this->assertNull($this->memory->get('a', 'ns1'));
        $this->assertNull($this->memory->get('b', 'ns1'));

        $this->assertSame('ns2_c', $this->memory->get('c', 'ns2'));

        $this->assertSame('keep_me', $this->cache->get('my_app_setting'));
    }

    public function test_has_returns_true_when_key_set(): void
    {
        $this->memory->set('foo', 'bar');
        $this->assertTrue($this->memory->has('foo'));
    }

    public function test_has_returns_false_when_key_missing(): void
    {
        $this->assertFalse($this->memory->has('missing'));
    }

    public function test_all_returns_every_phpclaw_key_in_namespace(): void
    {
        $this->memory->set('a', 1);
        $this->memory->set('b', 2);
        $this->memory->set('c', 3);

        $all = $this->memory->all();

        $this->assertSame(['a' => 1, 'b' => 2, 'c' => 3], $all);
    }

    public function test_all_returns_empty_when_namespace_empty(): void
    {
        $this->assertSame([], $this->memory->all());
    }

    public function test_all_is_namespace_scoped(): void
    {
        $this->memory->set('x', 'ns1_x', 'ns1');
        $this->memory->set('y', 'ns2_y', 'ns2');

        $this->assertSame(['x' => 'ns1_x'], $this->memory->all('ns1'));
        $this->assertSame(['y' => 'ns2_y'], $this->memory->all('ns2'));
    }

    public function test_set_fires_memory_write_event(): void
    {
        $captured = null;
        HookRegistry::on('memory.write', function (array $ctx) use (&$captured): void {
            $captured = $ctx;
        });

        $this->memory->set('my_key', 'my_value', 'my_ns', ttl: 60);

        $this->assertNotNull($captured, 'memory.write did not fire');
        $this->assertSame('my_key', $captured['key']);
        $this->assertSame('my_ns', $captured['namespace']);
        $this->assertSame('cache', $captured['driver']);
        $this->assertSame(60, $captured['ttl']);
        $this->assertArrayNotHasKey('value', $captured, 'P5: stored value must NOT leak through hook context');
    }

    public function test_get_fires_memory_read_event_with_hit_true(): void
    {
        $this->memory->set('found_key', 'v');

        $captured = null;
        HookRegistry::on('memory.read', function (array $ctx) use (&$captured): void {
            $captured = $ctx;
        });

        $this->memory->get('found_key');

        $this->assertNotNull($captured);
        $this->assertSame('cache', $captured['driver']);
        $this->assertTrue($captured['hit']);
        $this->assertArrayNotHasKey('value', $captured);
    }

    public function test_get_fires_memory_read_event_with_hit_false(): void
    {
        $captured = null;
        HookRegistry::on('memory.read', function (array $ctx) use (&$captured): void {
            $captured = $ctx;
        });

        $this->memory->get('missing');

        $this->assertNotNull($captured);
        $this->assertFalse($captured['hit']);
    }

    public function test_set_uses_lock_when_underlying_store_implements_lock_provider(): void
    {
        $lockAcquired = false;

        $lockStore = new class($lockAcquired) extends ArrayStore implements LockProvider
        {
            public function __construct(private bool &$acquired)
            {
                parent::__construct();
            }

            public function lock($name, $seconds = 0, $owner = null): Lock
            {
                $acquiredRef = &$this->acquired;

                return new class($this, $name, $seconds, $acquiredRef) extends Lock
                {
                    public function __construct(
                        $store,
                        $name,
                        $seconds,
                        private bool &$acquired,
                    ) {
                        parent::__construct($store, $name, $seconds);
                    }

                    public function acquire(): bool
                    {
                        $this->acquired = true;

                        return true;
                    }

                    public function release(): bool
                    {
                        return true;
                    }

                    public function owner(): string
                    {
                        return 'test';
                    }

                    public function forceRelease(): void {}

                    protected function getCurrentOwner(): string
                    {
                        return 'test';
                    }
                };
            }

            public function restoreLock($name, $owner): Lock
            {
                return $this->lock($name, 0, $owner);
            }
        };

        $repo = new Repository($lockStore);
        $memory = new CacheMemory(store: $repo);

        $memory->set('lock_test_key', 'lock_test_value');

        $this->assertTrue($lockAcquired, 'Lock must be acquired when store implements LockProvider');
        $this->assertSame('lock_test_value', $memory->get('lock_test_key'));
    }

    public function test_set_skips_lock_when_store_does_not_implement_lock_provider(): void
    {
        $plain = new Repository(new ArrayStore);
        $memory = new CacheMemory(store: $plain);

        $memory->set('no_lock_key', 'no_lock_value');

        $this->assertSame('no_lock_value', $memory->get('no_lock_key'));
    }

    public function test_forget_fires_memory_forget_event(): void
    {
        $this->memory->set('doomed', 'v');

        $captured = null;
        HookRegistry::on('memory.forget', function (array $ctx) use (&$captured): void {
            $captured = $ctx;
        });

        $this->memory->forget('doomed');

        $this->assertNotNull($captured);
        $this->assertSame('doomed', $captured['key']);
        $this->assertSame('cache', $captured['driver']);
        $this->assertArrayNotHasKey('value', $captured);
    }

    public function test_all_returns_empty_after_flush(): void
    {
        $this->memory->set('x', 1);
        $this->memory->set('y', 2);

        $this->memory->flush();

        $this->assertSame([], $this->memory->all());
    }

    public function test_has_returns_false_after_forget(): void
    {
        $this->memory->set('item', 'value');
        $this->memory->forget('item');

        $this->assertFalse($this->memory->has('item'));
    }

    public function test_all_skips_expired_entries(): void
    {
        $mem = new CacheMemory(store: $this->cache, defaultTtl: 1);
        $mem->set('alive', 'yes', ttl: 3600);
        $mem->set('ephemeral', 'soon-gone', ttl: 1);

        $all = $mem->all();
        $this->assertArrayHasKey('alive', $all);
        $this->assertArrayHasKey('ephemeral', $all);

        $this->cache->forget('phpclaw:default:ephemeral');

        $remaining = $mem->all();
        $this->assertArrayHasKey('alive', $remaining);
        $this->assertArrayNotHasKey('ephemeral', $remaining);
    }

    public function test_prefix_returns_custom_value(): void
    {
        $mem = new CacheMemory(store: $this->cache, prefix: 'custom:');
        $this->assertSame('custom:', $mem->prefix());
    }
}

<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit\Memory;

use PhpClaw\Exceptions\MemoryException;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Symfony\Memory\CacheMemory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Contracts\Cache\CacheInterface;

final class CacheMemoryTest extends TestCase
{
    private CacheInterface $cache;

    protected function setUp(): void
    {
        HookRegistry::reset();
        $this->cache = new ArrayAdapter;
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
    }

    public function test_set_and_get_round_trip(): void
    {
        $memory = new CacheMemory($this->cache);
        $memory->set('foo', 'bar', 'default');

        self::assertSame('bar', $memory->get('foo', 'default'));
    }

    public function test_get_missing_returns_null(): void
    {
        $memory = new CacheMemory($this->cache);

        self::assertNull($memory->get('nope', 'default'));
    }

    public function test_namespace_isolation(): void
    {
        $memory = new CacheMemory($this->cache);
        $memory->set('k', 'ns1-val', 'ns1');
        $memory->set('k', 'ns2-val', 'ns2');

        self::assertSame('ns1-val', $memory->get('k', 'ns1'));
        self::assertSame('ns2-val', $memory->get('k', 'ns2'));
    }

    public function test_forget_removes_single_key(): void
    {
        $memory = new CacheMemory($this->cache);
        $memory->set('a', 1, 'ns');
        $memory->set('b', 2, 'ns');
        $memory->forget('a', 'ns');

        self::assertNull($memory->get('a', 'ns'));
        self::assertSame(2, $memory->get('b', 'ns'));
    }

    public function test_flush_wipes_only_target_namespace(): void
    {
        $memory = new CacheMemory($this->cache);
        $memory->set('a', 1, 'ns1');
        $memory->set('b', 2, 'ns1');
        $memory->set('c', 3, 'ns2');

        $memory->flush('ns1');

        self::assertNull($memory->get('a', 'ns1'));
        self::assertSame(3, $memory->get('c', 'ns2'));
    }

    public function test_all_returns_populated_namespace(): void
    {
        $memory = new CacheMemory($this->cache);
        $memory->set('a', 1, 'ns');
        $memory->set('b', 'two', 'ns');

        $all = $memory->all('ns');

        self::assertCount(2, $all);
        self::assertSame(1, $all['a']);
        self::assertSame('two', $all['b']);
    }

    public function test_has_reflects_presence(): void
    {
        $memory = new CacheMemory($this->cache);

        self::assertFalse($memory->has('k', 'ns'));
        $memory->set('k', 'v', 'ns');
        self::assertTrue($memory->has('k', 'ns'));
    }

    public function test_hooks_fire(): void
    {
        $reads = 0;
        HookRegistry::on('memory.read', static function () use (&$reads): void {
            $reads++;
        });

        $memory = new CacheMemory($this->cache);
        $memory->get('x', 'default');

        self::assertSame(1, $reads);
    }

    public function test_flush_empty_namespace_is_noop(): void
    {
        $memory = new CacheMemory($this->cache);
        $memory->flush('empty-ns');

        self::assertSame([], $memory->all('empty-ns'));
    }

    public function test_all_returns_empty_array_for_empty_namespace(): void
    {
        $memory = new CacheMemory($this->cache);

        self::assertSame([], $memory->all('no-such-ns'));
    }

    public function test_has_returns_false_after_forget(): void
    {
        $memory = new CacheMemory($this->cache);
        $memory->set('x', 'val', 'ns');
        $memory->forget('x', 'ns');

        self::assertFalse($memory->has('x', 'ns'));
    }

    public function test_set_overwrites_existing_key(): void
    {
        $memory = new CacheMemory($this->cache);
        $memory->set('k', 'first', 'ns');
        $memory->set('k', 'second', 'ns');

        self::assertSame('second', $memory->get('k', 'ns'));
    }

    public function test_get_throws_memory_exception_on_cache_failure(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willThrowException(new \RuntimeException('cache down'));

        $this->expectException(MemoryException::class);
        (new CacheMemory($cache))->get('k', 'ns');
    }

    public function test_set_throws_memory_exception_on_cache_failure(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willThrowException(new \RuntimeException('cache down'));
        $cache->method('delete')->willReturn(true);

        $this->expectException(MemoryException::class);
        (new CacheMemory($cache))->set('k', 'v', 'ns');
    }

    public function test_forget_throws_memory_exception_on_cache_failure(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('delete')->willThrowException(new \RuntimeException('cache down'));

        $this->expectException(MemoryException::class);
        (new CacheMemory($cache))->forget('k', 'ns');
    }

    public function test_flush_throws_memory_exception_on_cache_failure(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willThrowException(new \RuntimeException('cache down'));

        $this->expectException(MemoryException::class);
        (new CacheMemory($cache))->flush('ns');
    }

    public function test_all_throws_memory_exception_on_cache_failure(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willThrowException(new \RuntimeException('cache down'));

        $this->expectException(MemoryException::class);
        (new CacheMemory($cache))->all('ns');
    }

    public function test_has_throws_memory_exception_on_cache_failure(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willThrowException(new \RuntimeException('cache down'));

        $this->expectException(MemoryException::class);
        (new CacheMemory($cache))->has('k', 'ns');
    }

    public function test_memory_exception_message_does_not_contain_underlying_error(): void
    {
        $sensitiveMsg = 'secret connection string from inner exception';
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willThrowException(new \RuntimeException($sensitiveMsg));

        try {
            (new CacheMemory($cache))->get('k', 'ns');
            self::fail('Expected MemoryException');
        } catch (MemoryException $e) {
            self::assertStringNotContainsString($sensitiveMsg, $e->getMessage());
        }
    }

    public function test_custom_prefix_isolates_keys(): void
    {
        $memory1 = new CacheMemory($this->cache, prefix: 'a.');
        $memory2 = new CacheMemory($this->cache, prefix: 'b.');
        $memory1->set('k', 'from-a', 'ns');
        $memory2->set('k', 'from-b', 'ns');

        self::assertSame('from-a', $memory1->get('k', 'ns'));
        self::assertSame('from-b', $memory2->get('k', 'ns'));
    }
}

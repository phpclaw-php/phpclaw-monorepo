<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Unit\Memory;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Lock\LockBackendInterface;
use PhpClaw\Drupal\Memory\CacheMemory;
use PHPUnit\Framework\TestCase;

final class CacheMemoryTest extends TestCase
{
    private CacheBackendInterface $cache;

    private TimeInterface $time;

    private CacheMemory $mem;

    protected function setUp(): void
    {
        if (! class_exists(\Drupal::class)) {
            $this->markTestSkipped('Drupal base classes not available.');
        }

        $this->time = $this->createMock(TimeInterface::class);
        $this->time->method('getRequestTime')->willReturn(time());
        $this->time->method('getCurrentTime')->willReturn(time());
        $this->cache = $this->createMock(CacheBackendInterface::class);
        $this->mem = new CacheMemory($this->cache, $this->time);
    }

    private function makeItem(mixed $data): object
    {
        return new class($data)
        {
            public function __construct(public readonly mixed $data) {}
        };
    }

    public function test_get_returns_null_on_cache_miss(): void
    {
        $this->cache->method('get')->willReturn(false);

        $result = $this->mem->get('missing', 'default');

        $this->assertNull($result);
    }

    public function test_get_returns_data_on_cache_hit(): void
    {
        $item = $this->makeItem('hello');
        $this->cache->method('get')
            ->with('phpclaw:default:mykey')
            ->willReturn($item);

        $result = $this->mem->get('mykey', 'default');

        $this->assertSame('hello', $result);
    }

    public function test_set_calls_cache_set_with_permanent_expire_when_no_ttl(): void
    {
        $this->cache->expects($this->atLeastOnce())->method('get')->willReturn(false);
        $this->cache->expects($this->atLeastOnce())->method('set');

        $this->mem->set('foo', 'bar', 'default');
    }

    public function test_set_calls_cache_set_with_calculated_expire_when_ttl_given(): void
    {
        $this->cache->expects($this->atLeastOnce())->method('get')->willReturn(false);
        $this->cache->expects($this->atLeastOnce())->method('set');

        $this->mem->set('foo', 'bar', 'default', 3600);
    }

    public function test_forget_calls_cache_delete(): void
    {
        $this->cache->expects($this->atLeastOnce())->method('delete');
        $this->cache->method('get')->willReturn(false);

        $this->mem->forget('key', 'ns');
    }

    public function test_flush_deletes_all_tracked_keys(): void
    {
        $trackerItem = $this->makeItem(['keyA', 'keyB']);

        $this->cache->method('get')->willReturnCallback(
            function (string $cacheKey) use ($trackerItem): mixed {
                if (str_starts_with($cacheKey, 'phpclaw:_tracker:')) {
                    return $trackerItem;
                }

                return false;
            }
        );

        $this->cache->expects($this->atLeast(3))->method('delete');

        $this->mem->flush('myns');
    }

    public function test_all_returns_empty_array_when_no_tracker(): void
    {
        $this->cache->method('get')->willReturn(false);

        $result = $this->mem->all('empty-ns');

        $this->assertSame([], $result);
    }

    public function test_all_returns_tracked_keys_data(): void
    {
        $trackerItem = $this->makeItem(['keyA', 'keyB']);
        $dataItem = $this->makeItem('value-a');

        $this->cache->method('get')->willReturnCallback(
            function (string $cacheKey) use ($trackerItem, $dataItem): mixed {
                if (str_starts_with($cacheKey, 'phpclaw:_tracker:')) {
                    return $trackerItem;
                }
                if (str_contains($cacheKey, ':keyA')) {
                    return $dataItem;
                }

                return false;
            }
        );

        $result = $this->mem->all('myns');

        $this->assertArrayHasKey('keyA', $result);
        $this->assertSame('value-a', $result['keyA']);
    }

    public function test_has_returns_true_when_item_exists(): void
    {
        $item = $this->makeItem('exists');
        $this->cache->method('get')->willReturn($item);

        $this->assertTrue($this->mem->has('k', 'ns'));
    }

    public function test_has_returns_false_when_item_missing(): void
    {
        $this->cache->method('get')->willReturn(false);

        $this->assertFalse($this->mem->has('k', 'ns'));
    }

    public function test_set_tracker_add_skips_duplicate_key(): void
    {
        $trackerItem = $this->makeItem(['foo']);

        $callCount = 0;
        $this->cache->method('get')->willReturnCallback(
            function (string $cacheKey) use ($trackerItem, &$callCount): mixed {
                $callCount++;
                if (str_starts_with($cacheKey, 'phpclaw:_tracker:')) {
                    return $trackerItem;
                }

                return false;
            }
        );

        $this->cache->expects($this->once())->method('set')
            ->with('phpclaw:default:foo', 'val', CacheBackendInterface::CACHE_PERMANENT);

        $this->mem->set('foo', 'val', 'default');
    }

    public function test_forget_removes_key_from_tracker_and_deletes_tracker_when_empty(): void
    {
        $trackerItem = $this->makeItem(['onlykey']);

        $this->cache->method('get')->willReturnCallback(
            function (string $cacheKey) use ($trackerItem): mixed {
                if (str_starts_with($cacheKey, 'phpclaw:_tracker:')) {
                    return $trackerItem;
                }

                return false;
            }
        );

        $this->cache->expects($this->atLeast(2))->method('delete');

        $this->mem->forget('onlykey', 'default');
    }

    public function test_set_skips_tracker_write_when_lock_never_acquired(): void
    {
        $lock = $this->createMock(LockBackendInterface::class);
        $lock->method('acquire')->willReturn(false);
        $lock->expects($this->once())->method('wait');
        $lock->expects($this->never())->method('release');

        $mem = new CacheMemory($this->cache, $this->time, $lock);

        $this->cache->method('get')->willReturn(false);
        $setCalls = [];
        $this->cache->method('set')->willReturnCallback(
            function (string $key) use (&$setCalls): void {
                $setCalls[] = $key;
            }
        );

        $mem->set('foo', 'bar', 'default');

        $this->assertSame(['phpclaw:default:foo'], $setCalls);
    }

    public function test_namespace_colon_is_replaced_in_cache_key(): void
    {
        $this->cache->method('get')->willReturnCallback(
            function (string $key): mixed {
                $this->assertStringNotContainsString('ns:sub:mykey', $key);

                return false;
            }
        );
        $this->cache->method('set');

        $this->mem->set('my:key', 'v', 'ns:sub');
    }
}

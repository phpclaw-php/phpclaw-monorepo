<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Unit\Cache;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\MemoryBackend;
use PhpClaw\Drupal\Cache\DrupalCache;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

final class DrupalCacheTest extends TestCase
{
    private int $now;

    private int $elapsed = 0;

    private MemoryBackend $backend;

    private TimeInterface $time;

    protected function setUp(): void
    {
        $this->now = 1_800_000_000;
        $this->time = $this->createMock(TimeInterface::class);
        $this->time->method('getRequestTime')->willReturnCallback(fn (): int => $this->now);
        $this->time->method('getCurrentTime')->willReturnCallback(fn (): int => $this->now + $this->elapsed);
        $this->backend = new MemoryBackend($this->time);
    }

    public function test_it_is_a_psr16_store(): void
    {
        self::assertInstanceOf(CacheInterface::class, new DrupalCache($this->backend, $this->time));
    }

    public function test_a_miss_returns_the_default(): void
    {
        $cache = new DrupalCache($this->backend, $this->time);

        self::assertSame('none', $cache->get('missing', 'none'));
        self::assertNull($cache->get('missing'));
        self::assertFalse($cache->has('missing'));
    }

    public function test_a_stored_array_reads_back_unchanged(): void
    {
        $cache = new DrupalCache($this->backend, $this->time);
        $value = ['type' => 'text', 'text' => 'Lima', 'input_tokens' => 12];

        self::assertTrue($cache->set('k', $value, 600));
        self::assertSame($value, $cache->get('k'));
        self::assertTrue($cache->has('k'));
    }

    public function test_an_entry_past_its_ttl_is_a_miss(): void
    {
        $cache = new DrupalCache($this->backend, $this->time);
        $cache->set('k', 'v', 600);
        $this->now += 601;

        self::assertSame('gone', $cache->get('k', 'gone'));
        self::assertFalse($cache->has('k'));
    }

    public function test_the_ttl_becomes_the_drupal_expiry_and_null_never_expires(): void
    {
        $cache = new DrupalCache($this->backend, $this->time);
        $cache->set('timed', 'v', 120);
        $cache->set('forever', 'v');
        $cache->set('interval', 'v', new \DateInterval('PT90S'));

        self::assertSame($this->now + 120, $this->backend->get('timed')->expire);
        self::assertSame(-1, $this->backend->get('forever')->expire);
        self::assertSame($this->now + 90, $this->backend->get('interval')->expire);
    }

    public function test_the_expiry_counts_from_when_the_entry_is_written_not_from_the_request_start(): void
    {
        $cache = new DrupalCache($this->backend, $this->time);
        $this->elapsed = 50;
        $cache->set('slow', 'v', 60);

        self::assertSame($this->now + 110, $this->backend->get('slow')->expire);
        self::assertSame('v', $cache->get('slow'));
    }

    public function test_a_zero_or_negative_ttl_deletes_the_entry(): void
    {
        $cache = new DrupalCache($this->backend, $this->time);
        $cache->set('k', 'v', 600);

        self::assertTrue($cache->set('k', 'new', 0));
        self::assertFalse($cache->has('k'));

        $cache->set('k', 'v', 600);
        self::assertTrue($cache->set('k', 'new', -5));
        self::assertNull($cache->get('k'));
    }

    public function test_delete_removes_one_entry_and_succeeds_for_a_missing_key(): void
    {
        $cache = new DrupalCache($this->backend, $this->time);
        $cache->set('a', 1, 600);
        $cache->set('b', 2, 600);

        self::assertTrue($cache->delete('a'));
        self::assertTrue($cache->delete('never-set'));
        self::assertFalse($cache->has('a'));
        self::assertSame(2, $cache->get('b'));
    }

    public function test_clear_empties_the_bin(): void
    {
        $cache = new DrupalCache($this->backend, $this->time);
        $cache->set('a', 1, 600);

        self::assertTrue($cache->clear());
        self::assertFalse($cache->has('a'));
    }

    public function test_multiple_operations_cover_every_key(): void
    {
        $cache = new DrupalCache($this->backend, $this->time);

        self::assertTrue($cache->setMultiple(['a' => '1', 'b' => '2'], 600));
        self::assertSame(['a' => '1', 'b' => '2', 'c' => 'none'], [...$cache->getMultiple(['a', 'b', 'c'], 'none')]);
        self::assertTrue($cache->deleteMultiple(['a', 'b']));
        self::assertSame(['a' => null, 'b' => null], [...$cache->getMultiple(['a', 'b'])]);
    }
}

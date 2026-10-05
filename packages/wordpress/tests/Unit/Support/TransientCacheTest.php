<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\Support;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\WordPress\Support\TransientCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TransientCache::class)]
final class TransientCacheTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private array $store = [];

    private array $captured = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->store = [];
        $this->captured = [];

        $store = &$this->store;
        $captured = &$this->captured;

        Functions\when('set_transient')->alias(function (string $key, mixed $value, int $expiration = 0) use (&$store, &$captured): bool {
            $store[$key] = $value;
            $captured['key'] = $key;
            $captured['ttl'] = $expiration;

            return true;
        });

        Functions\when('get_transient')->alias(function (string $key) use (&$store): mixed {
            return $store[$key] ?? false;
        });

        Functions\when('delete_transient')->alias(function (string $key) use (&$store): bool {
            $existed = array_key_exists($key, $store);
            unset($store[$key]);

            return $existed;
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_set_and_get_round_trip(): void
    {
        $cache = new TransientCache;
        $cache->set('foo', 'bar');

        self::assertSame('bar', $cache->get('foo'));
    }

    public function test_get_returns_default_on_miss(): void
    {
        $cache = new TransientCache;

        self::assertNull($cache->get('missing'));
        self::assertSame('fallback', $cache->get('missing', 'fallback'));
    }

    public function test_set_with_null_ttl_passes_zero_seconds(): void
    {
        $cache = new TransientCache;
        $cache->set('k', 'v', null);

        self::assertSame(0, $this->captured['ttl']);
    }

    public function test_set_with_int_ttl_passes_seconds_unchanged(): void
    {
        $cache = new TransientCache;
        $cache->set('k', 'v', 120);

        self::assertSame(120, $this->captured['ttl']);
    }

    public function test_set_with_date_interval_converts_to_seconds(): void
    {
        $cache = new TransientCache;
        $cache->set('k', 'v', new \DateInterval('PT1H'));

        self::assertSame(3600, $this->captured['ttl']);
    }

    public function test_delete_removes_the_key(): void
    {
        $cache = new TransientCache;
        $cache->set('k', 'v');

        self::assertTrue($cache->delete('k'));
        self::assertNull($cache->get('k'));
    }

    public function test_delete_returns_false_when_key_absent(): void
    {
        $cache = new TransientCache;

        self::assertFalse($cache->delete('never-set'));
    }

    public function test_has_true_when_present(): void
    {
        $cache = new TransientCache;
        $cache->set('k', 'v');

        self::assertTrue($cache->has('k'));
    }

    public function test_has_false_when_absent(): void
    {
        $cache = new TransientCache;

        self::assertFalse($cache->has('missing'));
    }

    public function test_get_multiple_returns_stored_values_and_default_for_misses(): void
    {
        $cache = new TransientCache;
        $cache->set('a', '1');
        $cache->set('b', '2');

        self::assertSame(
            ['a' => '1', 'b' => '2', 'c' => 'none'],
            [...$cache->getMultiple(['a', 'b', 'c'], 'none')],
        );
    }

    public function test_set_multiple_stores_every_pair(): void
    {
        $cache = new TransientCache;

        self::assertTrue($cache->setMultiple(['a' => '1', 'b' => '2']));
        self::assertSame('1', $cache->get('a'));
        self::assertSame('2', $cache->get('b'));
    }

    public function test_delete_multiple_removes_every_key(): void
    {
        $cache = new TransientCache;
        $cache->set('a', '1');
        $cache->set('b', '2');

        self::assertTrue($cache->deleteMultiple(['a', 'b']));
        self::assertNull($cache->get('a'));
        self::assertNull($cache->get('b'));
    }

    public function test_clear_returns_false(): void
    {
        $cache = new TransientCache;

        self::assertFalse($cache->clear());
    }

    public function test_key_hashing_produces_a_prefixed_name_and_never_collides(): void
    {
        $ref = new \ReflectionClass(TransientCache::class);
        $method = $ref->getMethod('transientKey');
        $method->setAccessible(true);
        $cache = new TransientCache;

        $keyA = $method->invoke($cache, 'response:a');
        $keyB = $method->invoke($cache, 'response:b');

        self::assertStringStartsWith('phpclaw_rc_', $keyA);
        self::assertStringStartsWith('phpclaw_rc_', $keyB);
        self::assertNotSame($keyA, $keyB);
    }
}

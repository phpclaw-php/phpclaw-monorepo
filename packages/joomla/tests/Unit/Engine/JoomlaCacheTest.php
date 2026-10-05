<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Unit\Engine;

use Joomla\CMS\Cache\Cache;
use Joomla\CMS\Factory;
use PhpClaw\Joomla\Component\Administrator\Engine\JoomlaCache;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

final class JoomlaCacheTest extends TestCase
{
    protected function tearDown(): void
    {
        Factory::$application = null;
        Cache::$lastOptions = [];
    }

    public function test_it_is_a_psr16_store(): void
    {
        self::assertInstanceOf(CacheInterface::class, new JoomlaCache(new Cache(['defaultgroup' => 'g'])));
    }

    public function test_a_miss_returns_the_default(): void
    {
        $cache = new JoomlaCache(new Cache(['defaultgroup' => 'g']));

        self::assertSame('none', $cache->get('missing', 'none'));
        self::assertNull($cache->get('missing'));
    }

    public function test_a_stored_array_reads_back_unchanged(): void
    {
        $cache = new JoomlaCache(new Cache(['defaultgroup' => 'g']));
        $value = ['type' => 'text', 'text' => 'Lima', 'input_tokens' => 12];

        self::assertTrue($cache->set('k', $value, 600));
        self::assertSame($value, $cache->get('k'));
        self::assertTrue($cache->has('k'));
    }

    public function test_an_entry_past_its_ttl_is_a_miss_and_is_removed(): void
    {
        $backend = new Cache(['defaultgroup' => 'g']);
        $cache = new JoomlaCache($backend);
        $cache->set('k', 'v', 600);

        foreach ($backend->items['g'] as $id => $raw) {
            $entry = json_decode($raw, true);
            $entry['e'] = time() - 1;
            $backend->items['g'][$id] = json_encode($entry);
        }

        self::assertSame('gone', $cache->get('k', 'gone'));
        self::assertFalse($cache->has('k'));
        self::assertSame([], $backend->items['g']);
    }

    public function test_a_null_ttl_never_expires(): void
    {
        $backend = new Cache(['defaultgroup' => 'g']);
        $cache = new JoomlaCache($backend);
        $cache->set('k', 'v');

        $entry = json_decode((string) reset($backend->items['g']), true);

        self::assertSame(0, $entry['e']);
        self::assertSame('v', $cache->get('k'));
    }

    public function test_a_date_interval_ttl_sets_the_expiry(): void
    {
        $backend = new Cache(['defaultgroup' => 'g']);
        $cache = new JoomlaCache($backend);
        $cache->set('k', 'v', new \DateInterval('PT120S'));

        $entry = json_decode((string) reset($backend->items['g']), true);

        self::assertEqualsWithDelta(time() + 120, $entry['e'], 2);
    }

    public function test_a_zero_or_negative_ttl_deletes_the_entry(): void
    {
        $cache = new JoomlaCache(new Cache(['defaultgroup' => 'g']));
        $cache->set('k', 'v', 600);

        self::assertTrue($cache->set('k', 'new', 0));
        self::assertFalse($cache->has('k'));

        $cache->set('k', 'v', 600);
        self::assertTrue($cache->set('k', 'new', -5));
        self::assertNull($cache->get('k'));
    }

    public function test_a_failed_backend_store_returns_false(): void
    {
        $backend = new Cache(['defaultgroup' => 'g']);
        $backend->failStore = true;

        self::assertFalse((new JoomlaCache($backend))->set('k', 'v', 600));
    }

    public function test_a_value_that_cannot_be_encoded_is_not_stored(): void
    {
        $backend = new Cache(['defaultgroup' => 'g']);

        self::assertFalse((new JoomlaCache($backend))->set('k', "\xB1\x31", 600));
        self::assertSame([], $backend->items['g'] ?? []);
    }

    public function test_delete_removes_one_entry_and_succeeds_for_a_missing_key(): void
    {
        $cache = new JoomlaCache(new Cache(['defaultgroup' => 'g']));
        $cache->set('a', 1, 600);
        $cache->set('b', 2, 600);

        self::assertTrue($cache->delete('a'));
        self::assertTrue($cache->delete('never-set'));
        self::assertFalse($cache->has('a'));
        self::assertSame(2, $cache->get('b'));
    }

    public function test_clear_empties_the_phpclaw_group(): void
    {
        $cache = new JoomlaCache(new Cache(['defaultgroup' => 'g']));
        $cache->set('a', 1, 600);

        self::assertTrue($cache->clear());
        self::assertFalse($cache->has('a'));
    }

    public function test_multiple_operations_cover_every_key(): void
    {
        $cache = new JoomlaCache(new Cache(['defaultgroup' => 'g']));

        self::assertTrue($cache->setMultiple(['a' => '1', 'b' => '2'], 600));
        self::assertSame(['a' => '1', 'b' => '2', 'c' => 'none'], [...$cache->getMultiple(['a', 'b', 'c'], 'none')]);
        self::assertTrue($cache->deleteMultiple(['a', 'b']));
        self::assertSame(['a' => null, 'b' => null], [...$cache->getMultiple(['a', 'b'])]);
    }

    public function test_the_default_backend_forces_caching_on_with_the_site_handler(): void
    {
        Factory::$application = new class
        {
            public function get(string $key, mixed $default = null): mixed
            {
                return $key === 'cache_handler' ? 'redis' : $default;
            }
        };

        new JoomlaCache;

        self::assertTrue(Cache::$lastOptions['caching']);
        self::assertSame('redis', Cache::$lastOptions['storage']);
        self::assertSame(1440, Cache::$lastOptions['lifetime']);
        self::assertSame('phpclaw_cache', Cache::$lastOptions['defaultgroup']);
    }

    public function test_the_default_backend_falls_back_to_file_storage(): void
    {
        Factory::$application = new class
        {
            public function get(string $key, mixed $default = null): mixed
            {
                return $key === 'cache_handler' ? '' : $default;
            }
        };

        new JoomlaCache;

        self::assertSame('file', Cache::$lastOptions['storage']);
    }
}

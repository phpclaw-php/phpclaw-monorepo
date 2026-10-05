<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Service;

use PhpClaw\Magento\Service\MagentoCache;
use PhpClaw\Magento\Tests\Unit\Support\ArrayAppCache;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

final class MagentoCacheTest extends TestCase
{
    private ArrayAppCache $appCache;

    protected function setUp(): void
    {
        $this->appCache = new ArrayAppCache;
    }

    public function test_it_is_a_psr16_store(): void
    {
        self::assertInstanceOf(CacheInterface::class, new MagentoCache($this->appCache));
    }

    public function test_a_miss_returns_the_default(): void
    {
        $cache = new MagentoCache($this->appCache);

        self::assertSame('none', $cache->get('missing', 'none'));
        self::assertNull($cache->get('missing'));
        self::assertFalse($cache->has('missing'));
    }

    public function test_a_stored_array_reads_back_unchanged(): void
    {
        $cache = new MagentoCache($this->appCache);
        $value = ['type' => 'text', 'text' => 'Lima', 'input_tokens' => 12];

        self::assertTrue($cache->set('k', $value, 600));
        self::assertSame($value, $cache->get('k'));
        self::assertTrue($cache->has('k'));
    }

    public function test_a_stored_null_is_a_hit_not_a_miss(): void
    {
        $cache = new MagentoCache($this->appCache);
        $cache->set('k', null, 600);

        self::assertTrue($cache->has('k'));
        self::assertNull($cache->get('k', 'default'));
    }

    public function test_the_ttl_becomes_the_magento_lifetime_and_null_never_expires(): void
    {
        $cache = new MagentoCache($this->appCache);
        $cache->set('timed', 'v', 120);
        $cache->set('forever', 'v');
        $cache->set('interval', 'v', new \DateInterval('PT90S'));

        $lifetimes = array_column($this->appCache->entries, 'lifetime');

        self::assertSame([120, null, 90], $lifetimes);
    }

    public function test_every_entry_is_tagged_and_keyed_safely_for_magento(): void
    {
        $cache = new MagentoCache($this->appCache);
        $cache->set('phpclaw:rate/limit bucket', 1, 60);

        $id = array_key_first($this->appCache->entries);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_]+$/', (string) $id);
        self::assertSame(['PHPCLAW_AGENT_CACHE'], $this->appCache->entries[$id]['tags']);
    }

    public function test_a_zero_or_negative_ttl_deletes_the_entry(): void
    {
        $cache = new MagentoCache($this->appCache);
        $cache->set('k', 'v', 600);

        self::assertTrue($cache->set('k', 'v', 0));
        self::assertFalse($cache->has('k'));

        $cache->set('k', 'v', 600);
        self::assertTrue($cache->set('k', 'v', -5));
        self::assertFalse($cache->has('k'));
    }

    public function test_an_unencodable_value_is_not_stored(): void
    {
        $cache = new MagentoCache($this->appCache);

        self::assertFalse($cache->set('k', NAN, 600));
        self::assertFalse($cache->has('k'));
    }

    public function test_a_corrupt_entry_is_a_miss(): void
    {
        $cache = new MagentoCache($this->appCache);
        $cache->set('k', 'v', 600);
        $id = (string) array_key_first($this->appCache->entries);
        $this->appCache->entries[$id]['data'] = 'not json';

        self::assertSame('gone', $cache->get('k', 'gone'));
    }

    public function test_delete_removes_one_entry_and_succeeds_for_a_missing_key(): void
    {
        $cache = new MagentoCache($this->appCache);
        $cache->set('a', 1, 600);
        $cache->set('b', 2, 600);

        self::assertTrue($cache->delete('a'));
        self::assertTrue($cache->delete('never-set'));
        self::assertFalse($cache->has('a'));
        self::assertSame(2, $cache->get('b'));
    }

    public function test_clear_removes_only_this_stores_entries(): void
    {
        $cache = new MagentoCache($this->appCache);
        $cache->set('a', 1, 600);
        $this->appCache->save('magento', 'OTHER_ENTRY', ['CONFIG']);

        self::assertTrue($cache->clear());
        self::assertFalse($cache->has('a'));
        self::assertSame('magento', $this->appCache->load('OTHER_ENTRY'));
    }

    public function test_multiple_operations_cover_every_key(): void
    {
        $cache = new MagentoCache($this->appCache);

        self::assertTrue($cache->setMultiple(['a' => 1, 'b' => 2], 600));
        self::assertSame(['a' => 1, 'b' => 2, 'c' => 'none'], $cache->getMultiple(['a', 'b', 'c'], 'none'));
        self::assertTrue($cache->deleteMultiple(['a', 'b']));
        self::assertSame(['a' => null, 'b' => null], $cache->getMultiple(['a', 'b']));
    }

    public function test_set_multiple_reports_a_failed_entry(): void
    {
        $cache = new MagentoCache($this->appCache);

        self::assertFalse($cache->setMultiple(['good' => 1, 'bad' => NAN], 600));
        self::assertSame(1, $cache->get('good'));
    }
}

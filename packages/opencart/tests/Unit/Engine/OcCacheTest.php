<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tests\Unit\Engine;

use PhpClaw\OpenCart\Engine\OcCache;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

final class OcCacheTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/phpclaw-oc-cache-'.getmypid().'-'.bin2hex(random_bytes(4)).'/';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function test_it_is_a_psr16_store(): void
    {
        self::assertInstanceOf(CacheInterface::class, new OcCache($this->dir));
    }

    public function test_a_miss_returns_the_default(): void
    {
        $cache = new OcCache($this->dir);

        self::assertSame('none', $cache->get('missing', 'none'));
        self::assertNull($cache->get('missing'));
        self::assertFalse($cache->has('missing'));
    }

    public function test_a_stored_array_reads_back_unchanged_from_a_second_instance(): void
    {
        $value = ['type' => 'text', 'text' => 'Lima', 'input_tokens' => 12];

        self::assertTrue((new OcCache($this->dir))->set('k', $value, 600));
        self::assertSame($value, (new OcCache($this->dir))->get('k'));
        self::assertTrue((new OcCache($this->dir))->has('k'));
    }

    public function test_an_entry_past_its_ttl_is_a_miss_and_is_removed(): void
    {
        $cache = new OcCache($this->dir);
        $cache->set('k', 'v', 600);
        $file = (string) (glob($this->dir.'*')[0] ?? '');
        $entry = json_decode((string) file_get_contents($file), true);
        $entry['e'] = time() - 1;
        file_put_contents($file, json_encode($entry));

        self::assertSame('gone', $cache->get('k', 'gone'));
        self::assertFileDoesNotExist($file);
    }

    public function test_a_null_ttl_never_expires(): void
    {
        $cache = new OcCache($this->dir);
        $cache->set('k', 'v');
        $entry = json_decode((string) file_get_contents((string) glob($this->dir.'*')[0]), true);

        self::assertSame(0, $entry['e']);
        self::assertSame('v', $cache->get('k'));
    }

    public function test_a_date_interval_ttl_sets_the_expiry(): void
    {
        $cache = new OcCache($this->dir);
        $cache->set('k', 'v', new \DateInterval('PT120S'));
        $entry = json_decode((string) file_get_contents((string) glob($this->dir.'*')[0]), true);

        self::assertEqualsWithDelta(time() + 120, $entry['e'], 2);
    }

    public function test_a_zero_or_negative_ttl_deletes_the_entry(): void
    {
        $cache = new OcCache($this->dir);
        $cache->set('k', 'v', 600);

        self::assertTrue($cache->set('k', 'new', 0));
        self::assertFalse($cache->has('k'));

        $cache->set('k', 'v', 600);
        self::assertTrue($cache->set('k', 'new', -5));
        self::assertNull($cache->get('k'));
    }

    public function test_a_value_that_cannot_be_encoded_is_not_stored(): void
    {
        $cache = new OcCache($this->dir);

        self::assertFalse($cache->set('k', "\xB1\x31", 600));
        self::assertSame([], glob($this->dir.'*') ?: []);
    }

    public function test_an_unreadable_entry_is_a_miss(): void
    {
        $cache = new OcCache($this->dir);
        $cache->set('k', 'v', 600);
        file_put_contents((string) glob($this->dir.'*')[0], 'not json');

        self::assertSame('none', $cache->get('k', 'none'));
    }

    public function test_delete_removes_one_entry_and_succeeds_for_a_missing_key(): void
    {
        $cache = new OcCache($this->dir);
        $cache->set('a', 1, 600);
        $cache->set('b', 2, 600);

        self::assertTrue($cache->delete('a'));
        self::assertTrue($cache->delete('never-set'));
        self::assertFalse($cache->has('a'));
        self::assertSame(2, $cache->get('b'));
    }

    public function test_clear_empties_only_the_store_directory(): void
    {
        $outside = sys_get_temp_dir().'/phpclaw-oc-cache-outside-'.getmypid().'.txt';
        file_put_contents($outside, 'keep');
        $cache = new OcCache($this->dir);
        $cache->set('a', 1, 600);

        self::assertTrue($cache->clear());
        self::assertFalse($cache->has('a'));
        self::assertFileExists($outside);
        unlink($outside);
    }

    public function test_multiple_operations_cover_every_key(): void
    {
        $cache = new OcCache($this->dir);

        self::assertTrue($cache->setMultiple(['a' => '1', 'b' => '2'], 600));
        self::assertSame(['a' => '1', 'b' => '2', 'c' => 'none'], [...$cache->getMultiple(['a', 'b', 'c'], 'none')]);
        self::assertTrue($cache->deleteMultiple(['a', 'b']));
        self::assertSame(['a' => null, 'b' => null], [...$cache->getMultiple(['a', 'b'])]);
    }

    public function test_a_store_without_a_directory_uses_the_opencart_cache_or_temp_dir(): void
    {
        $cache = new OcCache;
        $key = 'probe-'.bin2hex(random_bytes(4));

        self::assertTrue($cache->set($key, 'v', 60));
        self::assertSame('v', $cache->get($key));
        $cache->delete($key);
    }
}

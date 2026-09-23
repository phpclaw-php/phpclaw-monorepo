<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\AutoDiscovery;

use PhpClaw\AutoDiscovery\DiscoveryCache;
use PHPUnit\Framework\TestCase;

final class DiscoveryCacheLazyRebuildTest extends TestCase
{
    private string $tmpDir = '';

    private string $cachePath = '';

    private string $installedJsonPath = '';

    protected function setUp(): void
    {
        DiscoveryCache::reset();

        $this->tmpDir = sys_get_temp_dir().'/phpclaw-lazy-rebuild-'.bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o755, true);

        $this->cachePath = $this->tmpDir.'/phpclaw-discovery.php';
        $this->installedJsonPath = $this->tmpDir.'/installed.json';

        file_put_contents($this->installedJsonPath, '{"packages":[]}');
    }

    protected function tearDown(): void
    {
        DiscoveryCache::reset();

        foreach (glob($this->tmpDir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    public function test_first_load_with_no_cache_writes_a_fresh_cache(): void
    {
        $this->assertFileDoesNotExist($this->cachePath);

        $data = DiscoveryCache::load($this->cachePath);

        $this->assertFileExists($this->cachePath);
        $this->assertArrayHasKey('tools', $data);
    }

    public function test_load_after_installed_json_changes_rebuilds_silently(): void
    {
        DiscoveryCache::load($this->cachePath);
        $this->assertFileExists($this->cachePath);

        $originalCacheMtime = filemtime($this->cachePath);

        DiscoveryCache::reset();

        sleep(1);
        touch($this->installedJsonPath, time());

        DiscoveryCache::load($this->cachePath);

        clearstatcache();
        $newCacheMtime = filemtime($this->cachePath);

        $this->assertGreaterThan(
            $originalCacheMtime,
            $newCacheMtime,
            'cache should rebuild when installed.json is newer than the cache file',
        );
    }

    public function test_cache_content_matches_fresh_scan_after_rebuild(): void
    {
        $fresh = DiscoveryCache::rebuild($this->cachePath);
        DiscoveryCache::reset();

        $reloaded = DiscoveryCache::load($this->cachePath);

        $this->assertSame($fresh, $reloaded);
    }

    public function test_missing_vendor_composer_layout_falls_back_to_in_memory(): void
    {
        $data = DiscoveryCache::load('');

        foreach (['tools', 'providers', 'memory', 'skills', 'hooks', 'guards'] as $bucket) {
            $this->assertArrayHasKey($bucket, $data);
        }
    }

    public function test_rebuild_overwrites_an_existing_cache_file(): void
    {
        file_put_contents($this->cachePath, "<?php return ['tools' => ['stale-entry' => []]];");
        $originalSize = filesize($this->cachePath);

        DiscoveryCache::rebuild($this->cachePath);

        clearstatcache();
        $rebuiltSize = filesize($this->cachePath);

        $this->assertNotSame(
            $originalSize,
            $rebuiltSize,
            'rebuild should replace the stale file content',
        );
    }
}

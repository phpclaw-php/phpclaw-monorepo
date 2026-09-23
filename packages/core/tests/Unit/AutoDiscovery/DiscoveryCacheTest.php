<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\AutoDiscovery;

use PhpClaw\AutoDiscovery\DiscoveryCache;
use PHPUnit\Framework\TestCase;

final class DiscoveryCacheTest extends TestCase
{
    private string $tmpDir = '';

    private string $cachePath = '';

    private string $installedJsonPath = '';

    protected function setUp(): void
    {
        DiscoveryCache::reset();

        $this->tmpDir = sys_get_temp_dir().'/phpclaw-discovery-cache-test-'.bin2hex(random_bytes(6));
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

    public function test_load_returns_six_bucket_shape_when_no_cache_exists(): void
    {
        $data = DiscoveryCache::load($this->cachePath);

        $this->assertSame(
            ['tools', 'providers', 'memory', 'skills', 'hooks', 'guards'],
            array_keys($data),
        );
    }

    public function test_group_or_world_writable_temp_cache_is_untrusted(): void
    {
        if (! function_exists('posix_getuid')) {
            $this->markTestSkipped('posix extension required to verify ownership.');
        }

        $file = sys_get_temp_dir().'/phpclaw-disc-trust-'.bin2hex(random_bytes(6)).'.php';
        file_put_contents($file, '<?php return [];');
        chmod($file, 0o666);

        $this->assertFalse($this->invokeIsTrusted($file));

        @unlink($file);
    }

    public function test_owner_only_temp_cache_is_trusted(): void
    {
        if (! function_exists('posix_getuid')) {
            $this->markTestSkipped('posix extension required to verify ownership.');
        }

        $file = sys_get_temp_dir().'/phpclaw-disc-trust-'.bin2hex(random_bytes(6)).'.php';
        file_put_contents($file, '<?php return [];');
        chmod($file, 0o600);

        $this->assertTrue($this->invokeIsTrusted($file));

        @unlink($file);
    }

    public function test_non_temp_path_is_trusted_without_ownership_check(): void
    {
        $this->assertTrue($this->invokeIsTrusted('/var/www/app/vendor/composer/phpclaw-discovery.php'));
    }

    private function invokeIsTrusted(string $path): bool
    {
        $method = new \ReflectionMethod(DiscoveryCache::class, 'isTrustedCacheFile');
        $method->setAccessible(true);

        return (bool) $method->invoke(null, $path);
    }

    public function test_load_writes_cache_file_after_scan(): void
    {
        DiscoveryCache::load($this->cachePath);

        $this->assertFileExists($this->cachePath);
    }

    public function test_cache_file_contains_expected_array_structure(): void
    {
        DiscoveryCache::load($this->cachePath);
        DiscoveryCache::reset();

        $loaded = DiscoveryCache::load($this->cachePath);

        $this->assertArrayHasKey('tools', $loaded);
        $this->assertArrayHasKey('providers', $loaded);
    }

    public function test_is_fresh_returns_false_when_cache_missing(): void
    {
        $this->assertFalse(DiscoveryCache::isFresh($this->cachePath));
    }

    public function test_is_fresh_returns_true_when_cache_newer_than_installed_json(): void
    {
        touch($this->installedJsonPath, time() - 100);
        DiscoveryCache::load($this->cachePath);

        $this->assertTrue(DiscoveryCache::isFresh($this->cachePath));
    }

    public function test_is_fresh_returns_false_when_installed_json_newer_than_cache(): void
    {
        DiscoveryCache::load($this->cachePath);
        touch($this->installedJsonPath, time() + 100);

        $this->assertFalse(DiscoveryCache::isFresh($this->cachePath));
    }

    public function test_is_fresh_returns_true_when_no_installed_json(): void
    {
        DiscoveryCache::load($this->cachePath);
        unlink($this->installedJsonPath);

        $this->assertTrue(DiscoveryCache::isFresh($this->cachePath));
    }

    public function test_rebuild_regenerates_cache_even_when_fresh(): void
    {
        DiscoveryCache::load($this->cachePath);
        $originalMtime = filemtime($this->cachePath);

        sleep(1);
        DiscoveryCache::reset();
        DiscoveryCache::rebuild($this->cachePath);

        clearstatcache();
        $newMtime = filemtime($this->cachePath);

        $this->assertGreaterThan($originalMtime, $newMtime);
    }

    public function test_load_caches_result_in_memory_for_subsequent_calls(): void
    {
        $first = DiscoveryCache::load($this->cachePath);
        unlink($this->cachePath);
        $second = DiscoveryCache::load($this->cachePath);

        $this->assertSame($first, $second);
    }

    public function test_reset_clears_in_memory_cache(): void
    {
        DiscoveryCache::load($this->cachePath);
        $this->assertFileExists($this->cachePath);

        DiscoveryCache::reset();
        unlink($this->cachePath);

        $rebuilt = DiscoveryCache::load($this->cachePath);
        $this->assertArrayHasKey('tools', $rebuilt);
    }

    public function test_load_degrades_to_scan_when_cache_path_empty(): void
    {
        $data = DiscoveryCache::load('');

        $this->assertArrayHasKey('tools', $data);
        $this->assertArrayHasKey('providers', $data);
    }

    public function test_load_recovers_when_cache_file_is_malformed(): void
    {
        file_put_contents($this->cachePath, "<?php return 'not-an-array';");
        touch($this->cachePath, time() + 1000);
        DiscoveryCache::reset();

        $data = DiscoveryCache::load($this->cachePath);

        $this->assertArrayHasKey('tools', $data);
        $this->assertIsArray($data['tools']);
    }

    public function test_default_path_returns_empty_or_real_path(): void
    {
        $path = DiscoveryCache::defaultPath();

        $this->assertTrue($path === '' || str_ends_with($path, 'phpclaw-discovery.php'));
    }

    public function test_writes_use_atomic_rename_so_no_partial_file_remains(): void
    {
        DiscoveryCache::load($this->cachePath);

        $partials = glob($this->cachePath.'.tmp.*');
        $this->assertSame([], $partials ?: []);
    }

    public function test_write_skipped_when_target_dir_does_not_exist(): void
    {
        $unwritablePath = '/tmp/phpclaw-does-not-exist-'.bin2hex(random_bytes(4)).'/cache.php';

        $data = DiscoveryCache::load($unwritablePath);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('tools', $data);
        $this->assertFileDoesNotExist($unwritablePath);
    }

    public function test_load_recovers_when_cache_file_returns_non_array(): void
    {
        file_put_contents($this->cachePath, '<?php return 42;');
        touch($this->cachePath, time() + 1000);
        DiscoveryCache::reset();

        $data = DiscoveryCache::load($this->cachePath);

        $this->assertIsArray($data['tools']);
    }

    public function test_load_recovers_when_cache_file_missing_required_bucket(): void
    {
        file_put_contents(
            $this->cachePath,
            "<?php return ['tools' => [], 'providers' => [], 'memory' => [], 'skills' => [], 'hooks' => []];",
        );
        touch($this->cachePath, time() + 1000);
        DiscoveryCache::reset();

        $data = DiscoveryCache::load($this->cachePath);

        foreach (['tools', 'providers', 'memory', 'skills', 'hooks', 'guards'] as $bucket) {
            $this->assertArrayHasKey($bucket, $data);
        }
    }

    public function test_load_recovers_when_cache_bucket_is_not_an_array(): void
    {
        file_put_contents(
            $this->cachePath,
            "<?php return ['tools' => 'not-array', 'providers' => [], 'memory' => [], 'skills' => [], 'hooks' => [], 'guards' => []];",
        );
        touch($this->cachePath, time() + 1000);
        DiscoveryCache::reset();

        $data = DiscoveryCache::load($this->cachePath);

        $this->assertIsArray($data['tools']);
    }
}

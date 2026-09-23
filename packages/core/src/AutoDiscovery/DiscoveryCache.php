<?php

declare(strict_types=1);

namespace PhpClaw\AutoDiscovery;

/**
 * On-disk cache for {@see AttributeScanner} output: fast-paths via `require` on warm OPcache, degrades to in-memory scan on cache failure. The process-wide `$memoryCache` is an accepted static memoization cache (Point F memoization exemption), cleared via reset() for test isolation.
 *
 * @internal Use {@see Bootstrap} typed accessors. This class is a building block.
 */
final class DiscoveryCache
{
    private const CACHE_FILENAME = 'phpclaw-discovery.php';

    private static ?array $memoryCache = null;

    /**
     * Return the cached discovery map, building it if missing or stale.
     *
     * @param  string|null  $cachePath  Filesystem path to the cache file.
     * @return array<string, mixed>
     */
    public static function load(?string $cachePath = null): array
    {
        if (self::$memoryCache !== null) {
            return self::$memoryCache;
        }

        $cachePath ??= self::defaultPath();

        if ($cachePath !== '' && self::isFresh($cachePath) && self::isTrustedCacheFile($cachePath)) {
            $loaded = require $cachePath;
            if (self::isWellFormed($loaded)) {
                self::$memoryCache = $loaded;

                return $loaded;
            }
        }

        $scan = AttributeScanner::scan();

        if ($cachePath !== '') {
            self::writeAtomic($cachePath, $scan);
        }

        self::$memoryCache = $scan;

        return $scan;
    }

    /**
     * Force a rebuild + write of the cache. Returns the freshly scanned map.
     *
     * @param  string|null  $cachePath  Filesystem path to the cache file.
     * @return array<string, mixed>
     */
    public static function rebuild(?string $cachePath = null): array
    {
        $cachePath ??= self::defaultPath();
        $scan = AttributeScanner::scan();

        if ($cachePath !== '') {
            self::writeAtomic($cachePath, $scan);
        }

        self::$memoryCache = $scan;

        return $scan;
    }

    /**
     * Whether the cache file is present and newer than `installed.json`.
     *
     * @param  string  $cachePath  Filesystem path to the cache file.
     * @return bool True on success.
     */
    public static function isFresh(string $cachePath): bool
    {
        if (! is_file($cachePath)) {
            return false;
        }

        $installedJson = dirname($cachePath).'/installed.json';
        if (! is_file($installedJson)) {
            return true;
        }

        $cacheTime = @filemtime($cachePath);
        $installedTime = @filemtime($installedJson);

        if ($cacheTime === false || $installedTime === false) {
            return false;
        }

        return $cacheTime >= $installedTime;
    }

    /**
     * Resolve the canonical cache path under `vendor/composer/`.
     *
     * @return string The resulting value.
     */
    public static function defaultPath(): string
    {
        $candidates = [
            __DIR__.'/../../vendor/composer/'.self::CACHE_FILENAME,
            __DIR__.'/../../../../../vendor/composer/'.self::CACHE_FILENAME,
            __DIR__.'/../../../../../../vendor/composer/'.self::CACHE_FILENAME,
        ];

        foreach ($candidates as $path) {
            $dir = dirname($path);
            if (is_dir($dir) && is_writable($dir)) {
                return $path;
            }
        }

        return sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpclaw-discovery-'.substr(md5(__FILE__), 0, 8).'.php';
    }

    /**
     * Clear the in-memory cache. Use only in test tearDown.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$memoryCache = null;
    }

    /**
     * Whether a cache file is safe to `require`: files under a system-temp fallback must be owned by the current user and not group/world-writable, so a co-tenant cannot pre-plant executable PHP at the predictable path.
     *
     * @param  string  $cachePath  Filesystem path to the cache file about to be required.
     * @return bool True for non-temp (app-owned) paths, and for temp paths owned by us and not group/world-writable.
     */
    private static function isTrustedCacheFile(string $cachePath): bool
    {
        if (! str_starts_with($cachePath, sys_get_temp_dir())) {
            return true;
        }

        if (! function_exists('posix_getuid') || ! function_exists('fileowner')) {
            return false;
        }

        $owner = @fileowner($cachePath);
        if ($owner === false || $owner !== posix_getuid()) {
            return false;
        }

        $perms = @fileperms($cachePath);

        return $perms !== false && ($perms & 0o022) === 0;
    }

    /**
     * Write the cache file atomically via a temp file + rename; swallows failures so the cache degrades to in-memory.
     *
     * @param  string  $cachePath  Filesystem path to the cache file.
     * @param  array  $data  Data to write.
     * @return void
     */
    private static function writeAtomic(string $cachePath, array $data): void
    {
        $dir = dirname($cachePath);
        if (! is_dir($dir) || ! is_writable($dir)) {
            return;
        }

        $payload = "<?php\n\n"
            ."// Auto-generated by PhpClaw\\AutoDiscovery\\DiscoveryCache.\n"
            ."// Do not edit. Run `composer dump-autoload` or delete to regenerate.\n\n"
            .'return '.var_export($data, true).";\n";

        $tmp = $cachePath.'.tmp.'.bin2hex(random_bytes(6));

        if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
            @unlink($tmp);

            return;
        }

        if (! @rename($tmp, $cachePath)) {
            @unlink($tmp);
        }
    }

    /**
     * Sanity-check a loaded cache file has the expected top-level shape.
     *
     * @param  mixed  $loaded  Loaded cache payload to validate.
     * @return bool True on success.
     */
    private static function isWellFormed(mixed $loaded): bool
    {
        if (! is_array($loaded)) {
            return false;
        }

        foreach (['tools', 'providers', 'memory', 'skills', 'hooks', 'guards'] as $key) {
            if (! array_key_exists($key, $loaded) || ! is_array($loaded[$key])) {
                return false;
            }
        }

        return true;
    }
}

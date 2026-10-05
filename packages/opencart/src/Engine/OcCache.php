<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Engine;

use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 store as one JSON file per key in its own folder under OpenCart's cache directory, so it works the same on
 * OpenCart 3 and 4, admin, catalog and CLI; values must be JSON-encodable.
 */
final class OcCache implements CacheInterface
{
    private const FOLDER = 'phpclaw/';

    private readonly string $dir;

    /**
     * Create the store in the given folder, or in phpclaw/ under OpenCart's DIR_CACHE (the system temp dir when unset).
     *
     * @param  string|null  $dir  Folder holding the entries, with a trailing slash; null uses the default.
     * @return void
     */
    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? (defined('DIR_CACHE') ? (string) DIR_CACHE : rtrim(sys_get_temp_dir(), '/').'/').self::FOLDER;
    }

    /**
     * Fetch a cached value, or $default when the entry is missing, unreadable or past its expiry.
     *
     * @param  string  $key  The unique key of this item in the cache.
     * @param  mixed  $default  Default value to return on a cache miss.
     * @return mixed The stored value, or $default on a miss.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $entry = $this->readEntry($key);

        return $entry === null ? $default : $entry['v'];
    }

    /**
     * Store a value with the given TTL; a TTL of zero or less deletes the entry instead.
     *
     * @param  string  $key  The key of the item to store.
     * @param  mixed  $value  The JSON-encodable value to store.
     * @param  null|int|\DateInterval  $ttl  Seconds until expiry, an interval converted to seconds, or null for no expiry.
     * @return bool True on success, false when the value cannot be encoded or the file cannot be written.
     */
    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $seconds = self::ttlSeconds($ttl);

        if ($seconds !== null && $seconds <= 0) {
            return $this->delete($key);
        }

        $payload = json_encode(['v' => $value, 'e' => $seconds === null ? 0 : time() + $seconds]);

        if ($payload === false) {
            return false;
        }

        if (! is_dir($this->dir) && ! @mkdir($this->dir, 0775, true) && ! is_dir($this->dir)) {
            return false;
        }

        return file_put_contents($this->path($key), $payload, LOCK_EX) !== false;
    }

    /**
     * Delete a single cached value by key; a key that is not stored counts as deleted.
     *
     * @param  string  $key  The unique cache key of the item to delete.
     * @return bool Always true.
     */
    public function delete(string $key): bool
    {
        $path = $this->path($key);

        if (is_file($path)) {
            @unlink($path);
        }

        return true;
    }

    /**
     * Delete every entry in this store's folder, leaving the rest of OpenCart's cache alone.
     *
     * @return bool Always true.
     */
    public function clear(): bool
    {
        foreach (glob($this->dir.'*.json') ?: [] as $file) {
            @unlink($file);
        }

        return true;
    }

    /**
     * Fetch several cached values by key in one call.
     *
     * @param  iterable<string>  $keys  Keys to obtain in a single operation.
     * @param  mixed  $default  Default value for keys that do not exist.
     * @return iterable<string, mixed> Key => value pairs; a miss carries $default.
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];

        foreach ($keys as $key) {
            $result[(string) $key] = $this->get((string) $key, $default);
        }

        return $result;
    }

    /**
     * Store several key => value pairs with the same TTL.
     *
     * @param  iterable<string, mixed>  $values  Key => value pairs to store.
     * @param  null|int|\DateInterval  $ttl  Seconds until expiry, an interval converted to seconds, or null for no expiry.
     * @return bool True only when every entry was stored.
     */
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        $allOk = true;

        foreach ($values as $key => $value) {
            $allOk = $this->set((string) $key, $value, $ttl) && $allOk;
        }

        return $allOk;
    }

    /**
     * Delete several cached values by key.
     *
     * @param  iterable<string>  $keys  Keys to delete in a single operation.
     * @return bool Always true.
     */
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete((string) $key);
        }

        return true;
    }

    /**
     * Check whether a readable, unexpired entry exists for the key.
     *
     * @param  string  $key  The cache item key.
     * @return bool True when present and not expired.
     */
    public function has(string $key): bool
    {
        return $this->readEntry($key) !== null;
    }

    /**
     * Read and decode one entry, removing it and answering null when it is past its expiry.
     *
     * @param  string  $key  PSR-16 cache key.
     * @return array{v: mixed, e: int}|null
     */
    private function readEntry(string $key): ?array
    {
        $path = $this->path($key);
        $raw = is_file($path) ? @file_get_contents($path) : false;

        if (! is_string($raw)) {
            return null;
        }

        $entry = json_decode($raw, true);

        if (! is_array($entry) || ! array_key_exists('v', $entry) || ! is_int($entry['e'] ?? null)) {
            return null;
        }

        if ($entry['e'] !== 0 && $entry['e'] < time()) {
            @unlink($path);

            return null;
        }

        return ['v' => $entry['v'], 'e' => $entry['e']];
    }

    /**
     * File path for a PSR-16 key: an md5 hash, so any key length or character set is a valid file name.
     *
     * @param  string  $key  PSR-16 cache key.
     * @return string
     */
    private function path(string $key): string
    {
        return $this->dir.md5($key).'.json';
    }

    /**
     * Normalise a PSR-16 TTL to whole seconds, or null for no expiry; an interval is measured from a fixed epoch.
     *
     * @param  null|int|\DateInterval  $ttl
     * @return int|null
     */
    private static function ttlSeconds(null|int|\DateInterval $ttl): ?int
    {
        if ($ttl instanceof \DateInterval) {
            return (new \DateTimeImmutable('@0'))->add($ttl)->getTimestamp();
        }

        return $ttl;
    }
}

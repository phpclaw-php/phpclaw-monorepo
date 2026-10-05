<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Component\Administrator\Engine;

use Joomla\CMS\Cache\Cache;
use Joomla\CMS\Factory;
use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 store on Joomla's own cache, kept on even when site caching is off, holding each entry's expiry in the
 * stored value because Joomla's lifetime is per group; values must be JSON-encodable.
 */
final class JoomlaCache implements CacheInterface
{
    private const GROUP = 'phpclaw_cache';

    private const LIFETIME_MINUTES = 1440;

    private const DEFAULT_STORAGE = 'file';

    private readonly Cache $cache;

    /**
     * Create the store on the given Joomla cache, or on a new one using the site's cache handler.
     *
     * @param  Cache|null  $cache  Joomla cache to store in; null builds one with caching forced on.
     * @return void
     */
    public function __construct(?Cache $cache = null)
    {
        $this->cache = $cache ?? new Cache([
            'defaultgroup' => self::GROUP,
            'caching' => true,
            'storage' => self::storageHandler(),
            'lifetime' => self::LIFETIME_MINUTES,
        ]);
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
     * @return bool True on success, false when the value cannot be encoded or the cache refuses it.
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

        return (bool) $this->cache->store($payload, self::id($key));
    }

    /**
     * Delete a single cached value by key; a key that is not stored counts as deleted.
     *
     * @param  string  $key  The unique cache key of the item to delete.
     * @return bool Always true.
     */
    public function delete(string $key): bool
    {
        $this->cache->remove(self::id($key));

        return true;
    }

    /**
     * Wipe every entry in this store's cache group.
     *
     * @return bool True when the cache group was cleaned.
     */
    public function clear(): bool
    {
        return (bool) $this->cache->clean();
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
        $raw = $this->cache->get(self::id($key));

        if (! is_string($raw)) {
            return null;
        }

        $entry = json_decode($raw, true);

        if (! is_array($entry) || ! array_key_exists('v', $entry) || ! is_int($entry['e'] ?? null)) {
            return null;
        }

        if ($entry['e'] !== 0 && $entry['e'] < time()) {
            $this->cache->remove(self::id($key));

            return null;
        }

        return ['v' => $entry['v'], 'e' => $entry['e']];
    }

    /**
     * Joomla cache id for a PSR-16 key: an md5 hash, so any key length or character set is a valid id.
     *
     * @param  string  $key  PSR-16 cache key.
     * @return string
     */
    private static function id(string $key): string
    {
        return md5($key);
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

    /**
     * The site's cache handler from global configuration, or file storage when none is set.
     *
     * @return string
     */
    private static function storageHandler(): string
    {
        $handler = (string) Factory::getApplication()->get('cache_handler', '');

        return $handler !== '' ? $handler : self::DEFAULT_STORAGE;
    }
}

<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Service;

use Magento\Framework\App\CacheInterface as AppCache;
use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 store on Magento's application cache, with every entry tagged so clear() removes only phpClaw's entries;
 * values must be JSON-encodable.
 */
final class MagentoCache implements CacheInterface
{
    private const TAG = 'PHPCLAW_AGENT_CACHE';

    private const ID_PREFIX = 'PHPCLAW_';

    /**
     * Create the store on Magento's application cache.
     *
     * @param  AppCache  $cache  Magento's application cache, which holds the entries and enforces their lifetime.
     * @return void
     */
    public function __construct(
        private readonly AppCache $cache,
    ) {}

    /**
     * Fetch a cached value, or $default when the entry is missing, expired or unreadable.
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
     * @return bool True on success, false when the value cannot be encoded or Magento does not store it.
     */
    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $seconds = self::ttlSeconds($ttl);

        if ($seconds !== null && $seconds <= 0) {
            return $this->delete($key);
        }

        $payload = json_encode(['v' => $value]);

        if ($payload === false) {
            return false;
        }

        return $this->cache->save($payload, self::id($key), [self::TAG], $seconds);
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
     * Delete every phpClaw entry, leaving the rest of Magento's cache alone.
     *
     * @return bool Always true.
     */
    public function clear(): bool
    {
        $this->cache->clean([self::TAG]);

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
     * @return bool True when present.
     */
    public function has(string $key): bool
    {
        return $this->readEntry($key) !== null;
    }

    /**
     * Load and decode one entry, answering null when it is missing or not in the shape this store writes.
     *
     * @param  string  $key  PSR-16 cache key.
     * @return array{v: mixed}|null
     */
    private function readEntry(string $key): ?array
    {
        $raw = $this->cache->load(self::id($key));

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $entry = json_decode($raw, true);

        return is_array($entry) && array_key_exists('v', $entry) ? ['v' => $entry['v']] : null;
    }

    /**
     * Magento cache id for a PSR-16 key: an md5 hash, so any key length or character set is a valid id.
     *
     * @param  string  $key  PSR-16 cache key.
     * @return string
     */
    private static function id(string $key): string
    {
        return self::ID_PREFIX.md5($key);
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

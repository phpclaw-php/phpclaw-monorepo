<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Cache;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 store on a Drupal cache bin, using the bin's own per-item expiry, so it works the same for Drush, REST and
 * the admin chat.
 */
final class DrupalCache implements CacheInterface
{
    /**
     * Create the store on a Drupal cache bin.
     *
     * @param  CacheBackendInterface  $backend  Cache bin holding the entries (phpClaw uses cache.phpclaw).
     * @param  TimeInterface  $time  Clock used to turn a TTL into the bin's absolute expiry.
     * @return void
     */
    public function __construct(
        private readonly CacheBackendInterface $backend,
        private readonly TimeInterface $time,
    ) {}

    /**
     * Fetch a cached value, or $default when the entry is missing or past its expiry.
     *
     * @param  string  $key  The unique key of this item in the cache.
     * @param  mixed  $default  Default value to return on a cache miss.
     * @return mixed The stored value, or $default on a miss.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $item = $this->backend->get($key);

        return $item === false ? $default : $item->data;
    }

    /**
     * Store a value with the given TTL; a TTL of zero or less deletes the entry instead.
     *
     * @param  string  $key  The key of the item to store.
     * @param  mixed  $value  The value to store.
     * @param  null|int|\DateInterval  $ttl  Seconds until expiry, an interval converted to seconds, or null for no expiry.
     * @return bool Always true.
     */
    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $seconds = self::ttlSeconds($ttl);

        if ($seconds !== null && $seconds <= 0) {
            return $this->delete($key);
        }

        $expire = $seconds === null ? CacheBackendInterface::CACHE_PERMANENT : (int) $this->time->getCurrentTime() + $seconds;
        $this->backend->set($key, $value, $expire);

        return true;
    }

    /**
     * Delete a single cached value by key; a key that is not stored counts as deleted.
     *
     * @param  string  $key  The unique cache key of the item to delete.
     * @return bool Always true.
     */
    public function delete(string $key): bool
    {
        $this->backend->delete($key);

        return true;
    }

    /**
     * Delete every entry in this store's cache bin.
     *
     * @return bool Always true.
     */
    public function clear(): bool
    {
        $this->backend->deleteAll();

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
     * @return bool Always true.
     */
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $ttl);
        }

        return true;
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
     * Check whether an unexpired entry exists for the key.
     *
     * @param  string  $key  The cache item key.
     * @return bool True when present and not expired.
     */
    public function has(string $key): bool
    {
        return $this->backend->get($key) !== false;
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

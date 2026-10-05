<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Support;

use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 response cache store on the WordPress Transients API; never share one instance
 * across sites, transients are per-site by design so keys never cross a site boundary.
 */
final class TransientCache implements CacheInterface
{
    private const PREFIX = 'phpclaw_rc_';

    /**
     * Fetch a cached value, or $default when there is no non-expired transient for the key.
     *
     * @param  string  $key  The unique key of this item in the cache.
     * @param  mixed  $default  Default value to return on a cache miss.
     * @return mixed The stored value, or $default on a miss.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $value = get_transient($this->transientKey($key));

        return $value === false ? $default : $value;
    }

    /**
     * Store a value as a transient with the given TTL.
     *
     * @param  string  $key  The key of the item to store.
     * @param  mixed  $value  The value to store.
     * @param  null|int|\DateInterval  $ttl  Seconds until expiry, an interval converted to seconds, or null for no expiry.
     * @return bool True on success, false on failure.
     */
    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        return set_transient($this->transientKey($key), $value, $this->ttlSeconds($ttl));
    }

    /**
     * Delete a single cached value by key.
     *
     * @param  string  $key  The unique cache key of the item to delete.
     * @return bool True if the item was removed, false on failure.
     */
    public function delete(string $key): bool
    {
        return delete_transient($this->transientKey($key));
    }

    /**
     * Transients cannot be enumerated without a direct SQL scan, so this store cannot wipe
     * every key it has ever written; always returns false, which PSR-16 permits for failure.
     *
     * @return bool Always false.
     */
    public function clear(): bool
    {
        return false;
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
     * @return bool True only when every entry was removed.
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $allOk = true;

        foreach ($keys as $key) {
            $allOk = $this->delete((string) $key) && $allOk;
        }

        return $allOk;
    }

    /**
     * Check whether a non-expired transient exists for the key.
     *
     * @param  string  $key  The cache item key.
     * @return bool True when present and not expired.
     */
    public function has(string $key): bool
    {
        return get_transient($this->transientKey($key)) !== false;
    }

    /**
     * Build the transient name for a PSR-16 key: a fixed prefix plus an md5 hash, so any
     * key length or character set is always a valid, collision-resistant transient name.
     *
     * @param  string  $key  PSR-16 cache key.
     * @return string
     */
    private function transientKey(string $key): string
    {
        return self::PREFIX.md5($key);
    }

    /**
     * Normalise a PSR-16 TTL to whole seconds: null becomes 0 (no expiry), an int passes
     * through, and a DateInterval is measured from a fixed epoch so it never depends on "now".
     *
     * @param  null|int|\DateInterval  $ttl
     * @return int
     */
    private function ttlSeconds(null|int|\DateInterval $ttl): int
    {
        if ($ttl === null) {
            return 0;
        }

        if ($ttl instanceof \DateInterval) {
            return (new \DateTimeImmutable('@0'))->add($ttl)->getTimestamp();
        }

        return $ttl;
    }
}

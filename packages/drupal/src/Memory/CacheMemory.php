<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Memory;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Lock\LockBackendInterface;
use PhpClaw\Hooks\HookDispatcher;
use PhpClaw\Memory\Contracts\MemoryInterface;

/**
 * Drupal Cache API-backed memory driver for phpClaw.
 */
final class CacheMemory implements MemoryInterface
{
    private const PREFIX = 'phpclaw:';

    private const TRACKER = 'phpclaw:_tracker:';

    private const DRIVER_NAME = 'drupal_cache';

    /**
     * Create a new CacheMemory instance.
     *
     * @param  CacheBackendInterface  $cache  The Drupal cache backend used for storage.
     * @param  TimeInterface  $time  Drupal time service for TTL calculation.
     * @param  LockBackendInterface|null  $lock  Drupal lock backend, guards concurrent tracker writes (nullable for test compatibility).
     * @return void
     */
    public function __construct(
        private readonly CacheBackendInterface $cache,
        private readonly TimeInterface $time,
        private readonly ?LockBackendInterface $lock = null,
    ) {}

    /**
     * Retrieve a value from cache by key and namespace.
     *
     * @param  string  $key  Memory key to look up.
     * @param  string  $namespace  Namespace the key belongs to; defaults to 'default'.
     * @return mixed The cached value, or null on a cache miss.
     */
    public function get(string $key, string $namespace = 'default'): mixed
    {
        $item = $this->cache->get($this->cacheKey($namespace, $key));
        $hit = $item !== false;

        HookDispatcher::memoryRead($key, $namespace, self::DRIVER_NAME, $hit);

        return $hit ? $item->data : null;
    }

    /**
     * Store a value in cache with optional TTL in seconds.
     *
     * @param  string  $key  Memory key to write.
     * @param  mixed  $value  Value to store; can be any serializable type.
     * @param  string  $namespace  Namespace to store the key under; defaults to 'default'.
     * @param  int|null  $ttl  Seconds until expiry; null means permanently cached.
     * @return void
     */
    public function set(string $key, mixed $value, string $namespace = 'default', ?int $ttl = null): void
    {
        $expire = $ttl !== null
            ? $this->time->getCurrentTime() + $ttl
            : CacheBackendInterface::CACHE_PERMANENT;

        $this->cache->set($this->cacheKey($namespace, $key), $value, $expire);
        $this->trackerAdd($namespace, $key);

        HookDispatcher::memoryWrite($key, $namespace, self::DRIVER_NAME, $ttl);
    }

    /**
     * Delete a single key from cache.
     *
     * @param  string  $key  Memory key to delete.
     * @param  string  $namespace  Namespace the key belongs to; defaults to 'default'.
     * @return void
     */
    public function forget(string $key, string $namespace = 'default'): void
    {
        $this->cache->delete($this->cacheKey($namespace, $key));
        $this->trackerRemove($namespace, $key);

        HookDispatcher::memoryForget($key, $namespace, self::DRIVER_NAME);
    }

    /**
     * Flush all keys in a namespace from cache.
     *
     * @param  string  $namespace  Namespace to clear; all tracked keys for this namespace are deleted; defaults to 'default'.
     * @return void
     */
    public function flush(string $namespace = 'default'): void
    {
        $known = $this->trackerGet($namespace);

        foreach ($known as $shortKey) {
            $this->cache->delete($this->cacheKey($namespace, $shortKey));
        }

        $this->cache->delete(self::TRACKER.$namespace);

        HookDispatcher::memoryForget('*', $namespace, self::DRIVER_NAME);
    }

    /**
     * Get all entries in a namespace.
     *
     * @param  string  $namespace  Namespace to enumerate; defaults to 'default'.
     * @return array<string, mixed>
     */
    public function all(string $namespace = 'default'): array
    {
        $known = $this->trackerGet($namespace);
        $result = [];

        foreach ($known as $shortKey) {
            $item = $this->cache->get($this->cacheKey($namespace, $shortKey));
            if ($item !== false) {
                $result[$shortKey] = $item->data;
            }
        }

        return $result;
    }

    /**
     * Check whether a key exists in cache.
     *
     * @param  string  $key  Memory key to check.
     * @param  string  $namespace  Namespace to search within; defaults to 'default'.
     * @return bool True if the item is in cache and not expired, false otherwise.
     */
    public function has(string $key, string $namespace = 'default'): bool
    {
        return $this->cache->get($this->cacheKey($namespace, $key)) !== false;
    }

    /**
     * Build the prefixed cache key for the given namespace and key.
     *
     * @param  string  $namespace  Namespace segment of the cache key; colons are replaced with underscores.
     * @param  string  $key  Short key segment; colons are replaced with underscores.
     * @return string Fully prefixed cache key in the form 'phpclaw:{namespace}:{key}'.
     */
    private function cacheKey(string $namespace, string $key): string
    {
        return self::PREFIX.str_replace(':', '_', $namespace).':'.str_replace(':', '_', $key);
    }

    /**
     * Get the list of tracked keys for the given namespace.
     *
     * @param  string  $namespace  Namespace whose key list to retrieve.
     * @return string[]
     */
    private function trackerGet(string $namespace): array
    {
        $item = $this->cache->get(self::TRACKER.$namespace);

        return $item !== false && is_array($item->data) ? $item->data : [];
    }

    /**
     * Add a key to the namespace tracker if not already present.
     *
     * @param  string  $namespace  Namespace whose tracker to update.
     * @param  string  $key  Short key to register in the tracker.
     * @return void
     */
    private function trackerAdd(string $namespace, string $key): void
    {
        $this->withTrackerLock($namespace, function () use ($namespace, $key): void {
            $known = $this->trackerGet($namespace);

            if (! in_array($key, $known, true)) {
                $known[] = $key;
                $this->cache->set(self::TRACKER.$namespace, $known, CacheBackendInterface::CACHE_PERMANENT);
            }
        });
    }

    /**
     * Remove a key from the namespace tracker.
     *
     * @param  string  $namespace  Namespace whose tracker to update.
     * @param  string  $key  Short key to remove from the tracker.
     * @return void
     */
    private function trackerRemove(string $namespace, string $key): void
    {
        $this->withTrackerLock($namespace, function () use ($namespace, $key): void {
            $known = $this->trackerGet($namespace);
            $filtered = array_values(array_filter($known, fn ($k) => $k !== $key));

            if ($filtered === []) {
                $this->cache->delete(self::TRACKER.$namespace);
            } elseif (count($filtered) !== count($known)) {
                $this->cache->set(self::TRACKER.$namespace, $filtered, CacheBackendInterface::CACHE_PERMANENT);
            }
        });
    }

    /**
     * Run a tracker read-check-write callback under a Drupal lock, preventing concurrent writers from losing updates.
     *
     * @param  string  $namespace  Namespace whose tracker lock to acquire.
     * @param  callable  $callback  Read-check-write body to run once the lock is held.
     * @return void
     */
    private function withTrackerLock(string $namespace, callable $callback): void
    {
        if ($this->lock === null) {
            $callback();

            return;
        }

        $lockName = self::TRACKER.$namespace;
        $acquired = $this->lock->acquire($lockName);

        if (! $acquired) {
            $this->lock->wait($lockName);
            $acquired = $this->lock->acquire($lockName);
        }

        if (! $acquired) {
            return;
        }

        try {
            $callback();
        } finally {
            $this->lock->release($lockName);
        }
    }
}

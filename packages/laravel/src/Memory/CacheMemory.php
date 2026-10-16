<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Memory;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use PhpClaw\Exceptions\MemoryException;
use PhpClaw\Hooks\HookDispatcher;
use PhpClaw\Memory\Contracts\MemoryInterface;

/**
 * Laravel Cache-backed memory driver that wraps any configured cache store as a phpClaw memory backend.
 */
final class CacheMemory implements MemoryInterface
{
    private const DRIVER_NAME = 'cache';

    private const DEFAULT_PREFIX = 'phpclaw:';

    private const DEFAULT_TTL_SECONDS = 86400;

    private const TRACKER_LOCK_PREFIX = 'phpclaw_tracker_lock:';

    private const TRACKER_LOCK_SECONDS = 5;

    private const TRACKER_LOCK_WAIT_SECONDS = 3;

    /**
     * Bind the cache repository and key prefix this driver stores entries under.
     *
     * @param  Repository|null  $store  Cache repository to use, or null for the default store.
     * @param  string  $prefix  Key prefix for all phpClaw cache entries.
     * @param  int  $defaultTtl  Default TTL in seconds when none is given.
     * @return void
     */
    public function __construct(
        private readonly ?Repository $store = null,
        private readonly string $prefix = self::DEFAULT_PREFIX,
        private readonly int $defaultTtl = self::DEFAULT_TTL_SECONDS,
    ) {}

    /**
     * Retrieve a cached value by key and namespace.
     *
     * @param  string  $key
     * @param  string  $namespace
     * @return mixed
     */
    public function get(string $key, string $namespace = 'default'): mixed
    {
        try {
            $value = $this->repo()->get($this->key($namespace, $key));
            $hit = $value !== null;

            HookDispatcher::memoryRead($key, $namespace, self::DRIVER_NAME, hit: $hit);

            return $value;
        } catch (\Throwable $e) {
            throw new MemoryException('CacheMemory::get failed.', previous: $e);
        }
    }

    /**
     * Store a value under the given key and namespace, with an optional TTL in seconds.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @param  string  $namespace
     * @param  ?int  $ttl
     * @return void
     */
    public function set(string $key, mixed $value, string $namespace = 'default', ?int $ttl = null): void
    {
        try {
            $expiry = $ttl ?? $this->defaultTtl;
            $fullKey = $this->key($namespace, $key);

            if ($expiry > 0) {
                $this->repo()->put($fullKey, $value, $expiry);
            } else {
                $this->repo()->forever($fullKey, $value);
            }

            $this->trackerAdd($namespace, $key);

            HookDispatcher::memoryWrite($key, $namespace, self::DRIVER_NAME, $ttl);
        } catch (\Throwable $e) {
            throw new MemoryException('CacheMemory::set failed.', previous: $e);
        }
    }

    /**
     * Remove a single key from the given namespace and update the tracker index.
     *
     * @param  string  $key
     * @param  string  $namespace
     * @return void
     */
    public function forget(string $key, string $namespace = 'default'): void
    {
        try {
            $this->repo()->forget($this->key($namespace, $key));
            $this->trackerRemove($namespace, $key);

            HookDispatcher::memoryForget($key, $namespace, self::DRIVER_NAME);
        } catch (\Throwable $e) {
            throw new MemoryException('CacheMemory::forget failed.', previous: $e);
        }
    }

    /**
     * Remove all keys tracked under the given namespace without touching other cache entries.
     *
     * @param  string  $namespace
     * @return void
     */
    public function flush(string $namespace = 'default'): void
    {
        try {
            $repo = $this->repo();

            $trackerKey = $this->trackerKey($namespace);
            $known = (array) $repo->get($trackerKey, []);

            foreach ($known as $shortKey) {
                $repo->forget($this->key($namespace, $shortKey));
            }

            $repo->forget($trackerKey);
        } catch (\Throwable $e) {
            throw new MemoryException('CacheMemory::flush failed.', previous: $e);
        }
    }

    /**
     * Return all tracked key-value pairs in the given namespace, skipping expired entries.
     *
     * @param  string  $namespace
     * @return array<string, mixed>
     */
    public function all(string $namespace = 'default'): array
    {
        try {
            $repo = $this->repo();
            $trackerKey = $this->trackerKey($namespace);
            $known = (array) $repo->get($trackerKey, []);

            $result = [];
            foreach ($known as $shortKey) {
                $value = $repo->get($this->key($namespace, $shortKey));
                if ($value !== null) {
                    $result[$shortKey] = $value;
                }
            }

            return $result;
        } catch (\Throwable $e) {
            throw new MemoryException('CacheMemory::all failed.', previous: $e);
        }
    }

    /**
     * Return true when the given key exists in the cache under the given namespace.
     *
     * @param  string  $key
     * @param  string  $namespace
     * @return bool
     */
    public function has(string $key, string $namespace = 'default'): bool
    {
        try {
            return $this->repo()->has($this->key($namespace, $key));
        } catch (\Throwable $e) {
            throw new MemoryException('CacheMemory::has failed.', previous: $e);
        }
    }

    /**
     * Return the cache-key prefix applied to every phpClaw entry.
     *
     * @return string
     */
    public function prefix(): string
    {
        return $this->prefix;
    }

    /**
     * Resolve the backing cache repository.
     *
     * @return Repository
     */
    private function repo(): Repository
    {
        return $this->store ?? Cache::store();
    }

    /**
     * Build the fully-qualified cache key for a namespace and short key.
     *
     * @param  string  $namespace  The memory namespace.
     * @param  string  $key  The short key.
     * @return string
     */
    private function key(string $namespace, string $key): string
    {
        return $this->prefix.$namespace.':'.$key;
    }

    /**
     * Build the tracker index key for a namespace.
     *
     * @param  string  $namespace  The memory namespace.
     * @return string
     */
    private function trackerKey(string $namespace): string
    {
        return $this->prefix.'_t_'.sha1($namespace);
    }

    /**
     * Record a short key under the namespace tracker (advisory-locked when supported).
     *
     * @param  string  $namespace  The memory namespace.
     * @param  string  $shortKey  The short key to track.
     * @return void
     */
    private function trackerAdd(string $namespace, string $shortKey): void
    {
        $this->updateTracker($namespace, static function (array $known) use ($shortKey): ?array {
            return in_array($shortKey, $known, true) ? null : [...$known, $shortKey];
        });
    }

    /**
     * Remove a short key from the namespace tracker (advisory-locked when supported).
     *
     * @param  string  $namespace  The memory namespace.
     * @param  string  $shortKey  The short key to untrack.
     * @return void
     */
    private function trackerRemove(string $namespace, string $shortKey): void
    {
        $this->updateTracker($namespace, static function (array $known) use ($shortKey): ?array {
            $filtered = array_values(array_filter($known, static fn ($key) => $key !== $shortKey));

            return count($filtered) === count($known) ? null : $filtered;
        });
    }

    /**
     * Rewrite the namespace tracker under its lock when the store supports locks; the change returns the new key
     * list, null to leave it alone, and an empty list removes the tracker.
     *
     * @param  string  $namespace  The memory namespace.
     * @param  \Closure(array<int, mixed>): (array<int, mixed>|null)  $change  Builds the new key list from the current one.
     * @return void
     */
    private function updateTracker(string $namespace, \Closure $change): void
    {
        $repo = $this->repo();
        $trackerKey = $this->trackerKey($namespace);

        $update = static function () use ($repo, $trackerKey, $change): void {
            $known = $change((array) $repo->get($trackerKey, []));

            if ($known === null) {
                return;
            }

            if ($known === []) {
                $repo->forget($trackerKey);

                return;
            }

            $repo->forever($trackerKey, $known);
        };

        $store = $repo->getStore();

        if (! $store instanceof LockProvider) {
            $update();

            return;
        }

        $store->lock(self::TRACKER_LOCK_PREFIX.$namespace, self::TRACKER_LOCK_SECONDS)->block(self::TRACKER_LOCK_WAIT_SECONDS, $update);
    }
}

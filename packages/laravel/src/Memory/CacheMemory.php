<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Memory;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use PhpClaw\Exceptions\MemoryException;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use PhpClaw\Memory\Contracts\MemoryInterface;

/**
 * Laravel Cache-backed memory driver that wraps any configured cache store as a phpClaw memory backend.
 */
final class CacheMemory implements MemoryInterface
{
    private const DRIVER_NAME = 'cache';

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
        private readonly string $prefix = 'phpclaw:',
        private readonly int $defaultTtl = 86400,
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

            $this->fireRead($key, $namespace, hit: $hit);

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
            $fqn = $this->key($namespace, $key);

            if ($expiry > 0) {
                $this->repo()->put($fqn, $value, $expiry);
            } else {
                $this->repo()->forever($fqn, $value);
            }

            $this->trackerAdd($namespace, $key);

            HookRegistry::fire(LifecycleEvent::MemoryWrite->value, [
                'key' => $key,
                'namespace' => $namespace,
                'driver' => self::DRIVER_NAME,
                'ttl' => $ttl,
            ]);
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

            HookRegistry::fire(LifecycleEvent::MemoryForget->value, [
                'key' => $key,
                'namespace' => $namespace,
                'driver' => self::DRIVER_NAME,
            ]);
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
                $val = $repo->get($this->key($namespace, $shortKey));
                if ($val !== null) {
                    $result[$shortKey] = $val;
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
        $repo = $this->repo();
        $trackerKey = $this->trackerKey($namespace);
        $lockKey = 'phpclaw_tracker_lock:'.$namespace;

        $update = function () use ($repo, $trackerKey, $shortKey): void {
            $known = (array) $repo->get($trackerKey, []);
            if (! in_array($shortKey, $known, true)) {
                $known[] = $shortKey;
                $repo->forever($trackerKey, $known);
            }
        };

        $store = $repo->getStore();
        if ($store instanceof LockProvider) {
            $store->lock($lockKey, 5)->block(3, $update);
        } else {
            $update();
        }
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
        $repo = $this->repo();
        $trackerKey = $this->trackerKey($namespace);
        $lockKey = 'phpclaw_tracker_lock:'.$namespace;

        $update = function () use ($repo, $trackerKey, $shortKey): void {
            $known = (array) $repo->get($trackerKey, []);
            $filtered = array_values(array_filter($known, fn ($k) => $k !== $shortKey));

            if ($filtered === []) {
                $repo->forget($trackerKey);
            } elseif (count($filtered) !== count($known)) {
                $repo->forever($trackerKey, $filtered);
            }
        };

        $store = $repo->getStore();
        if ($store instanceof LockProvider) {
            $store->lock($lockKey, 5)->block(3, $update);
        } else {
            $update();
        }
    }

    /**
     * Fire the memory.read hook with metadata only (never the stored value).
     *
     * @param  string  $key  The memory key.
     * @param  string  $namespace  The memory namespace.
     * @param  bool  $hit  Whether the lookup found a value.
     * @return void
     */
    private function fireRead(string $key, string $namespace, bool $hit): void
    {
        HookRegistry::fire(LifecycleEvent::MemoryRead->value, [
            'key' => $key,
            'namespace' => $namespace,
            'driver' => self::DRIVER_NAME,
            'hit' => $hit,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Memory;

use PhpClaw\Exceptions\MemoryException;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use PhpClaw\Memory\Contracts\MemoryInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Symfony Cache-backed memory driver for phpClaw.
 */
final class CacheMemory implements MemoryInterface
{
    private const DRIVER_NAME = 'cache';

    /**
     * Constructs the cache memory driver with the given cache backend, key prefix, and default TTL.
     *
     * @param  CacheInterface  $cache  Symfony cache backend (array, Redis, etc.).
     * @param  string  $prefix  Namespace prefix for all cache keys.
     * @param  int  $defaultTtl  Default time-to-live in seconds.
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly string $prefix = 'phpclaw.',
        private readonly int $defaultTtl = 86400,
    ) {}

    /**
     * Retrieves a cached value by key and namespace.
     *
     * @param  string  $key
     * @param  string  $namespace
     * @return mixed
     */
    public function get(string $key, string $namespace = 'default'): mixed
    {
        try {
            $fqn = $this->key($namespace, $key);
            $value = $this->cache->get($fqn, fn () => null);
            $hit = $value !== null;

            HookRegistry::fire(LifecycleEvent::MemoryRead->value, [
                'key' => $key, 'namespace' => $namespace,
                'driver' => self::DRIVER_NAME, 'hit' => $hit,
            ]);

            return $value;
        } catch (\Throwable $e) {
            throw new MemoryException('CacheMemory::get failed.', previous: $e);
        }
    }

    /**
     * Stores a value in the cache under the given key and namespace.
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
            $fqn = $this->key($namespace, $key);

            $this->store($fqn, $value, $ttl);

            $this->trackerAdd($namespace, $key);

            HookRegistry::fire(LifecycleEvent::MemoryWrite->value, [
                'key' => $key, 'namespace' => $namespace,
                'driver' => self::DRIVER_NAME, 'ttl' => $ttl,
            ]);
        } catch (\Throwable $e) {
            throw new MemoryException('CacheMemory::set failed.', previous: $e);
        }
    }

    /**
     * Deletes a cached value by key and namespace.
     *
     * @param  string  $key
     * @param  string  $namespace
     * @return void
     */
    public function forget(string $key, string $namespace = 'default'): void
    {
        try {
            $this->cache->delete($this->key($namespace, $key));
            $this->trackerRemove($namespace, $key);

            HookRegistry::fire(LifecycleEvent::MemoryForget->value, [
                'key' => $key, 'namespace' => $namespace,
                'driver' => self::DRIVER_NAME,
            ]);
        } catch (\Throwable $e) {
            throw new MemoryException('CacheMemory::forget failed.', previous: $e);
        }
    }

    /**
     * Deletes all keys tracked under the given namespace.
     *
     * @param  string  $namespace
     * @return void
     */
    public function flush(string $namespace = 'default'): void
    {
        try {
            $trackerKey = $this->trackerKey($namespace);
            $known = $this->trackedKeys($namespace);

            foreach ($known as $shortKey) {
                $this->cache->delete($this->key($namespace, (string) $shortKey));
            }

            $this->cache->delete($trackerKey);
        } catch (\Throwable $e) {
            throw new MemoryException('CacheMemory::flush failed.', previous: $e);
        }
    }

    /**
     * Returns all non-null cached values in the given namespace.
     *
     * @param  string  $namespace
     * @return array<string, mixed>
     */
    public function all(string $namespace = 'default'): array
    {
        try {
            $known = $this->trackedKeys($namespace);

            $result = [];
            foreach ($known as $shortKey) {
                $shortKey = (string) $shortKey;
                $val = $this->cache->get($this->key($namespace, $shortKey), fn () => null);
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
     * Returns true if a non-null cached value exists for the key.
     *
     * @param  string  $key
     * @param  string  $namespace
     * @return bool
     */
    public function has(string $key, string $namespace = 'default'): bool
    {
        try {
            return $this->cache->get($this->key($namespace, $key), fn () => null) !== null;
        } catch (\Throwable $e) {
            throw new MemoryException('CacheMemory::has failed.', previous: $e);
        }
    }

    /**
     * Builds the fully qualified cache key for a namespace+key pair.
     *
     * @param  string  $namespace
     * @param  string  $key
     * @return string
     */
    private function key(string $namespace, string $key): string
    {
        return $this->prefix.$namespace.'.'.$key;
    }

    /**
     * Builds the cache key for the namespace tracker list.
     *
     * @param  string  $namespace
     * @return string
     */
    private function trackerKey(string $namespace): string
    {
        return $this->prefix.'__tracker__.'.$namespace;
    }

    /**
     * Reads the tracked short-key list for a namespace as a plain list.
     *
     * @param  string  $namespace
     * @return array<int, mixed>
     */
    private function trackedKeys(string $namespace): array
    {
        $known = $this->cache->get($this->trackerKey($namespace), fn (): array => []);

        return is_array($known) ? array_values($known) : [];
    }

    /**
     * Adds a short key to the namespace tracker list.
     *
     * @param  string  $namespace
     * @param  string  $shortKey
     * @return void
     */
    private function trackerAdd(string $namespace, string $shortKey): void
    {
        $trackerKey = $this->trackerKey($namespace);
        $known = $this->trackedKeys($namespace);

        if (! in_array($shortKey, $known, true)) {
            $known[] = $shortKey;
            $this->store($trackerKey, $known);
        }
    }

    /**
     * Removes a short key from the namespace tracker list.
     *
     * @param  string  $namespace
     * @param  string  $shortKey
     * @return void
     */
    private function trackerRemove(string $namespace, string $shortKey): void
    {
        $trackerKey = $this->trackerKey($namespace);
        $known = $this->trackedKeys($namespace);
        $filtered = array_values(array_filter($known, fn ($k) => (string) $k !== $shortKey));

        if ($filtered !== []) {
            $this->store($trackerKey, $filtered);
        } else {
            $this->cache->delete($trackerKey);
        }
    }

    /**
     * Writes a value into the cache under the given key, replacing any prior entry.
     *
     * @param  string  $cacheKey
     * @param  mixed  $value
     * @param  int|null  $ttl  Expiry in seconds; falls back to the configured default TTL when null.
     * @return void
     */
    private function store(string $cacheKey, mixed $value, ?int $ttl = null): void
    {
        $expiry = $ttl ?? $this->defaultTtl;

        $this->cache->delete($cacheKey);
        $this->cache->get($cacheKey, static function (ItemInterface $item) use ($value, $expiry): mixed {
            $item->expiresAfter($expiry);

            return $value;
        });
    }
}

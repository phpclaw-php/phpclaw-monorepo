<?php

declare(strict_types=1);

namespace PhpClaw\Memory;

use PhpClaw\AutoDiscovery\Attributes\Memory;
use PhpClaw\Hooks\HookDispatcher;
use PhpClaw\Memory\Contracts\MemoryInterface;

/**
 * In-process memory driver backed by a PHP array, no persistence across requests.
 */
#[Memory(driver: 'array', label: 'In-Memory Array', since: '1.0.0')]
final class ArrayMemory implements MemoryInterface
{
    private const DRIVER_NAME = 'array';

    private array $store = [];

    private array $expiry = [];

    /**
     * Retrieve a value by key. Returns null when the key does not exist or has expired.
     *
     * @param  string  $key  Memory key.
     * @param  string  $namespace  Memory namespace.
     * @return mixed
     */
    public function get(string $key, string $namespace = 'default'): mixed
    {
        NamespaceValidator::validate($namespace);
        if ($this->isExpired($key, $namespace)) {
            $this->evict($key, $namespace);
            $this->fireRead($key, $namespace, hit: false);

            return null;
        }

        $hit = isset($this->store[$namespace][$key]);
        $value = $this->store[$namespace][$key] ?? null;
        $this->fireRead($key, $namespace, hit: $hit);

        return $value;
    }

    /**
     * Store a value under the given key with an optional TTL.
     *
     * @param  string  $key  Memory key.
     * @param  mixed  $value  Value to store.
     * @param  string  $namespace  Memory namespace.
     * @param  int|null  $ttl  Time-to-live in seconds (null = no expiry).
     * @return void
     */
    public function set(string $key, mixed $value, string $namespace = 'default', ?int $ttl = null): void
    {
        NamespaceValidator::validate($namespace);
        $this->store[$namespace][$key] = $value;
        $this->expiry[$namespace][$key] = $ttl !== null
            ? time() + $ttl
            : null;

        HookDispatcher::memoryWrite($key, $namespace, self::DRIVER_NAME, $ttl);
    }

    /**
     * Delete the key from the namespace store.
     *
     * @param  string  $key  Memory key.
     * @param  string  $namespace  Memory namespace.
     * @return void
     */
    public function forget(string $key, string $namespace = 'default'): void
    {
        NamespaceValidator::validate($namespace);
        unset($this->store[$namespace][$key], $this->expiry[$namespace][$key]);

        HookDispatcher::memoryForget($key, $namespace, self::DRIVER_NAME);
    }

    /**
     * Remove all keys from the given namespace.
     *
     * @param  string  $namespace  Memory namespace.
     * @return void
     */
    public function flush(string $namespace = 'default'): void
    {
        NamespaceValidator::validate($namespace);
        unset($this->store[$namespace], $this->expiry[$namespace]);
    }

    /**
     * Return all non-expired entries in the namespace as key → value pairs.
     *
     * @param  string  $namespace  Namespace to scope the operation.
     * @return array<string, mixed>
     */
    public function all(string $namespace = 'default'): array
    {
        NamespaceValidator::validate($namespace);
        $entries = $this->store[$namespace] ?? [];
        $result = [];

        foreach ($entries as $key => $value) {
            if ($this->isExpired($key, $namespace)) {
                $this->evict($key, $namespace);

                continue;
            }
            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * Return true when the key exists and has not expired.
     *
     * @param  string  $key  Memory key.
     * @param  string  $namespace  Memory namespace.
     * @return bool
     */
    public function has(string $key, string $namespace = 'default'): bool
    {
        NamespaceValidator::validate($namespace);
        if (! isset($this->store[$namespace][$key])) {
            return false;
        }

        if ($this->isExpired($key, $namespace)) {
            $this->evict($key, $namespace);

            return false;
        }

        return true;
    }

    /**
     * Return true when the key has a non-null expiry timestamp that is in the past.
     *
     * @param  string  $key  Memory key.
     * @param  string  $namespace  Memory namespace.
     * @return bool
     */
    private function isExpired(string $key, string $namespace): bool
    {
        $expiresAt = $this->expiry[$namespace][$key] ?? null;

        return $expiresAt !== null && time() > $expiresAt;
    }

    /**
     * Remove an expired key from both the value store and the expiry index.
     *
     * @param  string  $key  Memory key.
     * @param  string  $namespace  Memory namespace.
     * @return void
     */
    private function evict(string $key, string $namespace): void
    {
        unset($this->store[$namespace][$key], $this->expiry[$namespace][$key]);
    }

    /**
     * Dispatch the memory.read hook: exposes only key, namespace, and driver, never the stored value.
     *
     * @param  string  $key  Memory key.
     * @param  string  $namespace  Memory namespace.
     * @param  bool  $hit  Whether the read was a cache hit.
     * @return void
     */
    private function fireRead(string $key, string $namespace, bool $hit): void
    {
        HookDispatcher::memoryRead($key, $namespace, self::DRIVER_NAME, $hit);
    }
}

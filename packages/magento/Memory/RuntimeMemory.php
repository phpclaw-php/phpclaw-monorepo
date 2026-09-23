<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Memory;

use PhpClaw\Magento\Factory\PhpClawFactoryInterface;
use PhpClaw\Memory\Contracts\MemoryInterface;

/**
 * Memory adapter that resolves the active backend at every call.
 */
// non-final: Magento interceptor required
class RuntimeMemory implements MemoryInterface
{
    /**
     * Bind the factory used to resolve the active memory backend on every call.
     *
     * @param  PhpClawFactoryInterface  $phpClawFactory  Factory used to resolve the active memory backend on every call.
     * @return void
     */
    public function __construct(
        private readonly PhpClawFactoryInterface $phpClawFactory,
    ) {}

    /**
     * Retrieve a value from the currently-configured memory backend.
     *
     * @param  string  $key  Entry identifier.
     * @param  string  $namespace  Memory namespace forwarded to the resolved driver.
     * @return mixed Stored value, or null when the key does not exist.
     */
    public function get(string $key, string $namespace = 'default'): mixed
    {
        return $this->phpClawFactory->resolveMemory()->get($key, $namespace);
    }

    /**
     * Store a value in the currently-configured memory backend.
     *
     * @param  string  $key  Entry identifier.
     * @param  mixed  $value  Value to persist; for 'conversations' this is the Conversation::toArray() shape.
     * @param  string  $namespace  Memory namespace forwarded to the resolved driver.
     * @param  int|null  $ttl  Optional TTL in seconds; support depends on the resolved driver.
     * @return void
     */
    public function set(string $key, mixed $value, string $namespace = 'default', ?int $ttl = null): void
    {
        $this->phpClawFactory->resolveMemory()->set($key, $value, $namespace, $ttl);
    }

    /**
     * Delete a single entry from the currently-configured memory backend.
     *
     * @param  string  $key  Entry identifier to remove.
     * @param  string  $namespace  Memory namespace forwarded to the resolved driver.
     * @return void
     */
    public function forget(string $key, string $namespace = 'default'): void
    {
        $this->phpClawFactory->resolveMemory()->forget($key, $namespace);
    }

    /**
     * Delete all entries in a namespace from the currently-configured memory backend.
     *
     * @param  string  $namespace  Memory namespace to flush, forwarded to the resolved driver.
     * @return void
     */
    public function flush(string $namespace = 'default'): void
    {
        $this->phpClawFactory->resolveMemory()->flush($namespace);
    }

    /**
     * Return all entries in a namespace from the currently-configured memory backend.
     *
     * @param  string  $namespace  Memory namespace forwarded to the resolved driver.
     * @return array<string, mixed> Map of key → stored value.
     */
    public function all(string $namespace = 'default'): array
    {
        return $this->phpClawFactory->resolveMemory()->all($namespace);
    }

    /**
     * Check whether an entry exists in the currently-configured memory backend.
     *
     * @param  string  $key  Entry identifier to check.
     * @param  string  $namespace  Memory namespace forwarded to the resolved driver.
     * @return bool True when the key exists, false otherwise.
     */
    public function has(string $key, string $namespace = 'default'): bool
    {
        return $this->phpClawFactory->resolveMemory()->has($key, $namespace);
    }
}

<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Memory;

use PhpClaw\Memory\Contracts\MemoryInterface;

/**
 * Namespace-routing memory driver that dispatches conversations to PsDbConversationMemory and everything else to PsDbMemory.
 */
final class PsRouterMemory implements MemoryInterface
{
    /**
     * Create a new PsRouterMemory instance.
     *
     * @param  PsDbConversationMemory  $conversations
     * @param  PsDbMemory  $store
     * @return void
     */
    public function __construct(
        private readonly PsDbConversationMemory $conversations,
        private readonly PsDbMemory $store,
    ) {}

    /**
     * Retrieve a stored value by key from the appropriate driver.
     *
     * @param  string  $key  Memory key.
     * @param  string  $namespace  Namespace to scope the lookup.
     * @return mixed
     */
    public function get(string $key, string $namespace = 'default'): mixed
    {
        return $this->driver($namespace)->get($key, $namespace);
    }

    /**
     * Store a value under the given key in the appropriate driver.
     *
     * @param  string  $key  Memory key.
     * @param  mixed  $value  Value to store.
     * @param  string  $namespace  Namespace to scope the entry.
     * @param  int|null  $ttl  Optional TTL in seconds.
     * @return void
     */
    public function set(string $key, mixed $value, string $namespace = 'default', ?int $ttl = null): void
    {
        $this->driver($namespace)->set($key, $value, $namespace, $ttl);
    }

    /**
     * Delete a single key from the appropriate driver.
     *
     * @param  string  $key  Memory key to remove.
     * @param  string  $namespace  Namespace to scope the deletion.
     * @return void
     */
    public function forget(string $key, string $namespace = 'default'): void
    {
        $this->driver($namespace)->forget($key, $namespace);
    }

    /**
     * Delete a namespace via the appropriate driver, which does nothing for conversations
     * unless the caller holds the manage-all grant.
     *
     * @param  string  $namespace  Namespace to flush.
     * @return void
     */
    public function flush(string $namespace = 'default'): void
    {
        $this->driver($namespace)->flush($namespace);
    }

    /**
     * Return a namespace's entries via the appropriate driver, which scopes conversations to
     * the acting employee unless they hold the manage-all grant.
     *
     * @param  string  $namespace  Namespace to scan.
     * @return array<string, mixed>
     */
    public function all(string $namespace = 'default'): array
    {
        return $this->driver($namespace)->all($namespace);
    }

    /**
     * Check whether a key exists in the given namespace.
     *
     * @param  string  $key  Memory key to check.
     * @param  string  $namespace  Namespace to scope the check.
     * @return bool
     */
    public function has(string $key, string $namespace = 'default'): bool
    {
        return $this->driver($namespace)->has($key, $namespace);
    }

    /**
     * Resolve which underlying driver serves the given namespace.
     *
     * @param  string  $namespace
     * @return MemoryInterface
     */
    private function driver(string $namespace): MemoryInterface
    {
        return $namespace === 'conversations' ? $this->conversations : $this->store;
    }
}

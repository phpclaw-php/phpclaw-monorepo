<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Memory;

use PhpClaw\Memory\Contracts\MemoryInterface;

/**
 * Routes memory calls by namespace to the appropriate driver.
 */
final class DrupalDbRouterMemory implements MemoryInterface
{
    /**
     * Construct the router with its per-namespace memory drivers.
     *
     * @param  DrupalDbConversationMemory  $conversationDriver  Driver used for the 'conversations' namespace.
     * @param  DrupalDbMemory  $kvDriver  Driver used for all other namespaces.
     * @return void
     */
    public function __construct(
        private readonly DrupalDbConversationMemory $conversationDriver,
        private readonly DrupalDbMemory $kvDriver,
    ) {}

    /**
     * Retrieve a value from memory by key and namespace.
     *
     * @param  string  $key  Memory key to retrieve.
     * @param  string  $namespace  Namespace to route the lookup to; defaults to 'default'.
     * @return mixed The stored value, or null if not found.
     */
    public function get(string $key, string $namespace = 'default'): mixed
    {
        return $this->resolve($namespace)->get($key, $namespace);
    }

    /**
     * Store a value in memory with optional TTL.
     *
     * @param  string  $key  Memory key to write.
     * @param  mixed  $value  Value to store under the key.
     * @param  string  $namespace  Namespace to route the write to; defaults to 'default'.
     * @param  int|null  $ttl  Time-to-live in seconds; passed through to the underlying driver.
     * @return void
     */
    public function set(string $key, mixed $value, string $namespace = 'default', ?int $ttl = null): void
    {
        $this->resolve($namespace)->set($key, $value, $namespace, $ttl);
    }

    /**
     * Remove a key from memory.
     *
     * @param  string  $key  Memory key to delete.
     * @param  string  $namespace  Namespace the key belongs to; defaults to 'default'.
     * @return void
     */
    public function forget(string $key, string $namespace = 'default'): void
    {
        $this->resolve($namespace)->forget($key, $namespace);
    }

    /**
     * Flush a namespace via the routed driver, which scopes conversations to the acting user
     * unless they hold the manage-all permission.
     *
     * @param  string  $namespace  Namespace to clear; defaults to 'default'.
     * @return void
     */
    public function flush(string $namespace = 'default'): void
    {
        $this->resolve($namespace)->flush($namespace);
    }

    /**
     * Get a namespace's entries via the routed driver, which scopes conversations to the
     * acting user unless they hold the manage-all permission.
     *
     * @param  string  $namespace  Namespace to retrieve all entries from; defaults to 'default'.
     * @return array<string, mixed>
     */
    public function all(string $namespace = 'default'): array
    {
        return $this->resolve($namespace)->all($namespace);
    }

    /**
     * Check whether a key exists in memory.
     *
     * @param  string  $key  Memory key to check.
     * @param  string  $namespace  Namespace to search within; defaults to 'default'.
     * @return bool True if the key exists, false otherwise.
     */
    public function has(string $key, string $namespace = 'default'): bool
    {
        return $this->resolve($namespace)->has($key, $namespace);
    }

    /**
     * Resolve the appropriate memory driver based on namespace.
     *
     * @param  string  $namespace  Namespace used to select the driver: 'conversations' maps to DrupalDbConversationMemory, all others to DrupalDbMemory.
     * @return MemoryInterface The resolved driver.
     */
    private function resolve(string $namespace): MemoryInterface
    {
        return $namespace === 'conversations'
            ? $this->conversationDriver
            : $this->kvDriver;
    }
}

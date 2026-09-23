<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Memory;

use PhpClaw\Memory\Contracts\MemoryInterface;

/**
 * Routes memory calls by namespace to the appropriate WordPress driver.
 */
final class WpRouterMemory implements MemoryInterface
{
    private const CONVERSATION_NAMESPACE = 'conversations';

    /**
     * Route memory operations to the conversation or generic driver by namespace.
     *
     * @param  MemoryInterface  $conversations  Driver for conversation history (namespace = 'conversations').
     * @param  MemoryInterface  $generic  Driver for all other namespaces.
     */
    public function __construct(
        private readonly MemoryInterface $conversations,
        private readonly MemoryInterface $generic,
    ) {}

    /**
     * Retrieve a stored value by key and namespace.
     *
     * @param  string  $key  The memory key.
     * @param  string  $namespace  Namespace to scope the lookup.
     * @return mixed The stored value, or null if not found.
     */
    public function get(string $key, string $namespace = 'default'): mixed
    {
        return $this->pick($namespace)->get($key, $namespace);
    }

    /**
     * Store a value in the appropriate driver for the given namespace.
     *
     * @param  string  $key  The memory key.
     * @param  mixed  $value  Value to store.
     * @param  string  $namespace  Namespace to scope the entry.
     * @param  int|null  $ttl  TTL in seconds. Null = no expiry.
     * @return void
     */
    public function set(string $key, mixed $value, string $namespace = 'default', ?int $ttl = null): void
    {
        $this->pick($namespace)->set($key, $value, $namespace, $ttl);
    }

    /**
     * Delete a single key from the given namespace.
     *
     * @param  string  $key  The memory key to remove.
     * @param  string  $namespace  Namespace to scope the deletion.
     * @return void
     */
    public function forget(string $key, string $namespace = 'default'): void
    {
        $this->pick($namespace)->forget($key, $namespace);
    }

    /**
     * Delete the given namespace via the namespace-appropriate driver, which refuses the
     * conversations namespace unless the caller holds phpclaw_manage_all_conversations.
     *
     * @param  string  $namespace  Namespace to flush.
     * @return void
     */
    public function flush(string $namespace = 'default'): void
    {
        $this->pick($namespace)->flush($namespace);
    }

    /**
     * Return the given namespace's entries via the namespace-appropriate driver, which scopes
     * conversations to the current user unless they hold phpclaw_manage_all_conversations.
     *
     * @param  string  $namespace  Namespace to scan.
     * @return array<string, mixed>
     */
    public function all(string $namespace = 'default'): array
    {
        return $this->pick($namespace)->all($namespace);
    }

    /**
     * Check whether a key exists in the given namespace.
     *
     * @param  string  $key  The memory key to check.
     * @param  string  $namespace  Namespace to scope the check.
     * @return bool
     */
    public function has(string $key, string $namespace = 'default'): bool
    {
        return $this->pick($namespace)->has($key, $namespace);
    }

    /**
     * Select the correct driver based on namespace.
     *
     * @param  string  $namespace  Namespace being accessed.
     * @return MemoryInterface
     */
    private function pick(string $namespace): MemoryInterface
    {
        return $namespace === self::CONVERSATION_NAMESPACE
            ? $this->conversations
            : $this->generic;
    }
}

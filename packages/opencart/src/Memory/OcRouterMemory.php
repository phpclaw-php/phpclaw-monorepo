<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Memory;

use PhpClaw\Memory\Contracts\MemoryInterface;

/**
 * Router memory driver for phpClaw in OpenCart 3/4.
 */
final class OcRouterMemory implements MemoryInterface
{
    /**
     * Bind the conversation and key-value drivers this router selects between.
     *
     * @param  OcDbConversationMemory  $conversations  Conversation history driver.
     * @param  OcDbMemory  $keyValue  General key-value driver.
     */
    public function __construct(
        private readonly OcDbConversationMemory $conversations,
        private readonly OcDbMemory $keyValue,
    ) {}

    /**
     * Retrieve a value. Returns null if not found or expired.
     *
     * @param  string  $key  Memory key.
     * @param  string  $namespace  Memory namespace.
     * @return mixed
     */
    public function get(string $key, string $namespace = 'default'): mixed
    {
        return $this->pick($namespace)->get($key, $namespace);
    }

    /**
     * Store a value, optionally with a TTL in seconds.
     *
     * @param  string  $key  Memory key.
     * @param  mixed  $value  Value to store.
     * @param  string  $namespace  Memory namespace.
     * @param  int|null  $ttl  Seconds until expiry. Null = never expires.
     * @return void
     */
    public function set(string $key, mixed $value, string $namespace = 'default', ?int $ttl = null): void
    {
        $this->pick($namespace)->set($key, $value, $namespace, $ttl);
    }

    /**
     * Delete a single key.
     *
     * @param  string  $key  Memory key.
     * @param  string  $namespace  Memory namespace.
     * @return void
     */
    public function forget(string $key, string $namespace = 'default'): void
    {
        $this->pick($namespace)->forget($key, $namespace);
    }

    /**
     * Delete a namespace via the routed driver, which requires the manage-all grant for the
     * conversations namespace.
     *
     * @param  string  $namespace  Memory namespace.
     * @return void
     */
    public function flush(string $namespace = 'default'): void
    {
        $this->pick($namespace)->flush($namespace);
    }

    /**
     * Return a namespace's entries via the routed driver, which scopes conversations to the
     * acting user unless they hold the manage-all grant.
     *
     * @param  string  $namespace  Memory namespace.
     * @return array<string, mixed>
     */
    public function all(string $namespace = 'default'): array
    {
        return $this->pick($namespace)->all($namespace);
    }

    /**
     * Whether a non-expired key exists.
     *
     * @param  string  $key  Memory key.
     * @param  string  $namespace  Memory namespace.
     * @return bool
     */
    public function has(string $key, string $namespace = 'default'): bool
    {
        return $this->pick($namespace)->has($key, $namespace);
    }

    /**
     * Choose the backing memory driver for a given namespace.
     *
     * @param  string  $namespace  Memory namespace requested by the caller.
     * @return MemoryInterface Concrete driver that owns this namespace.
     */
    private function pick(string $namespace): MemoryInterface
    {
        return $namespace === 'conversations'
            ? $this->conversations
            : $this->keyValue;
    }
}

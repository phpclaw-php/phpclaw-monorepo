<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Memory;

use PhpClaw\Memory\Contracts\MemoryInterface;

/**
 * Routes MemoryInterface calls by namespace: 'conversations' to DatabaseConversationMemory, everything else to DatabaseMemory.
 */
final class DatabaseRouterMemory implements MemoryInterface
{
    private const CONVERSATION_NAMESPACE = 'conversations';

    /**
     * Bind the conversation and generic drivers this router selects between.
     *
     * @param  DatabaseConversationMemory  $conversations  Driver for the 'conversations' namespace.
     * @param  DatabaseMemory  $generic  Driver for all other namespaces.
     * @return void
     */
    public function __construct(
        private readonly DatabaseConversationMemory $conversations,
        private readonly DatabaseMemory $generic,
    ) {}

    /**
     * Retrieve a value, routing to the correct driver by namespace.
     *
     * @param  string  $key
     * @param  string  $namespace
     * @return mixed
     */
    public function get(string $key, string $namespace = 'default'): mixed
    {
        return $this->pick($namespace)->get($key, $namespace);
    }

    /**
     * Persist a value, routing to the correct driver by namespace.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @param  string  $namespace
     * @param  ?int  $ttl
     * @return void
     */
    public function set(string $key, mixed $value, string $namespace = 'default', ?int $ttl = null): void
    {
        $this->pick($namespace)->set($key, $value, $namespace, $ttl);
    }

    /**
     * Remove a key, routing to the correct driver by namespace.
     *
     * @param  string  $key
     * @param  string  $namespace
     * @return void
     */
    public function forget(string $key, string $namespace = 'default'): void
    {
        $this->pick($namespace)->forget($key, $namespace);
    }

    /**
     * Flush a namespace via the routed driver, which scopes conversations to the acting user
     * unless they may reach every conversation.
     *
     * @param  string  $namespace
     * @return void
     */
    public function flush(string $namespace = 'default'): void
    {
        $this->pick($namespace)->flush($namespace);
    }

    /**
     * Return a namespace's entries via the routed driver, which scopes conversations to the
     * acting user unless they may reach every conversation.
     *
     * @param  string  $namespace
     * @return array<string, mixed>
     */
    public function all(string $namespace = 'default'): array
    {
        return $this->pick($namespace)->all($namespace);
    }

    /**
     * Return true when an entry exists for the given key, routing to the correct driver.
     *
     * @param  string  $key
     * @param  string  $namespace
     * @return bool
     */
    public function has(string $key, string $namespace = 'default'): bool
    {
        return $this->pick($namespace)->has($key, $namespace);
    }

    /**
     * Select the backing driver for a namespace.
     *
     * @param  string  $namespace  The memory namespace.
     * @return MemoryInterface
     */
    private function pick(string $namespace): MemoryInterface
    {
        return $namespace === self::CONVERSATION_NAMESPACE
            ? $this->conversations
            : $this->generic;
    }
}

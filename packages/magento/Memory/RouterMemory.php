<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Memory;

use PhpClaw\Memory\Contracts\MemoryInterface;

/**
 * Namespace-routing memory driver for phpClaw on Magento.
 */
// non-final: Magento interceptor required
class RouterMemory implements MemoryInterface
{
    private const CONVERSATIONS_NAMESPACE = 'conversations';

    /**
     * Bind the conversation and generic drivers this router selects between.
     *
     * @param  MemoryInterface  $conversationMemory  Driver for the 'conversations' namespace (phpclaw_conversations + phpclaw_messages).
     * @param  MemoryInterface  $resourceMemory  Driver for all other namespaces (phpclaw_memory KV store).
     * @return void
     */
    public function __construct(
        private readonly MemoryInterface $conversationMemory,
        private readonly MemoryInterface $resourceMemory,
    ) {}

    /**
     * Retrieve a value from the appropriate backend.
     *
     * @param  string  $key  Entry identifier.
     * @param  string  $namespace  Memory namespace; 'conversations' routes to ConversationMemory.
     * @return mixed Stored value, or null when the key does not exist.
     */
    public function get(string $key, string $namespace = 'default'): mixed
    {
        return $this->driver($namespace)->get($key, $namespace);
    }

    /**
     * Store a value in the appropriate backend.
     *
     * @param  string  $key  Entry identifier.
     * @param  mixed  $value  Value to persist; for 'conversations' this is the Conversation::toArray() shape.
     * @param  string  $namespace  Memory namespace; 'conversations' routes to ConversationMemory.
     * @param  int|null  $ttl  Optional TTL in seconds; ignored by the DB driver.
     * @return void
     */
    public function set(string $key, mixed $value, string $namespace = 'default', ?int $ttl = null): void
    {
        $this->driver($namespace)->set($key, $value, $namespace, $ttl);
    }

    /**
     * Delete a single entry from the appropriate backend.
     *
     * @param  string  $key  Entry identifier to remove.
     * @param  string  $namespace  Memory namespace; 'conversations' routes to ConversationMemory.
     * @return void
     */
    public function forget(string $key, string $namespace = 'default'): void
    {
        $this->driver($namespace)->forget($key, $namespace);
    }

    /**
     * Delete a namespace via the appropriate backend, which scopes conversations to the
     * acting admin unless they hold the manage-all tier.
     *
     * @param  string  $namespace  Memory namespace to flush; 'conversations' routes to ConversationMemory.
     * @return void
     */
    public function flush(string $namespace = 'default'): void
    {
        $this->driver($namespace)->flush($namespace);
    }

    /**
     * Return a namespace's entries via the appropriate backend, which scopes conversations
     * to the acting admin unless they hold the manage-all tier.
     *
     * @param  string  $namespace  Memory namespace; 'conversations' routes to ConversationMemory.
     * @return array<string, mixed> Map of key → stored value.
     */
    public function all(string $namespace = 'default'): array
    {
        return $this->driver($namespace)->all($namespace);
    }

    /**
     * Check whether an entry exists in the appropriate backend.
     *
     * @param  string  $key  Entry identifier to check.
     * @param  string  $namespace  Memory namespace; 'conversations' routes to ConversationMemory.
     * @return bool True when the key exists, false otherwise.
     */
    public function has(string $key, string $namespace = 'default'): bool
    {
        return $this->driver($namespace)->has($key, $namespace);
    }

    /**
     * Select the backend driver based on namespace.
     *
     * @param  string  $namespace  Incoming namespace string.
     * @return MemoryInterface ConversationMemory for 'conversations', ResourceMemory for all others.
     */
    private function driver(string $namespace): MemoryInterface
    {
        return $namespace === self::CONVERSATIONS_NAMESPACE
            ? $this->conversationMemory
            : $this->resourceMemory;
    }
}

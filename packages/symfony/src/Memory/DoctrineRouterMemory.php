<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Memory;

use PhpClaw\Memory\Contracts\MemoryInterface;

/**
 * Routes MemoryInterface calls to DoctrineConversationMemory or DoctrineMemory by namespace.
 */
final class DoctrineRouterMemory implements MemoryInterface
{
    private const CONVERSATION_NAMESPACE = 'conversations';

    /**
     * Constructs the router with a conversations driver and a generic key-value driver.
     *
     * @param  MemoryInterface  $conversations  Driver for conversation namespace reads/writes.
     * @param  MemoryInterface  $generic  Driver for all other namespace reads/writes.
     */
    public function __construct(
        private readonly MemoryInterface $conversations,
        private readonly MemoryInterface $generic,
    ) {}

    /**
     * Retrieves a stored value from the appropriate driver.
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
     * Stores a value in the appropriate driver.
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
     * Deletes a key from the appropriate driver.
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
     * Flushes a namespace via the appropriate driver, which scopes conversations to the
     * acting user outside the manage-all tier.
     *
     * @param  string  $namespace
     * @return void
     */
    public function flush(string $namespace = 'default'): void
    {
        $this->pick($namespace)->flush($namespace);
    }

    /**
     * Returns a namespace's non-expired pairs via the appropriate driver, which scopes
     * conversations to the acting user outside the manage-all tier.
     *
     * @param  string  $namespace
     * @return array<string, mixed>
     */
    public function all(string $namespace = 'default'): array
    {
        return $this->pick($namespace)->all($namespace);
    }

    /**
     * Returns true if a key exists and has not expired.
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
     * Selects the driver for the given namespace.
     *
     * @param  string  $namespace
     * @return MemoryInterface
     */
    private function pick(string $namespace): MemoryInterface
    {
        return $namespace === self::CONVERSATION_NAMESPACE
            ? $this->conversations
            : $this->generic;
    }
}

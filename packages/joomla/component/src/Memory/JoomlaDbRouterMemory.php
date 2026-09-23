<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Component\Administrator\Memory;

use PhpClaw\AutoDiscovery\Attributes\Memory;
use PhpClaw\Memory\Contracts\MemoryInterface;

/**
 * Routes memory calls by namespace to the appropriate Joomla driver.
 */
#[Memory(driver: 'joomladb', label: 'Joomla Database', since: '0.1.0')]
final class JoomlaDbRouterMemory implements MemoryInterface
{
    private const DEFAULT_NAMESPACE = 'default';

    private const CONVERSATIONS_NAMESPACE = 'conversations';

    /**
     * Create a new JoomlaDbRouterMemory instance.
     *
     * @param  MemoryInterface  $conversations  Driver for conversation history.
     * @param  MemoryInterface  $generic  Driver for all other namespaces.
     */
    public function __construct(
        private readonly MemoryInterface $conversations,
        private readonly MemoryInterface $generic,
    ) {}

    /**
     * Retrieve a value from the namespace-appropriate driver.
     *
     * @param  string  $key
     * @param  string  $namespace
     * @return mixed
     */
    public function get(string $key, string $namespace = self::DEFAULT_NAMESPACE): mixed
    {
        return $this->pick($namespace)->get($key, $namespace);
    }

    /**
     * Store a value via the namespace-appropriate driver.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @param  string  $namespace
     * @param  int|null  $ttl  Time-to-live in seconds, or null for no expiry.
     * @return void
     */
    public function set(string $key, mixed $value, string $namespace = self::DEFAULT_NAMESPACE, ?int $ttl = null): void
    {
        $this->pick($namespace)->set($key, $value, $namespace, $ttl);
    }

    /**
     * Delete a single entry via the namespace-appropriate driver.
     *
     * @param  string  $key
     * @param  string  $namespace
     * @return void
     */
    public function forget(string $key, string $namespace = self::DEFAULT_NAMESPACE): void
    {
        $this->pick($namespace)->forget($key, $namespace);
    }

    /**
     * Wipe the given namespace via the namespace-appropriate driver, which scopes the
     * conversations namespace to the acting user unless they hold manage-all rights.
     *
     * @param  string  $namespace
     * @return void
     */
    public function flush(string $namespace = self::DEFAULT_NAMESPACE): void
    {
        $this->pick($namespace)->flush($namespace);
    }

    /**
     * Return the given namespace's entries via the namespace-appropriate driver, which scopes
     * the conversations namespace to the acting user unless they hold manage-all rights.
     *
     * @param  string  $namespace
     * @return array<string, mixed>
     */
    public function all(string $namespace = self::DEFAULT_NAMESPACE): array
    {
        return $this->pick($namespace)->all($namespace);
    }

    /**
     * Whether the namespace-appropriate driver holds the given key.
     *
     * @param  string  $key
     * @param  string  $namespace
     * @return bool
     */
    public function has(string $key, string $namespace = self::DEFAULT_NAMESPACE): bool
    {
        return $this->pick($namespace)->has($key, $namespace);
    }

    /**
     * Whether `$namespace` is routed to the dedicated conversation driver.
     *
     * @param  string  $namespace
     * @return bool
     */
    public function routesToConversations(string $namespace): bool
    {
        return $namespace === self::CONVERSATIONS_NAMESPACE;
    }

    /**
     * Select the correct driver based on namespace.
     *
     * @param  string  $namespace
     * @return MemoryInterface
     */
    private function pick(string $namespace): MemoryInterface
    {
        return $namespace === self::CONVERSATIONS_NAMESPACE
            ? $this->conversations
            : $this->generic;
    }
}

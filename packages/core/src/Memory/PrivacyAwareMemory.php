<?php

declare(strict_types=1);

namespace PhpClaw\Memory;

use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Memory\Contracts\SearchableMemoryInterface;

/**
 * Privacy-gated memory wrapper: when `storeMessages` is OFF, every `set()` call becomes a no-op so message content is never persisted.
 */
final class PrivacyAwareMemory implements SearchableMemoryInterface
{
    private const DEFAULT_NAMESPACE = 'default';

    /**
     * Create a new PrivacyAwareMemory instance.
     *
     * @param  MemoryInterface  $inner  Underlying driver to delegate every operation to (subject to gating).
     * @param  bool  $storeMessages  When false, every `set()` is a no-op.
     */
    public function __construct(
        private readonly MemoryInterface $inner,
        private readonly bool $storeMessages,
    ) {}

    /**
     * Whether writes are currently flowing through to the inner driver.
     *
     * @return bool True when message storage is enabled.
     */
    public function storeMessages(): bool
    {
        return $this->storeMessages;
    }

    /**
     * The wrapped driver.
     *
     * @return MemoryInterface The result.
     */
    public function inner(): MemoryInterface
    {
        return $this->inner;
    }

    /**
     * Read a value from the wrapped driver.
     *
     * @param  string  $key  Storage key.
     * @param  string  $namespace  Namespace to scope the read.
     * @return mixed Stored value, or null when absent.
     */
    public function get(string $key, string $namespace = self::DEFAULT_NAMESPACE): mixed
    {
        return $this->inner->get($key, $namespace);
    }

    /**
     * Persist a value to the wrapped driver, or no-op when message storage is disabled.
     *
     * @param  string  $key  Storage key.
     * @param  mixed  $value  Value to store.
     * @param  string  $namespace  Namespace to scope the write.
     * @param  int|null  $ttl  Time-to-live in seconds, or null for no expiry.
     * @return void
     */
    public function set(string $key, mixed $value, string $namespace = self::DEFAULT_NAMESPACE, ?int $ttl = null): void
    {
        if (! $this->storeMessages) {
            return;
        }

        $this->inner->set($key, $value, $namespace, $ttl);
    }

    /**
     * Delete a value from the wrapped driver.
     *
     * @param  string  $key  Storage key.
     * @param  string  $namespace  Namespace to scope the deletion.
     * @return void
     */
    public function forget(string $key, string $namespace = self::DEFAULT_NAMESPACE): void
    {
        $this->inner->forget($key, $namespace);
    }

    /**
     * Delete every value in the namespace from the wrapped driver.
     *
     * @param  string  $namespace  Namespace to clear.
     * @return void
     */
    public function flush(string $namespace = self::DEFAULT_NAMESPACE): void
    {
        $this->inner->flush($namespace);
    }

    /**
     * Return every stored value in the namespace from the wrapped driver.
     *
     * @param  string  $namespace  Namespace to read.
     * @return array<string, mixed> Key-value map of stored entries.
     */
    public function all(string $namespace = self::DEFAULT_NAMESPACE): array
    {
        return $this->inner->all($namespace);
    }

    /**
     * Search the wrapped driver: its own search() when it is searchable, otherwise its entries ranked by shared keywords.
     *
     * @param  string  $query  Free text to match against stored values.
     * @param  int  $limit  Maximum hits to return.
     * @param  string  $namespace  Memory namespace to search.
     * @return list<MemoryHit> Hits in descending score order, at most $limit.
     */
    public function search(string $query, int $limit = 5, string $namespace = self::DEFAULT_NAMESPACE): array
    {
        return TokenOverlapScorer::search($this->inner, $query, $limit, $namespace);
    }

    /**
     * Report whether the wrapped driver holds a value for the key.
     *
     * @param  string  $key  Storage key.
     * @param  string  $namespace  Namespace to check.
     * @return bool True when the key exists.
     */
    public function has(string $key, string $namespace = self::DEFAULT_NAMESPACE): bool
    {
        return $this->inner->has($key, $namespace);
    }
}

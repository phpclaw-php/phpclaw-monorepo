<?php

declare(strict_types=1);

namespace PhpClaw\Memory\Contracts;

/**
 * Contract every memory driver must implement. Frozen until v2.0.
 */
interface MemoryInterface
{
    /**
     * Retrieve a value. Returns null if not found or expired.
     *
     * @param  string  $key  Memory key.
     * @param  string  $namespace  Memory namespace.
     * @return mixed
     */
    public function get(string $key, string $namespace = 'default'): mixed;

    /**
     * Store a value, optionally with a TTL in seconds.
     *
     * @param  string  $key  Memory key.
     * @param  mixed  $value  Value to store.
     * @param  string  $namespace  Memory namespace.
     * @param  int|null  $ttl  Seconds until expiry. Null = never expires.
     * @return void
     */
    public function set(string $key, mixed $value, string $namespace = 'default', ?int $ttl = null): void;

    /**
     * Delete a single key.
     *
     * @param  string  $key  Memory key.
     * @param  string  $namespace  Memory namespace.
     * @return void
     */
    public function forget(string $key, string $namespace = 'default'): void;

    /**
     * Delete all keys in a namespace.
     *
     * @param  string  $namespace  Memory namespace.
     * @return void
     */
    public function flush(string $namespace = 'default'): void;

    /**
     * Return all non-expired key→value pairs in a namespace.
     *
     * @param  string  $namespace  Memory namespace.
     * @return array<string, mixed>
     */
    public function all(string $namespace = 'default'): array;

    /**
     * Whether a non-expired key exists.
     *
     * @param  string  $key  Memory key.
     * @param  string  $namespace  Memory namespace.
     * @return bool
     */
    public function has(string $key, string $namespace = 'default'): bool;
}

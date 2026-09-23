<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Memory;

use PhpClaw\Exceptions\MemoryException;
use PhpClaw\Hooks\HookDispatcher;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Support\Ulid;

/**
 * MemoryInterface driver backed by a custom {prefix}phpclaw_memory table.
 */
final class WpDbMemory implements MemoryInterface
{
    private const DRIVER_NAME = 'wp_db';

    private string $table;

    /**
     * Resolve the prefixed table name from the global wpdb instance.
     */
    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix.'phpclaw_memory';
    }

    /**
     * Retrieve a stored value by key and namespace.
     *
     * @param  string  $key  The memory key.
     * @param  string  $namespace  Namespace to scope the lookup.
     * @return mixed The stored value, or null if not found or expired.
     */
    public function get(string $key, string $namespace = 'default'): mixed
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT value, expires_at FROM {$this->table}
                 WHERE namespace = %s AND lookup_key = %s
                 LIMIT 1",
                $namespace,
                $key,
            ),
            'ARRAY_A',
        );

        if (! $row) {
            HookDispatcher::memoryRead($key, $namespace, self::DRIVER_NAME, false);

            return null;
        }

        if ($this->isExpired($row['expires_at'])) {
            $this->forget($key, $namespace);
            HookDispatcher::memoryRead($key, $namespace, self::DRIVER_NAME, false);

            return null;
        }

        $value = $this->safeUnserialize((string) $row['value']);

        HookDispatcher::memoryRead($key, $namespace, self::DRIVER_NAME, true);

        return $value;
    }

    /**
     * Store a value in the custom memory table using an upsert.
     *
     * @param  string  $key  The memory key.
     * @param  mixed  $value  Value to store (will be serialized).
     * @param  string  $namespace  Namespace to scope the entry.
     * @param  int|null  $ttl  TTL in seconds. Null = no expiry.
     * @return void
     */
    public function set(string $key, mixed $value, string $namespace = 'default', ?int $ttl = null): void
    {
        global $wpdb;

        try {
            $serialised = serialize($value);
        } catch (\Throwable $e) {
            error_log("phpClaw WpDbMemory serialize failed for key [{$key}]: {$e->getMessage()}");
            throw new MemoryException("Failed to serialize value for key [{$key}]", 0, $e);
        }

        $expiresAt = $ttl !== null
            ? gmdate('Y-m-d H:i:s', time() + $ttl)
            : null;

        $now = gmdate('Y-m-d H:i:s');

        if ($expiresAt !== null) {
            $sql = $wpdb->prepare(
                "INSERT INTO {$this->table}
                 (id, namespace, lookup_key, value, expires_at, created_at, updated_at)
                 VALUES (%s, %s, %s, %s, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE
                     value      = VALUES(value),
                     expires_at = VALUES(expires_at),
                     updated_at = VALUES(updated_at)",
                Ulid::generate(),
                $namespace,
                $key,
                $serialised,
                $expiresAt,
                $now,
                $now,
            );
        } else {
            $sql = $wpdb->prepare(
                "INSERT INTO {$this->table}
                 (id, namespace, lookup_key, value, expires_at, created_at, updated_at)
                 VALUES (%s, %s, %s, %s, NULL, %s, %s)
                 ON DUPLICATE KEY UPDATE
                     value      = VALUES(value),
                     expires_at = NULL,
                     updated_at = VALUES(updated_at)",
                Ulid::generate(),
                $namespace,
                $key,
                $serialised,
                $now,
                $now,
            );
        }

        $result = $wpdb->query($sql);

        if ($result === false) {
            error_log("phpClaw WpDbMemory write failed for key [{$key}]: {$wpdb->last_error}");
            throw new MemoryException("DB write failed for key [{$key}]");
        }

        HookDispatcher::memoryWrite($key, $namespace, self::DRIVER_NAME, $ttl);
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
        global $wpdb;

        $wpdb->delete(
            $this->table,
            ['namespace' => $namespace, 'lookup_key' => $key],
            ['%s', '%s'],
        );

        HookDispatcher::memoryForget($key, $namespace, self::DRIVER_NAME);
    }

    /**
     * Delete all keys under the given namespace.
     *
     * @param  string  $namespace  Namespace to flush.
     * @return void
     */
    public function flush(string $namespace = 'default'): void
    {
        global $wpdb;

        $wpdb->delete(
            $this->table,
            ['namespace' => $namespace],
            ['%s'],
        );
    }

    /**
     * Return all non-expired key-value pairs stored under the given namespace.
     *
     * @param  string  $namespace  Namespace to scan.
     * @return array<string, mixed>
     */
    public function all(string $namespace = 'default'): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT lookup_key, value, expires_at FROM {$this->table}
                 WHERE namespace = %s",
                $namespace,
            ),
            'ARRAY_A',
        ) ?? [];

        $out = [];

        foreach ($rows as $row) {
            if ($this->isExpired($row['expires_at'])) {
                continue;
            }
            $out[(string) $row['lookup_key']] = $this->safeUnserialize((string) $row['value']);
        }

        return $out;
    }

    /**
     * Check whether a key exists and has not expired in the given namespace.
     *
     * @param  string  $key  The memory key to check.
     * @param  string  $namespace  Namespace to scope the check.
     * @return bool
     */
    public function has(string $key, string $namespace = 'default'): bool
    {
        return $this->get($key, $namespace) !== null;
    }

    /**
     * Best-effort unserialize that suppresses the PHP warning `unserialize()` raises on malformed input.
     *
     * @param  string  $raw
     * @return mixed
     */
    private function safeUnserialize(string $raw): mixed
    {
        if ($raw === 'b:0;') {
            return false;
        }

        $result = unserialize($raw, ['allowed_classes' => false]);

        return $result === false ? $raw : $result;
    }

    /**
     * Decide whether a row's `expires_at` column means the row has expired.
     *
     * @param  mixed  $expiresAt
     * @return bool
     */
    private function isExpired(mixed $expiresAt): bool
    {
        if ($expiresAt === null || $expiresAt === '' || $expiresAt === '0000-00-00 00:00:00') {
            return false;
        }

        $timestamp = strtotime((string) $expiresAt);

        if ($timestamp === false || $timestamp <= 0) {
            return false;
        }

        return $timestamp <= time();
    }
}

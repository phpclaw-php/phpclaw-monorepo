<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Memory;

use PhpClaw\Hooks\HookDispatcher;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\PrestaShop\Contracts\PsDbInterface;
use PhpClaw\Support\Ulid;

/**
 * Native-DB-backed key-value memory driver for phpClaw in PrestaShop.
 */
final class PsDbMemory implements MemoryInterface
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    private const DRIVER_NAME = 'ps_db';

    private readonly string $table;

    /**
     * Create a new PsDbMemory instance.
     *
     * @param  PsDbInterface  $db
     * @param  string  $tablePrefix
     * @return void
     */
    public function __construct(
        private readonly PsDbInterface $db,
        string $tablePrefix = '',
    ) {
        $this->table = $tablePrefix.'phpclaw_memory';
    }

    /**
     * Retrieve a value. Returns null if not found or expired.
     *
     * @param  string  $key  The memory key.
     * @param  string  $namespace  Namespace to scope the lookup.
     * @return mixed The stored value, or null when absent or expired.
     */
    public function get(string $key, string $namespace = 'default'): mixed
    {
        $now = date(self::DATETIME_FORMAT);
        $row = $this->db->query(
            "SELECT value FROM {$this->table}
             WHERE namespace = ? AND lookup_key = ?
               AND (expires_at IS NULL OR expires_at > ?)",
            [$namespace, $key, $now],
        )->row;

        $hit = $row !== [];
        HookDispatcher::memoryRead($key, $namespace, self::DRIVER_NAME, $hit);

        return $hit ? $this->unserialize((string) $row['value']) : null;
    }

    /**
     * Store a value. Upserts on (namespace, lookup_key). Optional TTL in seconds.
     *
     * @param  string  $key  The memory key.
     * @param  mixed  $value  Value to store (serialized when non-string).
     * @param  string  $namespace  Namespace to scope the entry.
     * @param  int|null  $ttl  TTL in seconds. Null = no expiry.
     * @return void
     */
    public function set(string $key, mixed $value, string $namespace = 'default', ?int $ttl = null): void
    {
        $now = date(self::DATETIME_FORMAT);
        $expiresAt = $ttl !== null ? date(self::DATETIME_FORMAT, time() + $ttl) : null;
        $stored = $this->serialize($value);

        $this->db->query(
            "INSERT INTO {$this->table} (id, namespace, lookup_key, value, expires_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), expires_at = VALUES(expires_at), updated_at = VALUES(updated_at)",
            [Ulid::generate(), $namespace, $key, $stored, $expiresAt, $now, $now],
        );

        HookDispatcher::memoryWrite($key, $namespace, self::DRIVER_NAME, $ttl);
    }

    /**
     * Delete a single key from the namespace.
     *
     * @param  string  $key  The memory key.
     * @param  string  $namespace  Namespace to scope the deletion.
     * @return void
     */
    public function forget(string $key, string $namespace = 'default'): void
    {
        $this->db->query(
            "DELETE FROM {$this->table} WHERE namespace = ? AND lookup_key = ?",
            [$namespace, $key],
        );
        HookDispatcher::memoryForget($key, $namespace, self::DRIVER_NAME);
    }

    /**
     * Delete all keys in a namespace.
     *
     * @param  string  $namespace  Namespace to clear.
     * @return void
     */
    public function flush(string $namespace = 'default'): void
    {
        $this->db->query("DELETE FROM {$this->table} WHERE namespace = ?", [$namespace]);
    }

    /**
     * Return all non-expired key→value pairs in a namespace.
     *
     * @param  string  $namespace  Namespace to read.
     * @return array<string, mixed> Key-value map of non-expired entries.
     */
    public function all(string $namespace = 'default'): array
    {
        $now = date(self::DATETIME_FORMAT);
        $rows = $this->db->query(
            "SELECT lookup_key, value FROM {$this->table}
             WHERE namespace = ?
               AND (expires_at IS NULL OR expires_at > ?)",
            [$namespace, $now],
        )->rows;

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['lookup_key']] = $this->unserialize((string) $row['value']);
        }

        return $result;
    }

    /**
     * Whether a non-expired key exists in the namespace.
     *
     * @param  string  $key  The memory key.
     * @param  string  $namespace  Namespace to scope the lookup.
     * @return bool True when a non-expired entry exists.
     */
    public function has(string $key, string $namespace = 'default'): bool
    {
        return $this->get($key, $namespace) !== null;
    }

    /**
     * Encode a value for storage: strings are stored verbatim, other types are PHP-serialized.
     *
     * @param  mixed  $value
     * @return string
     */
    private function serialize(mixed $value): string
    {
        return is_string($value) ? $value : serialize($value);
    }

    /**
     * Decode a stored string back to its original value.
     *
     * @param  string  $stored
     * @return mixed
     */
    private function unserialize(string $stored): mixed
    {
        if ($stored !== '' && (str_starts_with($stored, 'a:') || str_starts_with($stored, 'O:') || str_starts_with($stored, 's:')
            || str_starts_with($stored, 'i:') || str_starts_with($stored, 'b:'))) {
            $result = unserialize($stored, ['allowed_classes' => false]);
            if ($result !== false) {
                return $result;
            }
        }

        return $stored;
    }
}

<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Memory;

use PhpClaw\Hooks\HookDispatcher;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\OpenCart\Contracts\OcDbInterface;
use PhpClaw\OpenCart\OcTablePrefix;
use PhpClaw\Support\Ulid;

/**
 * Database-backed key-value memory driver for phpClaw in OpenCart 3/4.
 */
final class OcDbMemory implements MemoryInterface
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    private const DRIVER_NAME = 'oc_db';

    private readonly string $table;

    /**
     * Bind the database handle and resolve the prefixed memory table name.
     *
     * @param  OcDbInterface|null  $db  OC native DB adapter, or null.
     * @param  string|null  $tablePrefix  OpenCart table prefix; defaults to DB_PREFIX, or 'oc_' when undefined.
     */
    public function __construct(
        private readonly ?OcDbInterface $db,
        ?string $tablePrefix = null,
    ) {
        $this->table = OcTablePrefix::resolve($tablePrefix).'phpclaw_memory';
    }

    /**
     * Retrieve a value. Returns null if not found or expired.
     *
     * @param  string  $key  Memory key.
     * @param  string  $namespace  Memory namespace.
     * @return mixed
     */
    public function get(string $key, string $namespace = 'default'): mixed
    {
        if ($this->db === null) {
            return null;
        }

        $now = date(self::DATETIME_FORMAT);
        $result = $this->db->query(
            "SELECT value FROM `{$this->table}`
             WHERE namespace = ? AND lookup_key = ?
               AND (expires_at IS NULL OR expires_at > ?)",
            [$namespace, $key, $now],
        );

        $value = $result->row['value'] ?? null;

        HookDispatcher::memoryRead($key, $namespace, self::DRIVER_NAME, $value !== null);

        return $value !== null ? MemoryValueSerializer::decode((string) $value) : null;
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
        if ($this->db === null) {
            return;
        }

        $now = date(self::DATETIME_FORMAT);
        $expiresAt = $ttl !== null ? date(self::DATETIME_FORMAT, time() + $ttl) : null;
        $stored = MemoryValueSerializer::encode($value);

        $check = $this->db->query(
            "SELECT id FROM `{$this->table}` WHERE namespace = ? AND lookup_key = ?",
            [$namespace, $key],
        );
        $existingId = $check->row['id'] ?? null;

        if ($existingId !== null) {
            $this->db->query(
                "UPDATE `{$this->table}`
                 SET value = ?, expires_at = ?, updated_at = ?
                 WHERE namespace = ? AND lookup_key = ?",
                [$stored, $expiresAt, $now, $namespace, $key],
            );
        } else {
            $this->db->query(
                "INSERT INTO `{$this->table}` (id, namespace, lookup_key, value, expires_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [Ulid::generate(), $namespace, $key, $stored, $expiresAt, $now, $now],
            );
        }

        HookDispatcher::memoryWrite($key, $namespace, self::DRIVER_NAME, $ttl);
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
        if ($this->db === null) {
            return;
        }

        $this->db->query(
            "DELETE FROM `{$this->table}` WHERE namespace = ? AND lookup_key = ?",
            [$namespace, $key],
        );

        HookDispatcher::memoryForget($key, $namespace, self::DRIVER_NAME);
    }

    /**
     * Delete all keys in a namespace.
     *
     * @param  string  $namespace  Memory namespace.
     * @return void
     */
    public function flush(string $namespace = 'default'): void
    {
        if ($this->db === null) {
            return;
        }

        $this->db->query(
            "DELETE FROM `{$this->table}` WHERE namespace = ?",
            [$namespace],
        );
    }

    /**
     * Return every stored key/value pair in the given namespace.
     *
     * @param  string  $namespace  Memory namespace.
     * @return array<string, mixed>
     */
    public function all(string $namespace = 'default'): array
    {
        if ($this->db === null) {
            return [];
        }

        $now = date(self::DATETIME_FORMAT);
        $result = $this->db->query(
            "SELECT lookup_key, value FROM `{$this->table}`
             WHERE namespace = ?
               AND (expires_at IS NULL OR expires_at > ?)",
            [$namespace, $now],
        );

        $out = [];
        foreach ($result->rows as $row) {
            $out[(string) $row['lookup_key']] = MemoryValueSerializer::decode((string) $row['value']);
        }

        return $out;
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
        return $this->get($key, $namespace) !== null;
    }
}

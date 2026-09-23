<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Memory;

use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\PrestaShop\Contracts\PsDbInterface;

/**
 * ps_configuration-backed memory driver for phpClaw in PrestaShop.
 */
final class PsSettingMemory implements MemoryInterface
{
    private const KEY_PREFIX = 'PHPCLAW_MEM_';

    private const MAX_KEY_LEN = 254;

    /**
     * Create a new PsSettingMemory instance.
     *
     * @param  ?PsDbInterface  $db
     * @param  string  $tablePrefix
     * @return void
     */
    public function __construct(
        private readonly ?PsDbInterface $db,
        private readonly string $tablePrefix = 'ps_',
    ) {}

    /**
     * Retrieve a stored value by key, returning null when not found or expired.
     *
     * @param  string  $key  Memory key.
     * @param  string  $namespace  Namespace to scope the lookup.
     * @return mixed
     */
    public function get(string $key, string $namespace = 'default'): mixed
    {
        if ($this->db === null) {
            return null;
        }

        $name = $this->makeName($namespace, $key);
        $row = $this->db->query(
            "SELECT value FROM `{$this->tablePrefix}configuration` WHERE name = ? LIMIT 1",
            [$name],
        )->row;

        if ($row === [] || (string) $row['value'] === '') {
            return null;
        }

        return $this->decodeEnvelope((string) $row['value']);
    }

    /**
     * Store a value. Upserts the ps_configuration row. Optional TTL in seconds.
     *
     * @param  string  $key  Memory key.
     * @param  mixed  $value  Value to store.
     * @param  string  $namespace  Namespace to scope the entry.
     * @param  int|null  $ttl  Optional TTL in seconds; null means no expiry.
     * @return void
     */
    public function set(string $key, mixed $value, string $namespace = 'default', ?int $ttl = null): void
    {
        if ($this->db === null) {
            return;
        }

        $name = $this->makeName($namespace, $key);
        $expiry = $ttl !== null ? time() + $ttl : null;
        $encoded = $this->encodeEnvelope($value, $expiry);

        $existing = $this->db->query(
            "SELECT id_configuration FROM `{$this->tablePrefix}configuration` WHERE name = ? LIMIT 1",
            [$name],
        )->row;

        $now = date('Y-m-d H:i:s');

        if ($existing !== []) {
            $this->db->query(
                "UPDATE `{$this->tablePrefix}configuration` SET value = ?, date_upd = ? WHERE name = ?",
                [$encoded, $now, $name],
            );
        } else {
            $this->db->query(
                "INSERT INTO `{$this->tablePrefix}configuration` (name, value, date_add, date_upd)
                 VALUES (?, ?, ?, ?)",
                [$name, $encoded, $now, $now],
            );
        }
    }

    /**
     * Delete a single key from the ps_configuration table.
     *
     * @param  string  $key  Memory key to remove.
     * @param  string  $namespace  Namespace to scope the deletion.
     * @return void
     */
    public function forget(string $key, string $namespace = 'default'): void
    {
        if ($this->db === null) {
            return;
        }

        $name = $this->makeName($namespace, $key);
        $this->db->query(
            "DELETE FROM `{$this->tablePrefix}configuration` WHERE name = ?",
            [$name],
        );
    }

    /**
     * Delete all keys belonging to the given namespace from ps_configuration.
     *
     * @param  string  $namespace  Namespace to flush.
     * @return void
     */
    public function flush(string $namespace = 'default'): void
    {
        if ($this->db === null) {
            return;
        }

        $prefix = self::KEY_PREFIX.strtoupper(rawurlencode($namespace)).'_';
        $this->db->query(
            "DELETE FROM `{$this->tablePrefix}configuration` WHERE name LIKE ?",
            [$prefix.'%'],
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
        if ($this->db === null) {
            return [];
        }

        $prefix = self::KEY_PREFIX.strtoupper(rawurlencode($namespace)).'_';
        $rows = $this->db->query(
            "SELECT name, value FROM `{$this->tablePrefix}configuration` WHERE name LIKE ?",
            [$prefix.'%'],
        )->rows;

        $result = [];

        foreach ($rows as $row) {
            $value = $this->decodeEnvelope((string) $row['value']);
            if ($value === null) {
                continue;
            }
            $rawKey = substr((string) $row['name'], strlen($prefix));
            $result[rawurldecode($rawKey)] = $value;
        }

        return $result;
    }

    /**
     * Check whether a non-expired key exists in the given namespace.
     *
     * @param  string  $key  Memory key to check.
     * @param  string  $namespace  Namespace to scope the check.
     * @return bool
     */
    public function has(string $key, string $namespace = 'default'): bool
    {
        return $this->get($key, $namespace) !== null;
    }

    /**
     * Build the ps_configuration `name` column value for a given namespace and key.
     *
     * @param  string  $namespace
     * @param  string  $key
     * @return string
     */
    private function makeName(string $namespace, string $key): string
    {
        $name = self::KEY_PREFIX
            .strtoupper(rawurlencode($namespace)).'_'
            .rawurlencode($key);

        return substr($name, 0, self::MAX_KEY_LEN);
    }

    /**
     * Encode a value and expiry timestamp into the JSON envelope stored in ps_configuration.
     *
     * @param  mixed  $value  Value to encode; non-strings are PHP-serialized.
     * @param  int|null  $expiry  Unix timestamp of expiry, or null for no expiry.
     * @return string
     */
    private function encodeEnvelope(mixed $value, ?int $expiry): string
    {
        return (string) json_encode([
            'v' => is_string($value) ? $value : serialize($value),
            'e' => $expiry,
        ]);
    }

    /**
     * Decode a JSON envelope from ps_configuration back to its original value.
     *
     * @param  string  $raw  Raw JSON string from the database.
     * @return mixed
     */
    private function decodeEnvelope(string $raw): mixed
    {
        $envelope = json_decode($raw, associative: true);

        if (! is_array($envelope) || ! array_key_exists('v', $envelope)) {
            return null;
        }

        if (isset($envelope['e']) && is_int($envelope['e']) && time() > $envelope['e']) {
            return null;
        }

        $stored = (string) $envelope['v'];

        if ($stored !== '' && (str_starts_with($stored, 'a:') || str_starts_with($stored, 'O:') ||
            str_starts_with($stored, 'i:') || str_starts_with($stored, 'b:'))) {
            $result = unserialize($stored, ['allowed_classes' => false]);
            if ($result !== false) {
                return $result;
            }
        }

        return $stored;
    }
}

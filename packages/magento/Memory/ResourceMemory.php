<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Memory;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PhpClaw\Hooks\HookDispatcher;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Support\Ulid;

/**
 * Magento ResourceConnection-backed memory driver for phpClaw.
 */
// non-final: Magento interceptor required
class ResourceMemory implements MemoryInterface
{
    private const TABLE = 'phpclaw_memory';

    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    private const DRIVER_NAME = 'magento.resource';

    private const PHP_SERIALIZED_SCALAR_OR_COMPOUND_PREFIX = '/^[aOsibdN]:/';

    private readonly AdapterInterface $connection;

    private readonly string $tableName;

    /**
     * Bind the Magento database adapter provider this driver reads and writes through.
     *
     * @param  ResourceConnection  $resourceConnection  Magento DB adapter provider.
     * @return void
     */
    public function __construct(
        ResourceConnection $resourceConnection,
    ) {
        $this->connection = $resourceConnection->getConnection();
        $this->tableName = $resourceConnection->getTableName(self::TABLE);
    }

    /**
     * Retrieve a value. Returns null if not found or expired.
     *
     * @param  string  $key  Lookup key.
     * @param  string  $namespace  Scoping namespace.
     * @return mixed
     */
    public function get(string $key, string $namespace = 'default'): mixed
    {
        $now = date(self::DATETIME_FORMAT);
        $result = $this->connection->fetchOne(
            "SELECT `value` FROM `{$this->tableName}`
             WHERE `namespace` = ? AND `lookup_key` = ?
               AND (`expires_at` IS NULL OR `expires_at` > ?)",
            [$namespace, $key, $now],
        );

        HookDispatcher::memoryRead($key, $namespace, self::DRIVER_NAME, $result !== false);

        return $result !== false ? $this->unserialize((string) $result) : null;
    }

    /**
     * Store a value. Upserts on (namespace, lookup_key). Optional TTL in seconds.
     *
     * @param  string  $key  Lookup key.
     * @param  mixed  $value  Value to store.
     * @param  string  $namespace  Scoping namespace.
     * @param  int|null  $ttl  Time-to-live in seconds, or null for no expiry.
     * @return void
     */
    public function set(string $key, mixed $value, string $namespace = 'default', ?int $ttl = null): void
    {
        $now = date(self::DATETIME_FORMAT);
        $expiresAt = $ttl !== null ? date(self::DATETIME_FORMAT, time() + $ttl) : null;
        $stored = $this->serialize($value);

        $this->connection->insertOnDuplicate(
            $this->tableName,
            [
                'id' => Ulid::generate(),
                'namespace' => $namespace,
                'lookup_key' => $key,
                'value' => $stored,
                'expires_at' => $expiresAt,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['value', 'expires_at', 'updated_at'],
        );

        HookDispatcher::memoryWrite($key, $namespace, self::DRIVER_NAME, $ttl);
    }

    /**
     * Delete a single key from the namespace.
     *
     * @param  string  $key  Lookup key to delete.
     * @param  string  $namespace  Scoping namespace.
     * @return void
     */
    public function forget(string $key, string $namespace = 'default'): void
    {
        $this->connection->delete($this->tableName, [
            'namespace = ?' => $namespace,
            'lookup_key = ?' => $key,
        ]);

        HookDispatcher::memoryForget($key, $namespace, self::DRIVER_NAME);
    }

    /**
     * Delete all keys in a namespace.
     *
     * @param  string  $namespace  Scoping namespace to clear.
     * @return void
     */
    public function flush(string $namespace = 'default'): void
    {
        $this->connection->delete($this->tableName, ['namespace = ?' => $namespace]);

        HookDispatcher::memoryForget('*', $namespace, self::DRIVER_NAME);
    }

    /**
     * Return all non-expired key→value pairs in a namespace.
     *
     * @param  string  $namespace  Scoping namespace.
     * @return array<string, mixed>
     */
    public function all(string $namespace = 'default'): array
    {
        $now = date(self::DATETIME_FORMAT);
        $rows = $this->connection->fetchPairs(
            "SELECT `lookup_key`, `value` FROM `{$this->tableName}`
             WHERE `namespace` = ?
               AND (`expires_at` IS NULL OR `expires_at` > ?)",
            [$namespace, $now],
        );

        return array_map(fn (string $v) => $this->unserialize($v), $rows ?: []);
    }

    /**
     * Whether a non-expired key exists in the namespace.
     *
     * @param  string  $key  Lookup key.
     * @param  string  $namespace  Scoping namespace.
     * @return bool
     */
    public function has(string $key, string $namespace = 'default'): bool
    {
        return $this->get($key, $namespace) !== null;
    }

    /**
     * Encode a value for DB storage; strings are stored as-is, other types use PHP serialization.
     *
     * @param  mixed  $value  The value to encode.
     * @return string
     */
    private function serialize(mixed $value): string
    {
        return is_string($value) ? $value : serialize($value);
    }

    /**
     * Decode a value retrieved from DB storage; PHP-serialized strings are unserialized.
     *
     * @param  string  $stored  The raw DB string to decode.
     * @return mixed
     */
    private function unserialize(string $stored): mixed
    {
        if ($stored !== '' && preg_match(self::PHP_SERIALIZED_SCALAR_OR_COMPOUND_PREFIX, $stored)) {
            $result = unserialize($stored, ['allowed_classes' => false]);
            if ($result !== false || $stored === 'b:0;' || $stored === 'N;') {
                return $result;
            }
        }

        return $stored;
    }
}

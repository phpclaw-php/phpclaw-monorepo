<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Memory;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use PhpClaw\Exceptions\MemoryException;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use PhpClaw\Support\Ulid;

/**
 * Doctrine DBAL-backed key-value memory driver for phpClaw.
 */
final class DoctrineMemory extends AbstractDoctrineMemory
{
    private const TABLE = 'phpclaw_memory';

    private const DRIVER_NAME = 'doctrine';

    /**
     * Constructs the key-value memory driver with the given DBAL connection.
     *
     * @param  Connection  $connection  Doctrine DBAL connection for the phpclaw_memory table.
     */
    public function __construct(
        private readonly Connection $connection,
    ) {}

    /**
     * Retrieves a stored value by key and namespace, evicting expired entries lazily.
     *
     * @param  string  $key
     * @param  string  $namespace
     * @return mixed
     *
     * @throws MemoryException
     */
    public function get(string $key, string $namespace = 'default'): mixed
    {
        return $this->guard('DoctrineMemory::get', function () use ($key, $namespace): mixed {
            $row = $this->connection->fetchAssociative(
                'SELECT id, value, expires_at FROM '.self::TABLE.' WHERE namespace = ? AND lookup_key = ?',
                [$namespace, $key],
            );

            $result = null;
            if ($row !== false) {
                if ($row['expires_at'] !== null && $this->isExpired((string) $row['expires_at'])) {
                    $this->deleteById((string) $row['id']);
                } else {
                    $result = json_decode((string) $row['value'], associative: true, flags: JSON_THROW_ON_ERROR);
                }
            }

            HookRegistry::fire(LifecycleEvent::MemoryRead->value, [
                'key' => $key, 'namespace' => $namespace,
                'driver' => self::DRIVER_NAME, 'hit' => $result !== null,
            ]);

            return $result;
        });
    }

    /**
     * Stores a value in phpclaw_memory, upserting by namespace+lookup_key.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @param  string  $namespace
     * @param  ?int  $ttl
     * @return void
     *
     * @throws MemoryException
     */
    public function set(string $key, mixed $value, string $namespace = 'default', ?int $ttl = null): void
    {
        $this->guard('DoctrineMemory::set', function () use ($key, $value, $namespace, $ttl): void {
            $now = $this->utcNow();
            $expiresAt = $this->expiryAt($ttl);
            $encoded = json_encode($value, flags: JSON_THROW_ON_ERROR);
            $platform = $this->connection->getDatabasePlatform();

            if ($platform instanceof MySQLPlatform) {
                $this->upsertMySQL(key: $key, namespace: $namespace, encoded: $encoded, expiresAt: $expiresAt, now: $now);
            } else {
                $this->upsertSqlite(key: $key, namespace: $namespace, encoded: $encoded, expiresAt: $expiresAt, now: $now);
            }

            HookRegistry::fire(LifecycleEvent::MemoryWrite->value, [
                'key' => $key, 'namespace' => $namespace,
                'driver' => self::DRIVER_NAME, 'ttl' => $ttl,
            ]);
        });
    }

    /**
     * Deletes a single key from phpclaw_memory.
     *
     * @param  string  $key
     * @param  string  $namespace
     * @return void
     *
     * @throws MemoryException
     */
    public function forget(string $key, string $namespace = 'default'): void
    {
        $this->guard('DoctrineMemory::forget', function () use ($key, $namespace): void {
            $this->connection->executeStatement(
                'DELETE FROM '.self::TABLE.' WHERE namespace = ? AND lookup_key = ?',
                [$namespace, $key],
            );

            HookRegistry::fire(LifecycleEvent::MemoryForget->value, [
                'key' => $key, 'namespace' => $namespace,
                'driver' => self::DRIVER_NAME,
            ]);
        });
    }

    /**
     * Deletes all keys in the given namespace from phpclaw_memory.
     *
     * @param  string  $namespace
     * @return void
     *
     * @throws MemoryException
     */
    public function flush(string $namespace = 'default'): void
    {
        $this->guard('DoctrineMemory::flush', function () use ($namespace): void {
            $this->connection->executeStatement(
                'DELETE FROM '.self::TABLE.' WHERE namespace = ?',
                [$namespace],
            );
        });
    }

    /**
     * Returns all non-expired keys in the given namespace.
     *
     * @param  string  $namespace
     * @return array<string, mixed>
     *
     * @throws MemoryException
     */
    public function all(string $namespace = 'default'): array
    {
        return (array) $this->guard('DoctrineMemory::all', function () use ($namespace): array {
            $now = $this->utcNow();
            $rows = $this->connection->fetchAllAssociative(
                'SELECT lookup_key, value FROM '.self::TABLE.' WHERE namespace = ? AND (expires_at IS NULL OR expires_at > ?)',
                [$namespace, $now],
            );

            $result = [];
            foreach ($rows as $row) {
                $result[(string) $row['lookup_key']] = json_decode(
                    json: (string) $row['value'],
                    associative: true,
                    flags: JSON_THROW_ON_ERROR,
                );
            }

            return $result;
        });
    }

    /**
     * Returns true if a non-expired key exists in phpclaw_memory.
     *
     * @param  string  $key
     * @param  string  $namespace
     * @return bool
     *
     * @throws MemoryException
     */
    public function has(string $key, string $namespace = 'default'): bool
    {
        return (bool) $this->guard('DoctrineMemory::has', function () use ($key, $namespace): bool {
            $now = $this->utcNow();
            $count = $this->connection->fetchOne(
                'SELECT COUNT(*) FROM '.self::TABLE.' WHERE namespace = ? AND lookup_key = ? AND (expires_at IS NULL OR expires_at > ?)',
                [$namespace, $key, $now],
            );

            return (int) $count > 0;
        });
    }

    /**
     * Upserts a row on MySQL using ON DUPLICATE KEY UPDATE.
     *
     * @param  string  $key
     * @param  string  $namespace
     * @param  string  $encoded
     * @param  ?string  $expiresAt
     * @param  string  $now
     * @return void
     */
    private function upsertMySQL(
        string $key,
        string $namespace,
        string $encoded,
        ?string $expiresAt,
        string $now,
    ): void {
        $id = Ulid::generate();

        $this->connection->executeStatement(
            'INSERT INTO '.self::TABLE.' (id, namespace, lookup_key, value, expires_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), expires_at = VALUES(expires_at), updated_at = VALUES(updated_at)',
            [$id, $namespace, $key, $encoded, $expiresAt, $now, $now],
        );
    }

    /**
     * Upserts a row on SQLite using a SELECT + INSERT OR UPDATE pattern.
     *
     * @param  string  $key
     * @param  string  $namespace
     * @param  string  $encoded
     * @param  ?string  $expiresAt
     * @param  string  $now
     * @return void
     */
    private function upsertSqlite(
        string $key,
        string $namespace,
        string $encoded,
        ?string $expiresAt,
        string $now,
    ): void {
        $existing = $this->connection->fetchAssociative(
            'SELECT id, created_at FROM '.self::TABLE.' WHERE namespace = ? AND lookup_key = ?',
            [$namespace, $key],
        );

        if ($existing !== false) {
            $this->connection->executeStatement(
                'UPDATE '.self::TABLE.' SET value = ?, expires_at = ?, updated_at = ? WHERE namespace = ? AND lookup_key = ?',
                [$encoded, $expiresAt, $now, $namespace, $key],
            );
        } else {
            $id = Ulid::generate();
            $this->connection->executeStatement(
                'INSERT INTO '.self::TABLE.' (id, namespace, lookup_key, value, expires_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$id, $namespace, $key, $encoded, $expiresAt, $now, $now],
            );
        }
    }

    /**
     * Deletes a row by primary key ID (used for lazy expiry eviction).
     *
     * @param  string  $id
     * @return void
     */
    private function deleteById(string $id): void
    {
        try {
            $this->connection->executeStatement(
                'DELETE FROM '.self::TABLE.' WHERE id = ?',
                [$id],
            );
        } catch (DbalException) {
        }
    }
}

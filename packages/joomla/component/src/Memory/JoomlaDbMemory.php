<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Component\Administrator\Memory;

use Joomla\Database\DatabaseInterface;
use PhpClaw\Hooks\HookDispatcher;
use PhpClaw\Joomla\Component\Administrator\Database\PhpClawTables;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Support\Ulid;

/**
 * Joomla Database API-backed memory driver for phpClaw.
 */
final class JoomlaDbMemory implements MemoryInterface
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    private const DEFAULT_NAMESPACE = 'default';

    private const DRIVER_NAME = 'joomla_db';

    private const SERIALIZED_PREFIXES = ['a:', 'O:', 's:', 'i:', 'd:', 'b:'];

    private const SERIALIZED_NULL = 'N;';

    private const ROW_COLUMNS = [
        'id', 'namespace', 'lookup_key', 'value',
        'expires_at', 'created_at', 'updated_at',
    ];

    private const NULL_LITERAL = 'NULL';

    /**
     * Create a new JoomlaDbMemory instance.
     *
     * @param  DatabaseInterface  $db  Joomla database connection.
     */
    public function __construct(
        private readonly DatabaseInterface $db,
    ) {}

    /**
     * Retrieve a value. Returns null when missing or expired.
     *
     * @param  string  $key
     * @param  string  $namespace
     * @return mixed
     */
    public function get(string $key, string $namespace = self::DEFAULT_NAMESPACE): mixed
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('value'))
            ->from($this->db->quoteName(PhpClawTables::MEMORY_TABLE))
            ->where($this->db->quoteName('namespace').' = '.$this->db->quote($namespace))
            ->where($this->db->quoteName('lookup_key').' = '.$this->db->quote($key))
            ->where($this->nonExpiredClause());

        $result = $this->db->setQuery($query)->loadResult();
        $hit = $result !== null;

        HookDispatcher::memoryRead($key, $namespace, self::DRIVER_NAME, $hit);

        return $hit ? self::unserialize((string) $result) : null;
    }

    /**
     * Return all non-expired key/value pairs in the namespace.
     *
     * @param  string  $namespace
     * @return array<string, mixed>
     */
    public function all(string $namespace = self::DEFAULT_NAMESPACE): array
    {
        $query = $this->db->getQuery(true)
            ->select([$this->db->quoteName('lookup_key'), $this->db->quoteName('value')])
            ->from($this->db->quoteName(PhpClawTables::MEMORY_TABLE))
            ->where($this->db->quoteName('namespace').' = '.$this->db->quote($namespace))
            ->where($this->nonExpiredClause());

        $rows = $this->db->setQuery($query)->loadAssocList('lookup_key', 'value') ?: [];

        return array_map(fn (string $v): mixed => self::unserialize($v), $rows);
    }

    /**
     * Whether a non-expired key exists in the namespace.
     *
     * @param  string  $key
     * @param  string  $namespace
     * @return bool
     */
    public function has(string $key, string $namespace = self::DEFAULT_NAMESPACE): bool
    {
        return $this->get($key, $namespace) !== null;
    }

    /**
     * Store a value. Upserts on (namespace, lookup_key).
     *
     * @param  string  $key
     * @param  mixed  $value
     * @param  string  $namespace
     * @param  int|null  $ttl  Optional TTL in seconds.
     * @return void
     */
    public function set(string $key, mixed $value, string $namespace = self::DEFAULT_NAMESPACE, ?int $ttl = null): void
    {
        $now = self::now();
        $expiresAt = $ttl !== null ? date(self::DATETIME_FORMAT, time() + $ttl) : null;
        $stored = self::serialize($value);

        $this->rowExists($key, $namespace)
            ? $this->updateRow($key, $namespace, $stored, $expiresAt, $now)
            : $this->insertRow($key, $namespace, $stored, $expiresAt, $now);

        HookDispatcher::memoryWrite($key, $namespace, self::DRIVER_NAME, $ttl);
    }

    /**
     * Delete a single key from the namespace.
     *
     * @param  string  $key
     * @param  string  $namespace
     * @return void
     */
    public function forget(string $key, string $namespace = self::DEFAULT_NAMESPACE): void
    {
        $delete = $this->db->getQuery(true)
            ->delete($this->db->quoteName(PhpClawTables::MEMORY_TABLE))
            ->where($this->db->quoteName('namespace').' = '.$this->db->quote($namespace))
            ->where($this->db->quoteName('lookup_key').' = '.$this->db->quote($key));

        $this->db->setQuery($delete)->execute();

        HookDispatcher::memoryForget($key, $namespace, self::DRIVER_NAME);
    }

    /**
     * Delete all keys in a namespace.
     *
     * @param  string  $namespace
     * @return void
     */
    public function flush(string $namespace = self::DEFAULT_NAMESPACE): void
    {
        $delete = $this->db->getQuery(true)
            ->delete($this->db->quoteName(PhpClawTables::MEMORY_TABLE))
            ->where($this->db->quoteName('namespace').' = '.$this->db->quote($namespace));

        $this->db->setQuery($delete)->execute();
    }

    /**
     * Whether a row with the given key + namespace exists.
     *
     * @param  string  $key
     * @param  string  $namespace
     * @return bool
     */
    private function rowExists(string $key, string $namespace): bool
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('id'))
            ->from($this->db->quoteName(PhpClawTables::MEMORY_TABLE))
            ->where($this->db->quoteName('namespace').' = '.$this->db->quote($namespace))
            ->where($this->db->quoteName('lookup_key').' = '.$this->db->quote($key));

        return $this->db->setQuery($query)->loadResult() !== null;
    }

    /**
     * Update an existing memory row in place.
     *
     * @param  string  $key
     * @param  string  $namespace
     * @param  string  $value  Serialized value to store.
     * @param  string|null  $expiresAt  Absolute expiry timestamp, or null for no TTL.
     * @param  string  $now  Current timestamp string used for `updated_at`.
     * @return void
     */
    private function updateRow(string $key, string $namespace, string $value, ?string $expiresAt, string $now): void
    {
        $update = $this->db->getQuery(true)
            ->update($this->db->quoteName(PhpClawTables::MEMORY_TABLE))
            ->set($this->db->quoteName('value').' = '.$this->db->quote($value))
            ->set($this->db->quoteName('expires_at').' = '.$this->expiresLiteral($expiresAt))
            ->set($this->db->quoteName('updated_at').' = '.$this->db->quote($now))
            ->where($this->db->quoteName('namespace').' = '.$this->db->quote($namespace))
            ->where($this->db->quoteName('lookup_key').' = '.$this->db->quote($key));

        $this->db->setQuery($update)->execute();
    }

    /**
     * Insert a fresh memory row.
     *
     * @param  string  $key
     * @param  string  $namespace
     * @param  string  $value  Serialized value to store.
     * @param  string|null  $expiresAt  Absolute expiry timestamp, or null for no TTL.
     * @param  string  $now  Current timestamp string used for `created_at`/`updated_at`.
     * @return void
     */
    private function insertRow(string $key, string $namespace, string $value, ?string $expiresAt, string $now): void
    {
        $insert = $this->db->getQuery(true)
            ->insert($this->db->quoteName(PhpClawTables::MEMORY_TABLE))
            ->columns($this->db->quoteName(self::ROW_COLUMNS))
            ->values(implode(',', [
                $this->db->quote(Ulid::generate()),
                $this->db->quote($namespace),
                $this->db->quote($key),
                $this->db->quote($value),
                $this->expiresLiteral($expiresAt),
                $this->db->quote($now),
                $this->db->quote($now),
            ]));

        $this->db->setQuery($insert)->execute();
    }

    /**
     * Quoted timestamp value, or the SQL `NULL` literal when no expiry is set.
     *
     * @param  ?string  $expiresAt
     * @return string
     */
    private function expiresLiteral(?string $expiresAt): string
    {
        return $expiresAt !== null ? $this->db->quote($expiresAt) : self::NULL_LITERAL;
    }

    /**
     * WHERE-fragment that excludes expired rows.
     *
     * @return string
     */
    private function nonExpiredClause(): string
    {
        return '(expires_at IS NULL OR expires_at > '.$this->db->quote(self::now()).')';
    }

    /**
     * Current server-local timestamp formatted for the `created_at`/`updated_at` columns.
     *
     * @return string
     */
    private static function now(): string
    {
        return date(self::DATETIME_FORMAT);
    }

    /**
     * Serialize a value for storage. Plain strings are stored as-is.
     *
     * @param  mixed  $value
     * @return string
     */
    private static function serialize(mixed $value): string
    {
        return is_string($value) ? $value : serialize($value);
    }

    /**
     * Unserialize a stored value back to its original type.
     *
     * @param  string  $stored
     * @return mixed
     */
    private static function unserialize(string $stored): mixed
    {
        if ($stored === self::SERIALIZED_NULL) {
            return null;
        }

        if ($stored !== '' && self::looksSerialized($stored)) {
            $result = unserialize($stored, ['allowed_classes' => false]);
            if ($result !== false) {
                return $result;
            }
        }

        return $stored;
    }

    /**
     * Heuristic: does the string start with one of the standard `serialize()` prefixes?
     *
     * @param  string  $stored
     * @return bool
     */
    private static function looksSerialized(string $stored): bool
    {
        foreach (self::SERIALIZED_PREFIXES as $prefix) {
            if (str_starts_with($stored, $prefix)) {
                return true;
            }
        }

        return false;
    }
}

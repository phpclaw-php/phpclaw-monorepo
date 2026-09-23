<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Memory;

use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\OpenCart\Contracts\OcDbInterface;
use PhpClaw\OpenCart\OcTablePrefix;

/**
 * Zero-migration memory driver that piggybacks on OpenCart's {DB_PREFIX}setting table.
 */
final class OcSettingMemory implements MemoryInterface
{
    private const CODE = 'phpclaw';

    private const KEY_PREFIX = 'mem_';

    private const MAX_KEY = 100;

    private readonly string $tablePrefix;

    /**
     * Bind the database handle and resolve the OpenCart table prefix.
     *
     * @param  OcDbInterface|null  $db  OC native DB adapter, or null.
     * @param  string|null  $tablePrefix  OpenCart table prefix; defaults to DB_PREFIX, or 'oc_' when undefined.
     */
    public function __construct(
        private readonly ?OcDbInterface $db,
        ?string $tablePrefix = null,
    ) {
        $this->tablePrefix = OcTablePrefix::resolve($tablePrefix);
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

        $row = $this->fetchRow($this->buildKey($key, $namespace));

        if ($row === null) {
            return null;
        }

        $envelope = $this->decodeEnvelope($row['value']);

        if ($envelope === null) {
            return null;
        }

        if ($envelope['e'] !== null && $envelope['e'] < time()) {
            $this->deleteKey($this->buildKey($key, $namespace));

            return null;
        }

        return MemoryValueSerializer::decode((string) $envelope['v']);
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

        $envelope = json_encode([
            'v' => MemoryValueSerializer::encode($value),
            'e' => $ttl !== null ? time() + $ttl : null,
        ]);

        $ocKey = $this->buildKey($key, $namespace);
        $table = $this->tablePrefix.'setting';

        $check = $this->db->query(
            "SELECT setting_id FROM `{$table}` WHERE code = ? AND `key` = ? AND store_id = 0",
            [self::CODE, $ocKey],
        );
        $existing = $check->num_rows > 0;

        if ($existing) {
            $this->db->query(
                "UPDATE `{$table}` SET value = ?, serialized = 0
                 WHERE code = ? AND `key` = ? AND store_id = 0",
                [$envelope, self::CODE, $ocKey],
            );
        } else {
            $this->db->query(
                "INSERT INTO `{$table}` (store_id, code, `key`, value, serialized)
                 VALUES (0, ?, ?, ?, 0)",
                [self::CODE, $ocKey, $envelope],
            );
        }
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
        $this->deleteKey($this->buildKey($key, $namespace));
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

        $prefix = self::KEY_PREFIX.rawurlencode($namespace).'_';
        $table = $this->tablePrefix.'setting';

        $this->db->query(
            "DELETE FROM `{$table}` WHERE code = ? AND `key` LIKE ? AND store_id = 0",
            [self::CODE, $prefix.'%'],
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

        $prefix = self::KEY_PREFIX.rawurlencode($namespace).'_';
        $table = $this->tablePrefix.'setting';
        $result = $this->db->query(
            "SELECT `key`, value FROM `{$table}`
             WHERE code = ? AND `key` LIKE ? AND store_id = 0",
            [self::CODE, $prefix.'%'],
        );

        $now = time();
        $out = [];

        foreach ($result->rows as $row) {
            $envelope = $this->decodeEnvelope((string) $row['value']);

            if ($envelope === null) {
                continue;
            }

            if ($envelope['e'] !== null && $envelope['e'] < $now) {
                continue;
            }

            $originalKey = rawurldecode(substr((string) $row['key'], strlen($prefix)));
            $out[$originalKey] = MemoryValueSerializer::decode((string) $envelope['v']);
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

    /**
     * Build the `{DB_PREFIX}setting.key` value used to namespace a phpClaw memory row.
     *
     * @param  string  $key  Caller-supplied memory key.
     * @param  string  $namespace  Memory namespace.
     * @return string
     */
    private function buildKey(string $key, string $namespace): string
    {
        $raw = self::KEY_PREFIX.rawurlencode($namespace).'_'.rawurlencode($key);

        return substr($raw, 0, self::MAX_KEY);
    }

    /**
     * Decode the stored JSON value envelope, returning null when malformed.
     *
     * @param  string  $raw  Raw JSON string read from the {DB_PREFIX}setting value column.
     * @return array{v: string, e: int|null}|null
     */
    private function decodeEnvelope(string $raw): ?array
    {
        $data = json_decode($raw, associative: true);

        if (! is_array($data) || ! array_key_exists('v', $data) || ! array_key_exists('e', $data)) {
            return null;
        }

        return ['v' => (string) $data['v'], 'e' => isset($data['e']) ? (int) $data['e'] : null];
    }

    /**
     * Fetch the raw setting row for an encoded OC key.
     *
     * @param  string  $ocKey  Encoded OC setting key (code + namespace + key concatenated).
     * @return array<string, mixed>|null
     */
    private function fetchRow(string $ocKey): ?array
    {
        if ($this->db === null) {
            return null;
        }

        $table = $this->tablePrefix.'setting';
        $result = $this->db->query(
            "SELECT value FROM `{$table}` WHERE code = ? AND `key` = ? AND store_id = 0",
            [self::CODE, $ocKey],
        );

        return $result->num_rows > 0 ? $result->row : null;
    }

    /**
     * Delete the phpClaw memory row identified by its OC `key` value.
     *
     * @param  string  $ocKey  Fully built OC `key` value from {@see self::buildKey()}.
     * @return void
     */
    private function deleteKey(string $ocKey): void
    {
        if ($this->db === null) {
            return;
        }

        $table = $this->tablePrefix.'setting';
        $this->db->query(
            "DELETE FROM `{$table}` WHERE code = ? AND `key` = ? AND store_id = 0",
            [self::CODE, $ocKey],
        );
    }
}

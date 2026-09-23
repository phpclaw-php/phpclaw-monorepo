<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Memory;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelInterface;
use PhpClaw\Hooks\HookDispatcher;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Support\Ulid;

/**
 * Drupal Database API-backed memory driver for phpClaw.
 */
final class DrupalDbMemory implements MemoryInterface
{
    private const DRIVER_NAME = 'drupal_db';

    /**
     * Create a new DrupalDbMemory instance.
     *
     * @param  Connection  $database  Drupal database connection
     * @param  TimeInterface  $time  Drupal time service for timestamp/TTL calculation.
     * @param  LoggerChannelInterface|null  $logger  phpClaw logger channel (nullable for direct construction in tests).
     * @return void
     */
    public function __construct(
        private readonly Connection $database,
        private readonly TimeInterface $time,
        private readonly ?LoggerChannelInterface $logger = null,
    ) {}

    /**
     * Retrieve a value from memory by key.
     *
     * @param  string  $key  Memory key to look up
     * @param  string  $namespace  Namespace scope for the key
     * @return mixed The stored value, or null if not found or expired
     */
    public function get(string $key, string $namespace = 'default'): mixed
    {
        try {
            $value = $this->database->query(
                'SELECT value FROM {phpclaw_memory}
                 WHERE namespace = :ns AND lookup_key = :key
                 AND (expires_at IS NULL OR expires_at > :now)',
                [':ns' => $namespace, ':key' => $key, ':now' => $this->time->getCurrentTime()]
            )->fetchField();
        } catch (\Throwable $e) {
            $this->logger?->error('Memory read failed: @class', ['@class' => $e::class]);

            return null;
        }

        $hit = $value !== false;
        HookDispatcher::memoryRead($key, $namespace, self::DRIVER_NAME, $hit);

        if (! $hit) {
            return null;
        }

        return self::maybeDecode((string) $value);
    }

    /**
     * Store a value in memory.
     *
     * @param  string  $key  Memory key
     * @param  mixed  $value  Value to store (strings stored as-is, others JSON-encoded)
     * @param  string  $namespace  Namespace scope for the key
     * @param  int|null  $ttl  Time-to-live in seconds, or null for no expiry
     * @return void
     *
     * @throws \JsonException If value cannot be JSON-encoded
     */
    public function set(string $key, mixed $value, string $namespace = 'default', ?int $ttl = null): void
    {
        $now = $this->time->getCurrentTime();
        $expiresAt = $ttl !== null ? ($this->time->getCurrentTime() + $ttl) : null;
        $stored = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        try {
            $this->database->merge('phpclaw_memory')
                ->keys(['namespace' => $namespace, 'lookup_key' => $key])
                ->insertFields([
                    'id' => Ulid::generate(),
                    'namespace' => $namespace,
                    'lookup_key' => $key,
                    'value' => $stored,
                    'expires_at' => $expiresAt,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->updateFields([
                    'value' => $stored,
                    'expires_at' => $expiresAt,
                    'updated_at' => $now,
                ])
                ->execute();
        } catch (\Throwable $e) {
            $this->logger?->error('Memory write failed: @class', ['@class' => $e::class]);

            return;
        }

        HookDispatcher::memoryWrite($key, $namespace, self::DRIVER_NAME, $ttl);
    }

    /**
     * Remove a single key from memory.
     *
     * @param  string  $key  Memory key to remove
     * @param  string  $namespace  Namespace scope for the key
     * @return void
     */
    public function forget(string $key, string $namespace = 'default'): void
    {
        try {
            $this->database->delete('phpclaw_memory')
                ->condition('namespace', $namespace)
                ->condition('lookup_key', $key)
                ->execute();
        } catch (\Throwable $e) {
            $this->logger?->error('Memory forget failed: @class', ['@class' => $e::class]);

            return;
        }

        HookDispatcher::memoryForget($key, $namespace, self::DRIVER_NAME);
    }

    /**
     * Remove all keys within a namespace.
     *
     * @param  string  $namespace  Namespace to flush
     * @return void
     */
    public function flush(string $namespace = 'default'): void
    {
        try {
            $this->database->delete('phpclaw_memory')
                ->condition('namespace', $namespace)
                ->execute();
        } catch (\Throwable $e) {
            $this->logger?->error('Memory flush failed: @class', ['@class' => $e::class]);

            return;
        }

        HookDispatcher::memoryForget('*', $namespace, self::DRIVER_NAME);
    }

    /**
     * Return all non-expired key→value pairs in a namespace.
     *
     * @param  string  $namespace  Namespace to retrieve
     * @return array<string, mixed>
     */
    public function all(string $namespace = 'default'): array
    {
        try {
            $result = $this->database->query(
                'SELECT lookup_key, value FROM {phpclaw_memory}
                 WHERE namespace = :ns
                 AND (expires_at IS NULL OR expires_at > :now)',
                [':ns' => $namespace, ':now' => $this->time->getCurrentTime()]
            );

            $raw = $result->fetchAllKeyed(0, 1) ?: [];
        } catch (\Throwable $e) {
            $this->logger?->error('Memory list failed: @class', ['@class' => $e::class]);

            return [];
        }

        return array_map(static fn ($v) => self::maybeDecode((string) $v), $raw);
    }

    /**
     * Check whether a non-expired key exists in memory.
     *
     * @param  string  $key  Memory key to check
     * @param  string  $namespace  Namespace scope for the key
     * @return bool True if the key exists and has not expired
     */
    public function has(string $key, string $namespace = 'default'): bool
    {
        try {
            $exists = (bool) $this->database->query(
                'SELECT 1 FROM {phpclaw_memory}
                 WHERE namespace = :ns AND lookup_key = :key
                 AND (expires_at IS NULL OR expires_at > :now)',
                [':ns' => $namespace, ':key' => $key, ':now' => $this->time->getCurrentTime()]
            )->fetchField();
        } catch (\Throwable $e) {
            $this->logger?->error('Memory exists-check failed: @class', ['@class' => $e::class]);

            return false;
        }

        return $exists;
    }

    /**
     * Attempt to decode a stored value from JSON or serialized format.
     *
     * @param  string  $value  Raw stored value
     * @return mixed Decoded value, or the original string
     */
    private static function maybeDecode(string $value): mixed
    {
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && $decoded !== null) {
            return $decoded;
        }

        if (preg_match('/^[aidsb]:\d/', $value)) {
            $safe = @unserialize($value, ['allowed_classes' => false]);
            if ($safe !== false || $value === 'b:0;') {
                return $safe;
            }
        }

        return $value;
    }
}

<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Memory;

use PhpClaw\Exceptions\MemoryException;
use PhpClaw\Hooks\HookDispatcher;
use PhpClaw\Memory\Contracts\MemoryInterface;

/**
 * Memory driver backed by WordPress's native wp_options table.
 */
final class WpOptionsMemory implements MemoryInterface
{
    private const DRIVER_NAME = 'wp_options';

    private const PREFIX = '_phpclaw_';

    private const EXPIRY_PREFIX = '_phpclaw_expiry_';

    /**
     * Retrieve a stored value by key and namespace.
     *
     * @param  string  $key  The memory key.
     * @param  string  $namespace  Namespace to scope the lookup.
     * @return mixed The stored value, or null if not found or expired.
     */
    public function get(string $key, string $namespace = 'default'): mixed
    {
        $optionKey = $this->optionKey($namespace, $key);

        if ($this->isExpired($namespace, $key)) {
            $this->deleteOption($optionKey);
            $this->deleteOption($this->expiryKey($namespace, $key));
            HookDispatcher::memoryRead($key, $namespace, self::DRIVER_NAME, false);

            return null;
        }

        $raw = get_option($optionKey, null);

        if ($raw === null || $raw === false) {
            HookDispatcher::memoryRead($key, $namespace, self::DRIVER_NAME, false);

            return null;
        }

        $value = $this->safeUnserialize((string) $raw);

        HookDispatcher::memoryRead($key, $namespace, self::DRIVER_NAME, true);

        return $value;
    }

    /**
     * Store a value in wp_options with optional TTL.
     *
     * @param  string  $key  The memory key.
     * @param  mixed  $value  Value to store (will be serialized).
     * @param  string  $namespace  Namespace to scope the entry.
     * @param  int|null  $ttl  TTL in seconds. Null = no expiry.
     * @return void
     */
    public function set(string $key, mixed $value, string $namespace = 'default', ?int $ttl = null): void
    {
        $optionKey = $this->optionKey($namespace, $key);
        $expiryKey = $this->expiryKey($namespace, $key);
        $expireAt = $ttl !== null ? (string) (time() + $ttl) : '';

        try {
            $serialised = serialize($value);
        } catch (\Throwable $e) {
            error_log("phpClaw WpOptionsMemory serialize failed for key [{$key}]: {$e->getMessage()}");
            throw new MemoryException("Failed to serialize value for key [{$key}]", 0, $e);
        }

        update_option($optionKey, $serialised, false);
        update_option($expiryKey, $expireAt, false);

        HookDispatcher::memoryWrite($key, $namespace, self::DRIVER_NAME, $ttl);
    }

    /**
     * Delete a single key and its expiry record from the given namespace.
     *
     * @param  string  $key  The memory key to remove.
     * @param  string  $namespace  Namespace to scope the deletion.
     * @return void
     */
    public function forget(string $key, string $namespace = 'default'): void
    {
        delete_option($this->optionKey($namespace, $key));
        delete_option($this->expiryKey($namespace, $key));

        HookDispatcher::memoryForget($key, $namespace, self::DRIVER_NAME);
    }

    /**
     * Delete all keys under the given namespace from wp_options.
     *
     * @param  string  $namespace  Namespace to flush.
     * @return void
     */
    public function flush(string $namespace = 'default'): void
    {
        global $wpdb;

        $prefix = $wpdb->esc_like(self::PREFIX.$namespace.'_').'%';

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $prefix,
            ),
        );

        $expiryPrefix = $wpdb->esc_like(self::EXPIRY_PREFIX.$namespace.'_').'%';

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $expiryPrefix,
            ),
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

        $prefix = $wpdb->esc_like(self::PREFIX.$namespace.'_').'%';
        $expiryPrefixLike = $wpdb->esc_like(self::EXPIRY_PREFIX.$namespace.'_').'%';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, option_value FROM {$wpdb->options}
                 WHERE option_name LIKE %s
                   AND option_name NOT LIKE %s",
                $prefix,
                $expiryPrefixLike,
            ),
            'ARRAY_A',
        ) ?? [];

        $out = [];
        $prefixLen = strlen(self::PREFIX.$namespace.'_');

        foreach ($rows as $row) {
            $key = substr((string) $row['option_name'], $prefixLen);

            if ($this->isExpired($namespace, $key)) {
                $this->forget($key, $namespace);

                continue;
            }

            $out[$key] = $this->safeUnserialize((string) $row['option_value']);
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
     * Build the wp_options key for a namespace + short key pair.
     *
     * @param  string  $namespace  Memory namespace.
     * @param  string  $key  Short key within the namespace.
     * @return string
     */
    private function optionKey(string $namespace, string $key): string
    {
        return self::PREFIX.$this->sanitise($namespace).'_'.$this->sanitise($key);
    }

    /**
     * Build the wp_options key used to store the expiry timestamp for a given entry.
     *
     * @param  string  $namespace  Memory namespace.
     * @param  string  $key  Short key within the namespace.
     * @return string
     */
    private function expiryKey(string $namespace, string $key): string
    {
        return self::EXPIRY_PREFIX.$this->sanitise($namespace).'_'.$this->sanitise($key);
    }

    /**
     * Sanitise a string for safe use as a wp_options key segment.
     *
     * @param  string  $input  Raw namespace or key string.
     * @return string
     */
    private function sanitise(string $input): string
    {
        return substr(rawurlencode($input), 0, 80);
    }

    /**
     * Check whether the stored expiry timestamp for a key has passed.
     *
     * @param  string  $namespace  Memory namespace.
     * @param  string  $key  Short key within the namespace.
     * @return bool
     */
    private function isExpired(string $namespace, string $key): bool
    {
        $expiryRaw = get_option($this->expiryKey($namespace, $key), '');

        if ($expiryRaw === '' || $expiryRaw === false) {
            return false;
        }

        return time() >= (int) $expiryRaw;
    }

    /**
     * Delete a wp_options entry by its full option key.
     *
     * @param  string  $optionKey  Full wp_options key to delete.
     * @return void
     */
    private function deleteOption(string $optionKey): void
    {
        delete_option($optionKey);
    }

    /**
     * Best-effort unserialize that survives legacy / manually-inserted rows.
     *
     * @param  string  $raw
     * @return mixed
     */
    private function safeUnserialize(string $raw): mixed
    {
        if ($raw === 'b:0;') {
            return false;
        }

        $result = @unserialize($raw, ['allowed_classes' => false]);

        return $result === false ? $raw : $result;
    }
}

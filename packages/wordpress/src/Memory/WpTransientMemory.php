<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Memory;

use PhpClaw\Exceptions\MemoryException;
use PhpClaw\Hooks\HookDispatcher;
use PhpClaw\Memory\Contracts\MemoryInterface;

/**
 * WordPress Transients API-backed cache memory driver for phpClaw.
 */
final class WpTransientMemory implements MemoryInterface
{
    private const DRIVER_NAME = 'wp_transient';

    private const PREFIX = 'phpclaw_';

    private const TRACKER_OPTION = 'phpclaw_tr_tracker_';

    private const DEFAULT_TTL = 86400;

    /**
     * Retrieve a stored value by key and namespace.
     *
     * @param  string  $key  The memory key.
     * @param  string  $namespace  Namespace to scope the lookup.
     * @return mixed The stored value, or null if not found or expired.
     */
    public function get(string $key, string $namespace = 'default'): mixed
    {
        try {
            $value = get_transient($this->transientKey($namespace, $key));
            $hit = $value !== false;

            HookDispatcher::memoryRead($key, $namespace, self::DRIVER_NAME, $hit);

            return $hit ? $value : null;
        } catch (\Throwable $e) {
            error_log("phpClaw WpTransientMemory::get failed: {$e->getMessage()}");
            throw new MemoryException('WpTransientMemory::get failed', previous: $e);
        }
    }

    /**
     * Store a value as a WordPress transient.
     *
     * @param  string  $key  The memory key.
     * @param  mixed  $value  Value to store.
     * @param  string  $namespace  Namespace to scope the entry.
     * @param  int|null  $ttl  TTL in seconds. Null = 86400 (24 h).
     * @return void
     */
    public function set(string $key, mixed $value, string $namespace = 'default', ?int $ttl = null): void
    {
        try {
            $expiration = $ttl ?? self::DEFAULT_TTL;

            set_transient($this->transientKey($namespace, $key), $value, $expiration);

            $this->trackerAdd($namespace, $key);
        } catch (\Throwable $e) {
            error_log("phpClaw WpTransientMemory::set failed: {$e->getMessage()}");
            throw new MemoryException('WpTransientMemory::set failed', previous: $e);
        }

        HookDispatcher::memoryWrite($key, $namespace, self::DRIVER_NAME, $ttl);
    }

    /**
     * Delete a single transient by key and namespace.
     *
     * @param  string  $key  The memory key to remove.
     * @param  string  $namespace  Namespace to scope the deletion.
     * @return void
     */
    public function forget(string $key, string $namespace = 'default'): void
    {
        try {
            delete_transient($this->transientKey($namespace, $key));
            $this->trackerRemove($namespace, $key);
        } catch (\Throwable $e) {
            error_log("phpClaw WpTransientMemory::forget failed: {$e->getMessage()}");
            throw new MemoryException('WpTransientMemory::forget failed', previous: $e);
        }

        HookDispatcher::memoryForget($key, $namespace, self::DRIVER_NAME);
    }

    /**
     * Delete all transients under the given namespace.
     *
     * @param  string  $namespace  Namespace to flush.
     * @return void
     */
    public function flush(string $namespace = 'default'): void
    {
        try {
            $known = $this->trackerGet($namespace);

            foreach ($known as $shortKey) {
                delete_transient($this->transientKey($namespace, $shortKey));
            }

            delete_option($this->trackerOptionName($namespace));
        } catch (\Throwable $e) {
            error_log("phpClaw WpTransientMemory::flush failed: {$e->getMessage()}");
            throw new MemoryException('WpTransientMemory::flush failed', previous: $e);
        }
    }

    /**
     * Return all non-expired key-value pairs stored under the given namespace.
     *
     * @param  string  $namespace  Namespace to scan.
     * @return array<string, mixed>
     */
    public function all(string $namespace = 'default'): array
    {
        try {
            $known = $this->trackerGet($namespace);
            $result = [];

            foreach ($known as $shortKey) {
                $val = get_transient($this->transientKey($namespace, $shortKey));

                if ($val !== false) {
                    $result[$shortKey] = $val;
                }
            }

            return $result;
        } catch (\Throwable $e) {
            error_log("phpClaw WpTransientMemory::all failed: {$e->getMessage()}");
            throw new MemoryException('WpTransientMemory::all failed', previous: $e);
        }
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
        try {
            return get_transient($this->transientKey($namespace, $key)) !== false;
        } catch (\Throwable $e) {
            error_log("phpClaw WpTransientMemory::has failed: {$e->getMessage()}");
            throw new MemoryException('WpTransientMemory::has failed', previous: $e);
        }
    }

    /**
     * Build the WordPress transient key for a namespace + short key pair.
     *
     * @param  string  $namespace  Memory namespace.
     * @param  string  $key  Short key within the namespace.
     * @return string
     */
    private function transientKey(string $namespace, string $key): string
    {
        $raw = self::PREFIX.$namespace.'_'.$key;

        if (strlen($raw) > 172) {
            $raw = self::PREFIX.md5($namespace.'_'.$key);
        }

        return $raw;
    }

    /**
     * Return the wp_options key used to track known transient keys for a namespace.
     *
     * @param  string  $namespace  Memory namespace.
     * @return string
     */
    private function trackerOptionName(string $namespace): string
    {
        return self::TRACKER_OPTION.$namespace;
    }

    /**
     * Return all short keys currently tracked for a namespace.
     *
     * @param  string  $namespace  Memory namespace.
     * @return string[]
     */
    private function trackerGet(string $namespace): array
    {
        return (array) get_option($this->trackerOptionName($namespace), []);
    }

    /**
     * Register a short key in the namespace tracker if not already present.
     *
     * @param  string  $namespace  Memory namespace.
     * @param  string  $shortKey  Short key to add.
     * @return void
     */
    private function trackerAdd(string $namespace, string $shortKey): void
    {
        $optionName = $this->trackerOptionName($namespace);
        $known = (array) get_option($optionName, []);

        if (! in_array($shortKey, $known, strict: true)) {
            $known[] = $shortKey;
            update_option($optionName, $known, false);
        }
    }

    /**
     * Remove a short key from the namespace tracker.
     *
     * @param  string  $namespace  Memory namespace.
     * @param  string  $shortKey  Short key to remove.
     * @return void
     */
    private function trackerRemove(string $namespace, string $shortKey): void
    {
        $optionName = $this->trackerOptionName($namespace);
        $known = (array) get_option($optionName, []);
        $filtered = array_values(array_filter($known, fn ($k) => $k !== $shortKey));

        if ($filtered === []) {
            delete_option($optionName);
        } elseif (count($filtered) !== count($known)) {
            update_option($optionName, $filtered, false);
        }
    }
}

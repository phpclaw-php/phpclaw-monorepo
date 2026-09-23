<?php

declare(strict_types=1);

namespace PhpClaw\Memory;

use PhpClaw\AutoDiscovery\Attributes\Memory;
use PhpClaw\Exceptions\MemoryException;
use PhpClaw\Hooks\HookDispatcher;
use PhpClaw\Memory\Contracts\MemoryInterface;

/**
 * File-backed memory driver using one JSON file per namespace, with atomic writes and shared locking.
 */
#[Memory(driver: 'file', label: 'JSON File', since: '1.0.0')]
final class FileMemory implements MemoryInterface
{
    public const DEFAULT_STORAGE_SUBPATH = 'storage/phpclaw/memory';

    public const DEFAULT_NAMESPACE = 'default';

    private const STORE_FILE_EXTENSION = '.json';

    private const LOCK_FILE_EXTENSION = '.lock';

    private const TEMP_FILE_PREFIX = 'phpclaw_mem_';

    private const DRIVER_NAME = 'file';

    private const DIRECTORY_MODE = 0755;

    private const JSON_ENCODE_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    private const SAFE_NAMESPACE_REGEX = '/[^a-zA-Z0-9_\-]/';

    private readonly string $storageDir;

    /**
     * Create a new FileMemory instance.
     *
     * @param  string|null  $storageDir  Absolute path to the storage directory. Defaults to <CWD>/storage/phpclaw/memory/.
     * @return void
     *
     * @throws MemoryException If the storage directory cannot be created.
     */
    public function __construct(?string $storageDir = null)
    {
        $raw = rtrim(
            $storageDir ?? (getcwd().DIRECTORY_SEPARATOR.self::DEFAULT_STORAGE_SUBPATH),
            DIRECTORY_SEPARATOR
        );

        $this->ensureDirectory($raw);

        $this->storageDir = rtrim((string) (realpath($raw) ?: $raw), DIRECTORY_SEPARATOR);
    }

    /**
     * Retrieve a value by key. Returns null when the key does not exist or has expired.
     *
     * @param  string  $key  Memory key.
     * @param  string  $namespace  Memory namespace.
     * @return mixed
     *
     * @throws MemoryException When the namespace file cannot be read or decoded.
     */
    public function get(string $key, string $namespace = self::DEFAULT_NAMESPACE): mixed
    {
        NamespaceValidator::validate($namespace);
        $store = $this->readStore($namespace);

        if (! isset($store[$key])) {
            $this->fireRead($key, $namespace, hit: false);

            return null;
        }

        if ($this->isExpired($store[$key])) {
            unset($store[$key]);
            $this->writeStore($store, $namespace);
            $this->fireRead($key, $namespace, hit: false);

            return null;
        }

        $this->fireRead($key, $namespace, hit: true);

        return $store[$key]['value'];
    }

    /**
     * Store a value under the given key, pruning expired entries before writing.
     *
     * @param  string  $key  Memory key.
     * @param  mixed  $value  Value to persist.
     * @param  string  $namespace  Memory namespace.
     * @param  int|null  $ttl  Time-to-live in seconds (null = never expires).
     * @return void
     *
     * @throws MemoryException When the namespace file cannot be written atomically.
     */
    public function set(string $key, mixed $value, string $namespace = self::DEFAULT_NAMESPACE, ?int $ttl = null): void
    {
        NamespaceValidator::validate($namespace);
        $store = $this->readStore($namespace);
        $store[$key] = [
            'value' => $value,
            'expires_at' => $ttl !== null ? time() + $ttl : null,
        ];

        $this->writeStore($this->pruneExpired($store), $namespace);

        HookDispatcher::memoryWrite($key, $namespace, self::DRIVER_NAME, $ttl);
    }

    /**
     * Delete the key from the namespace store file.
     *
     * @param  string  $key  Memory key.
     * @param  string  $namespace  Memory namespace.
     * @return void
     *
     * @throws MemoryException When the namespace file cannot be read or written.
     */
    public function forget(string $key, string $namespace = self::DEFAULT_NAMESPACE): void
    {
        NamespaceValidator::validate($namespace);
        $store = $this->readStore($namespace);
        unset($store[$key]);
        $this->writeStore($store, $namespace);

        HookDispatcher::memoryForget($key, $namespace, self::DRIVER_NAME);
    }

    /**
     * Delete the store file and lock file for the given namespace.
     *
     * @param  string  $namespace  Memory namespace.
     * @return void
     */
    public function flush(string $namespace = self::DEFAULT_NAMESPACE): void
    {
        NamespaceValidator::validate($namespace);
        $file = $this->filePath($namespace);

        if (file_exists($file)) {
            unlink($file);
        }

        $lock = $this->lockPath($namespace);

        if (file_exists($lock)) {
            unlink($lock);
        }
    }

    /**
     * Return all non-expired entries in the namespace as key → value pairs.
     *
     * @param  string  $namespace  Memory namespace.
     * @return array<string, mixed>
     *
     * @throws MemoryException When the namespace file cannot be read or decoded.
     */
    public function all(string $namespace = self::DEFAULT_NAMESPACE): array
    {
        NamespaceValidator::validate($namespace);
        $store = $this->readStore($namespace);
        $result = [];

        foreach ($store as $key => $entry) {
            if (! $this->isExpired($entry)) {
                $result[$key] = $entry['value'];
            }
        }

        return $result;
    }

    /**
     * Return true when the key exists in the store and has not expired.
     *
     * @param  string  $key  Memory key.
     * @param  string  $namespace  Memory namespace.
     * @return bool
     *
     * @throws MemoryException When the namespace file cannot be read or decoded.
     */
    public function has(string $key, string $namespace = self::DEFAULT_NAMESPACE): bool
    {
        NamespaceValidator::validate($namespace);
        $store = $this->readStore($namespace);

        if (! isset($store[$key])) {
            return false;
        }

        return ! $this->isExpired($store[$key]);
    }

    /**
     * Absolute path to the storage directory used for namespace files.
     *
     * @return string
     */
    public function storageDir(): string
    {
        return $this->storageDir;
    }

    /**
     * Read and decode the namespace store file.
     *
     * @param  string  $namespace  Memory namespace.
     * @return array<string, array{value: mixed, expires_at: int|null}>
     *
     * @throws MemoryException When the store file resolves outside storageDir or holds invalid JSON.
     */
    private function readStore(string $namespace): array
    {
        $file = $this->filePath($namespace);

        if (! file_exists($file)) {
            return [];
        }

        $real = realpath($file);

        if ($real === false || ! str_starts_with($real, $this->storageDir.DIRECTORY_SEPARATOR)) {
            throw new MemoryException(
                "Memory file for namespace '{$namespace}' resolved outside the storage directory."
            );
        }

        $lock = $this->acquireLock($namespace, LOCK_SH);
        $raw = file_get_contents($real);
        $this->releaseLock($lock);

        if ($raw === false || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, associative: true);

        if (! is_array($decoded)) {
            throw new MemoryException(
                "Memory store for namespace '{$namespace}' contains invalid JSON."
            );
        }

        return $decoded;
    }

    /**
     * Write the namespace store atomically.
     *
     * @param  array<string, array{value: mixed, expires_at: int|null}>  $store  Decoded store contents to persist.
     * @param  string  $namespace  Memory namespace.
     * @return void
     *
     * @throws MemoryException When encoding, temp-file creation, or atomic rename fails.
     */
    private function writeStore(array $store, string $namespace): void
    {
        $file = $this->filePath($namespace);
        $encoded = json_encode($store, self::JSON_ENCODE_FLAGS);

        if ($encoded === false) {
            throw new MemoryException(
                "Failed to JSON-encode memory store for namespace '{$namespace}'."
            );
        }

        $lock = $this->acquireLock($namespace, LOCK_EX);

        $tmp = tempnam($this->storageDir, self::TEMP_FILE_PREFIX);

        if ($tmp === false) {
            $this->releaseLock($lock);
            throw new MemoryException("Could not create temp file in '{$this->storageDir}'.");
        }

        if (file_put_contents($tmp, $encoded) === false) {
            unlink($tmp);
            $this->releaseLock($lock);
            throw new MemoryException("Failed to write temp memory file for namespace '{$namespace}'.");
        }

        if (! rename($tmp, $file)) {
            unlink($tmp);
            $this->releaseLock($lock);
            throw new MemoryException("Failed to atomically move memory file for namespace '{$namespace}'.");
        }

        $this->releaseLock($lock);
    }

    /**
     * Acquire a file lock (LOCK_SH or LOCK_EX) on the namespace lock file.
     *
     * @param  string  $namespace  Memory namespace.
     * @param  int  $lockType  `LOCK_SH` for shared (read) or `LOCK_EX` for exclusive (write).
     * @return resource Open file handle that holds the lock.
     *
     * @throws MemoryException When fopen or flock fails.
     */
    private function acquireLock(string $namespace, int $lockType): mixed
    {
        $lockFile = $this->lockPath($namespace);
        $handle = fopen($lockFile, 'c');

        if ($handle === false) {
            throw new MemoryException("Cannot open lock file for namespace '{$namespace}'.");
        }

        if (! flock($handle, $lockType)) {
            fclose($handle);
            throw new MemoryException("Cannot acquire lock for namespace '{$namespace}'.");
        }

        return $handle;
    }

    /**
     * Release a previously acquired file lock and close the handle.
     *
     * @param  resource  $handle  The lock file handle returned by acquireLock().
     * @return void
     */
    private function releaseLock(mixed $handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /**
     * Return true when the given store entry has a TTL that has already passed.
     *
     * @param  array{value: mixed, expires_at: int|null}  $entry  Store entry record.
     * @return bool
     */
    private function isExpired(array $entry): bool
    {
        return $entry['expires_at'] !== null && time() > $entry['expires_at'];
    }

    /**
     * Remove all expired entries from the store array (called before write).
     *
     * @param  array<string, array{value: mixed, expires_at: int|null}>  $store  Decoded store contents.
     * @return array<string, array{value: mixed, expires_at: int|null}>
     */
    private function pruneExpired(array $store): array
    {
        return array_filter($store, fn (array $entry) => ! $this->isExpired($entry));
    }

    /**
     * Absolute path to the JSON store file for the given namespace.
     *
     * @param  string  $namespace  Memory namespace.
     * @return string
     */
    private function filePath(string $namespace): string
    {
        return $this->storageDir.DIRECTORY_SEPARATOR.$this->sanitiseNamespace($namespace).self::STORE_FILE_EXTENSION;
    }

    /**
     * Absolute path to the lock file used for namespace I/O serialisation.
     *
     * @param  string  $namespace  Memory namespace.
     * @return string
     */
    private function lockPath(string $namespace): string
    {
        return $this->storageDir.DIRECTORY_SEPARATOR.$this->sanitiseNamespace($namespace).self::LOCK_FILE_EXTENSION;
    }

    /**
     * Strip any path traversal or filesystem-unsafe characters from namespace names.
     *
     * @param  string  $namespace  Raw namespace from caller.
     * @return string Filesystem-safe namespace token.
     */
    private function sanitiseNamespace(string $namespace): string
    {
        $safe = preg_replace(self::SAFE_NAMESPACE_REGEX, '_', $namespace);

        return ($safe !== null && $safe !== '') ? $safe : self::DEFAULT_NAMESPACE;
    }

    /**
     * Create the storage directory (and any missing parents) if it does not yet exist.
     *
     * @param  string  $dir  Absolute directory path.
     * @return void
     *
     * @throws MemoryException When the directory cannot be created.
     */
    private function ensureDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        if (! mkdir($dir, self::DIRECTORY_MODE, recursive: true) && ! is_dir($dir)) {
            throw new MemoryException("Cannot create memory storage directory: {$dir}");
        }
    }

    /**
     * Dispatch the memory.read hook: exposes only key, namespace, and driver, never the stored value.
     *
     * @param  string  $key  Memory key.
     * @param  string  $namespace  Memory namespace.
     * @param  bool  $hit  True when the key was found.
     * @return void
     */
    private function fireRead(string $key, string $namespace, bool $hit): void
    {
        HookDispatcher::memoryRead($key, $namespace, self::DRIVER_NAME, $hit);
    }
}

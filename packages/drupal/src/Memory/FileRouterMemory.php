<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Memory;

use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Memory\FileMemory;

/**
 * File-based memory with automatic conversation title extraction for the 'conversations' namespace.
 */
final class FileRouterMemory implements MemoryInterface
{
    use ConversationTitleTrait;

    private readonly FileMemory $file;

    /**
     * Construct the file-based router memory driver.
     *
     * @param  string|null  $storageDir  Absolute path to the directory used for file-based storage; null uses the core FileMemory default.
     * @return void
     */
    public function __construct(private readonly ?string $storageDir = null)
    {
        $this->file = new FileMemory($this->storageDir);
    }

    /**
     * Retrieve a value from memory by key and namespace.
     *
     * @param  string  $key  Memory key to look up.
     * @param  string  $namespace  Namespace the key belongs to; defaults to 'default'.
     * @return mixed The stored value, or null if not found.
     */
    public function get(string $key, string $namespace = 'default'): mixed
    {
        return $this->file->get($key, $namespace);
    }

    /**
     * Store a value in memory with optional TTL.
     *
     * @param  string  $key  Memory key to write.
     * @param  mixed  $value  Value to store; for 'conversations' namespace must be an array with a 'history' entry.
     * @param  string  $namespace  Namespace to store the key under; defaults to 'default'.
     * @param  int|null  $ttl  Time-to-live in seconds; passed through to core FileMemory.
     * @return void
     */
    public function set(string $key, mixed $value, string $namespace = 'default', ?int $ttl = null): void
    {
        if ($namespace === 'conversations' && is_array($value)) {
            $existing = $this->file->get($key, $namespace);

            if (! isset($value['title']) || ! is_string($value['title']) || $value['title'] === '') {
                $existingTitle = is_array($existing) ? ($existing['title'] ?? '') : '';

                if ($existingTitle !== '') {
                    $value['title'] = $existingTitle;
                } else {
                    $value['title'] = self::extractTitle((array) ($value['history'] ?? []));
                }
            }

            $value['updated_at'] = gmdate('Y-m-d H:i:s');
            if (! isset($value['created_at'])) {
                $existingCreated = is_array($existing) ? ($existing['created_at'] ?? '') : '';
                $value['created_at'] = $existingCreated !== '' ? $existingCreated : $value['updated_at'];
            }
        }

        $this->file->set($key, $value, $namespace, $ttl);
    }

    /**
     * Remove a key from memory.
     *
     * @param  string  $key  Memory key to delete.
     * @param  string  $namespace  Namespace the key belongs to; defaults to 'default'.
     * @return void
     */
    public function forget(string $key, string $namespace = 'default'): void
    {
        $this->file->forget($key, $namespace);
    }

    /**
     * Flush all keys in a namespace.
     *
     * @param  string  $namespace  Namespace to clear; defaults to 'default'.
     * @return void
     */
    public function flush(string $namespace = 'default'): void
    {
        $this->file->flush($namespace);
    }

    /**
     * Get all entries in a namespace.
     *
     * @param  string  $namespace  Namespace to enumerate; defaults to 'default'.
     * @return array<string, mixed>
     */
    public function all(string $namespace = 'default'): array
    {
        return $this->file->all($namespace);
    }

    /**
     * Check whether a key exists in memory.
     *
     * @param  string  $key  Memory key to check.
     * @param  string  $namespace  Namespace to search within; defaults to 'default'.
     * @return bool True if the key exists and has not expired, false otherwise.
     */
    public function has(string $key, string $namespace = 'default'): bool
    {
        return $this->file->has($key, $namespace);
    }
}

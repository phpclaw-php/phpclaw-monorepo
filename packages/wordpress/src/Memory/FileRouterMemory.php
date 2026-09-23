<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Memory;

use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Memory\FileMemory;
use PhpClaw\WordPress\Plugin;

/**
 * File-based memory driver with conversation title extraction and store_messages gating.
 */
final class FileRouterMemory implements MemoryInterface
{
    private const CONVERSATION_NAMESPACE = 'conversations';

    private readonly FileMemory $file;

    /**
     * Create a file-backed memory router.
     *
     * @param  string|null  $storageDir  Absolute path to the storage directory. Null = core default.
     */
    public function __construct(?string $storageDir = null)
    {
        $this->file = new FileMemory($storageDir);
    }

    /**
     * Retrieve a stored value by key and namespace.
     *
     * @param  string  $key  The memory key.
     * @param  string  $namespace  Namespace to scope the lookup.
     * @return mixed The stored value, or null if not found.
     */
    public function get(string $key, string $namespace = 'default'): mixed
    {
        return $this->file->get($key, $namespace);
    }

    /**
     * Store a value, applying store_messages gating and injecting title / timestamps for conversations.
     *
     * @param  string  $key  The memory key.
     * @param  mixed  $value  Value to store.
     * @param  string  $namespace  Namespace to scope the entry.
     * @param  int|null  $ttl  TTL in seconds. Null = no expiry.
     * @return void
     */
    public function set(string $key, mixed $value, string $namespace = 'default', ?int $ttl = null): void
    {
        if ($namespace === self::CONVERSATION_NAMESPACE && is_array($value)) {
            $storeMsg = self::storeMessages();

            if (! $storeMsg) {
                $value['history'] = [];
            }

            $existing = $this->file->get($key, $namespace);

            if (! isset($value['title']) || ! is_string($value['title']) || $value['title'] === '') {
                $existingTitle = is_array($existing) ? ($existing['title'] ?? '') : '';

                if ($existingTitle !== '') {
                    $value['title'] = $existingTitle;
                } else {
                    $value['title'] = $storeMsg ? self::extractTitle((array) ($value['history'] ?? [])) : null;
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
     * Delete a single key from the given namespace.
     *
     * @param  string  $key  The memory key to remove.
     * @param  string  $namespace  Namespace to scope the deletion.
     * @return void
     */
    public function forget(string $key, string $namespace = 'default'): void
    {
        $this->file->forget($key, $namespace);
    }

    /**
     * Delete all keys under the given namespace.
     *
     * @param  string  $namespace  Namespace to flush.
     * @return void
     */
    public function flush(string $namespace = 'default'): void
    {
        $this->file->flush($namespace);
    }

    /**
     * Return all key-value pairs stored under the given namespace.
     *
     * @param  string  $namespace  Namespace to scan.
     * @return array<string, mixed>
     */
    public function all(string $namespace = 'default'): array
    {
        return $this->file->all($namespace);
    }

    /**
     * Check whether a key exists in the given namespace.
     *
     * @param  string  $key  The memory key to check.
     * @param  string  $namespace  Namespace to scope the check.
     * @return bool
     */
    public function has(string $key, string $namespace = 'default'): bool
    {
        return $this->file->has($key, $namespace);
    }

    /**
     * Check whether "Save Conversations" is enabled.
     *
     * @return bool
     */
    private static function storeMessages(): bool
    {
        return Plugin::storeMessagesEnabled();
    }

    /**
     * Auto-generate a title from the first user message in the conversation.
     *
     * @param  array<int, array<string, mixed>>  $history
     * @return ?string
     */
    private static function extractTitle(array $history): ?string
    {
        foreach ($history as $message) {
            if (($message['role'] ?? '') === 'user' && is_string($message['content'] ?? null)) {
                $text = trim((string) $message['content']);
                if ($text !== '') {
                    return mb_substr($text, 0, 80);
                }
            }
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Memory;

use PhpClaw\Exceptions\MemoryException;
use PhpClaw\Hooks\HookDispatcher;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Support\Ulid;
use PhpClaw\WordPress\Exceptions\ConversationAccessDeniedException;
use PhpClaw\WordPress\Plugin;

/**
 * DB-backed conversation memory driver for WordPress.
 */
final class WpDbConversationMemory implements MemoryInterface
{
    private const DRIVER_NAME = 'wp_db_conv';

    private string $conversationsTable;

    private string $messagesTable;

    /**
     * Resolve the prefixed conversations and messages table names from the global wpdb instance.
     */
    public function __construct()
    {
        global $wpdb;
        $this->conversationsTable = $wpdb->prefix.'phpclaw_conversations';
        $this->messagesTable = $wpdb->prefix.'phpclaw_messages';
    }

    /**
     * Retrieve a conversation record and its message history by key.
     *
     * @param  string  $key  The conversation ID.
     * @param  string  $namespace  Namespace to scope the lookup.
     * @return mixed Associative array with id, namespace, title, metadata, created_at, history - or null if not found.
     *
     * @throws ConversationAccessDeniedException When the conversation exists but belongs to another user.
     */
    public function get(string $key, string $namespace = 'default'): mixed
    {
        global $wpdb;

        try {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, namespace, title, metadata, user_id, created_at
                     FROM {$this->conversationsTable}
                     WHERE id = %s AND namespace = %s
                     LIMIT 1",
                    $key,
                    $namespace,
                ),
                'ARRAY_A',
            );

            if ($row === null) {
                HookDispatcher::memoryRead($key, $namespace, self::DRIVER_NAME, false);

                return null;
            }

            self::assertOwnership($row);

            $data = [
                'id' => $row['id'],
                'namespace' => $row['namespace'],
                'title' => $row['title'],
                'metadata' => self::decodeMetadata((string) ($row['metadata'] ?? ''), (string) $row['id']),
                'created_at' => $row['created_at'],
                'history' => [],
            ];

            if (self::storeMessages()) {
                $messages = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT role, content, tool_name, tool_input
                         FROM {$this->messagesTable}
                         WHERE conversation_id = %s
                         ORDER BY created_at ASC, id ASC",
                        $key,
                    ),
                    'ARRAY_A',
                ) ?? [];

                $history = [];
                foreach ($messages as $msg) {
                    $entry = [
                        'role' => $msg['role'],
                        'content' => $msg['content'],
                        'tool_name' => $msg['tool_name'] ?? null,
                    ];
                    if (isset($msg['tool_input'])) {
                        $decoded = json_decode((string) $msg['tool_input'], true);
                        $entry['tool_input'] = is_array($decoded) ? $decoded : null;
                    }
                    $history[] = $entry;
                }

                $data['history'] = $history;
            }

            HookDispatcher::memoryRead($key, $namespace, self::DRIVER_NAME, true);

            return $data;
        } catch (ConversationAccessDeniedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log("phpClaw WpDbConversationMemory::get failed: {$e->getMessage()}");
            throw new MemoryException('WpDbConversationMemory::get failed', previous: $e);
        }
    }

    /**
     * Persist a conversation record and its message history.
     *
     * @param  string  $key  The conversation ID.
     * @param  mixed  $value  Associative array with history, title, metadata, created_at.
     * @param  string  $namespace  Namespace to scope the entry.
     * @param  int|null  $ttl  Unused - conversations do not expire.
     * @return void
     */
    public function set(string $key, mixed $value, string $namespace = 'default', ?int $ttl = null): void
    {
        global $wpdb;

        try {
            if (! is_array($value)) {
                throw new MemoryException('WpDbConversationMemory::set expects an array value.');
            }

            if (! self::storeMessages()) {
                HookDispatcher::memoryWrite($key, $namespace, self::DRIVER_NAME, $ttl);

                return;
            }

            wp_cache_delete('analytics_total_conversations', 'phpclaw');
            wp_cache_delete('analytics_total_messages', 'phpclaw');
            wp_cache_delete('analytics_active_conversations', 'phpclaw');

            $metadata = isset($value['metadata']) && is_array($value['metadata'])
                ? json_encode($value['metadata'])
                : null;

            $history = (array) ($value['history'] ?? []);

            $title = isset($value['title']) && is_string($value['title'])
                ? $value['title']
                : self::extractTitle($history);

            $now = gmdate('Y-m-d H:i:s');

            $userId = self::resolveWriteUserId();

            $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$this->conversationsTable} (id, namespace, title, metadata, user_id, created_at, updated_at)
                     VALUES (%s, %s, %s, %s, %d, %s, %s)
                     ON DUPLICATE KEY UPDATE
                         namespace  = VALUES(namespace),
                         title      = IF(title IS NULL OR title = '', VALUES(title), title),
                         metadata   = VALUES(metadata),
                         updated_at = VALUES(updated_at)",
                    $key,
                    $namespace,
                    $title,
                    $metadata,
                    $userId,
                    $value['created_at'] ?? $now,
                    $now,
                ),
            );

            if ($wpdb->last_error !== '') {
                error_log("phpClaw WpDbConversationMemory write failed for conversation [{$key}]: {$wpdb->last_error}");
                throw new MemoryException("DB write failed for conversation [{$key}]");
            }

            if ($history !== []) {
                $wpdb->delete($this->messagesTable, ['conversation_id' => $key], ['%s']);

                foreach ($history as $message) {
                    if (! is_array($message)) {
                        continue;
                    }

                    $role = $message['role'] ?? 'user';

                    if ($role === 'tool_batch') {
                        $calls = is_array($message['batch_calls'] ?? null) ? $message['batch_calls'] : [];
                        $results = is_array($message['batch_results'] ?? null) ? $message['batch_results'] : [];

                        if ($calls === []) {
                            continue;
                        }

                        $toolNames = array_column($calls, 'tool_name');
                        $toolInputs = array_column($calls, 'tool_input');

                        $wpdb->insert(
                            $this->messagesTable,
                            [
                                'id' => Ulid::generate(),
                                'conversation_id' => $key,
                                'role' => 'tool',
                                'content' => json_encode($results, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                                'tool_name' => implode(', ', $toolNames),
                                'tool_input' => json_encode($toolInputs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                                'created_at' => $now,
                            ],
                            ['%s', '%s', '%s', '%s', '%s', '%s', '%s'],
                        );

                        continue;
                    }

                    if (! in_array($role, ['user', 'assistant', 'tool'], true)) {
                        continue;
                    }

                    $wpdb->insert(
                        $this->messagesTable,
                        [
                            'id' => Ulid::generate(),
                            'conversation_id' => $key,
                            'role' => $role,
                            'content' => isset($message['content']) ? (string) $message['content'] : null,
                            'tool_name' => $message['tool_name'] ?? null,
                            'tool_input' => isset($message['tool_input']) ? json_encode($message['tool_input']) : null,
                            'created_at' => $now,
                        ],
                        ['%s', '%s', '%s', '%s', '%s', '%s', '%s'],
                    );
                }
            }
        } catch (MemoryException $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log("phpClaw WpDbConversationMemory::set failed: {$e->getMessage()}");
            throw new MemoryException('WpDbConversationMemory::set failed', previous: $e);
        }

        HookDispatcher::memoryWrite($key, $namespace, self::DRIVER_NAME, $ttl);
    }

    /**
     * Delete a conversation record and its associated messages.
     *
     * @param  string  $key  The conversation ID to remove.
     * @param  string  $namespace  Namespace to scope the deletion.
     * @return void
     */
    public function forget(string $key, string $namespace = 'default'): void
    {
        global $wpdb;

        try {
            $existing = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT user_id FROM {$this->conversationsTable} WHERE id = %s AND namespace = %s LIMIT 1",
                    $key,
                    $namespace,
                ),
                'ARRAY_A',
            );

            if (is_array($existing)) {
                self::assertOwnership($existing);
            }

            $wpdb->delete(
                $this->conversationsTable,
                ['id' => $key, 'namespace' => $namespace],
                ['%s', '%s'],
            );
        } catch (\Throwable $e) {
            error_log("phpClaw WpDbConversationMemory::forget failed: {$e->getMessage()}");
            throw new MemoryException('WpDbConversationMemory::forget failed', previous: $e);
        }

        HookDispatcher::memoryForget($key, $namespace, self::DRIVER_NAME);
    }

    /**
     * Delete every conversation and its messages under the given namespace, refusing the
     * call unless the caller holds phpclaw_manage_all_conversations.
     *
     * @param  string  $namespace  Namespace to flush.
     * @return void
     */
    public function flush(string $namespace = 'default'): void
    {
        global $wpdb;

        if (! self::canManageAllConversations()) {
            throw new ConversationAccessDeniedException('Access denied.');
        }

        try {
            $ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT id FROM {$this->conversationsTable} WHERE namespace = %s",
                    $namespace,
                ),
            ) ?? [];

            if ($ids !== []) {
                $placeholders = implode(',', array_fill(0, count($ids), '%s'));

                $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$this->messagesTable} WHERE conversation_id IN ({$placeholders})",
                        ...$ids,
                    ),
                );
            }

            $wpdb->delete($this->conversationsTable, ['namespace' => $namespace], ['%s']);
        } catch (\Throwable $e) {
            error_log("phpClaw WpDbConversationMemory::flush failed: {$e->getMessage()}");
            throw new MemoryException('WpDbConversationMemory::flush failed', previous: $e);
        }
    }

    /**
     * Return summary records for the current user's conversations in the given namespace, or
     * for every user's when the caller holds phpclaw_manage_all_conversations.
     *
     * @param  string  $namespace  Namespace to scan.
     * @return array<string, mixed>
     */
    public function all(string $namespace = 'default'): array
    {
        global $wpdb;

        try {
            $viewAll = self::canManageAllConversations();

            if ($viewAll) {
                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT id, title, metadata, created_at, updated_at
                         FROM {$this->conversationsTable}
                         WHERE namespace = %s
                         ORDER BY updated_at DESC",
                        $namespace,
                    ),
                    'ARRAY_A',
                ) ?? [];
            } else {
                $currentUserId = function_exists('get_current_user_id') ? get_current_user_id() : 0;

                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT id, title, metadata, created_at, updated_at
                         FROM {$this->conversationsTable}
                         WHERE namespace = %s AND user_id = %d
                         ORDER BY updated_at DESC",
                        $namespace,
                        $currentUserId,
                    ),
                    'ARRAY_A',
                ) ?? [];
            }

            $result = [];
            foreach ($rows as $row) {
                $result[(string) $row['id']] = [
                    'id' => $row['id'],
                    'title' => $row['title'],
                    'metadata' => self::decodeMetadata((string) ($row['metadata'] ?? ''), (string) $row['id']),
                    'created_at' => $row['created_at'],
                    'updated_at' => $row['updated_at'] ?? null,
                ];
            }

            return $result;
        } catch (\Throwable $e) {
            error_log("phpClaw WpDbConversationMemory::all failed: {$e->getMessage()}");
            throw new MemoryException('WpDbConversationMemory::all failed', previous: $e);
        }
    }

    /**
     * Check whether a conversation ID exists in the given namespace.
     *
     * @param  string  $key  The conversation ID to check.
     * @param  string  $namespace  Namespace to scope the check.
     * @return bool
     */
    public function has(string $key, string $namespace = 'default'): bool
    {
        global $wpdb;

        try {
            $count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->conversationsTable} WHERE id = %s AND namespace = %s",
                    $key,
                    $namespace,
                ),
            );

            return $count > 0;
        } catch (\Throwable $e) {
            error_log("phpClaw WpDbConversationMemory::has failed: {$e->getMessage()}");
            throw new MemoryException('WpDbConversationMemory::has failed', previous: $e);
        }
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
     * Resolve the user_id to stamp on a newly created conversation.
     *
     * @return int
     */
    private static function resolveWriteUserId(): int
    {
        if (defined('WP_CLI') && WP_CLI) {
            return 0;
        }

        return function_exists('get_current_user_id') ? get_current_user_id() : 0;
    }

    /**
     * Return true if the current user holds phpclaw_manage_all_conversations.
     *
     * @return bool
     */
    private static function canManageAllConversations(): bool
    {
        return function_exists('current_user_can')
            && current_user_can('phpclaw_manage_all_conversations');
    }

    /**
     * Assert that the current user owns the given conversation row, unless they hold
     * phpclaw_manage_all_conversations.
     *
     * @param  array<string, mixed>  $row  Row fetched from phpclaw_conversations.
     * @return void
     *
     * @throws ConversationAccessDeniedException
     */
    private static function assertOwnership(array $row): void
    {
        if (self::canManageAllConversations()) {
            return;
        }

        $rowUserId = array_key_exists('user_id', $row) && $row['user_id'] !== null
            ? (int) $row['user_id']
            : null;

        if ($rowUserId === null) {
            throw new ConversationAccessDeniedException('Access denied.');
        }

        $currentUserId = function_exists('get_current_user_id') ? get_current_user_id() : 0;

        if ($rowUserId !== $currentUserId) {
            throw new ConversationAccessDeniedException('Access denied.');
        }
    }

    /**
     * Decode conversation metadata JSON safely.
     *
     * @param  string  $raw  Raw JSON string from the DB column.
     * @param  string  $conversationId  Conversation ID used in the error log.
     * @return array<string, mixed>
     */
    private static function decodeMetadata(string $raw, string $conversationId): array
    {
        if ($raw === '' || $raw === 'null') {
            return [];
        }

        $decoded = json_decode($raw, associative: true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log(sprintf(
                'phpClaw: WpDbConversationMemory corrupt metadata JSON for conversation [%s]: %s',
                $conversationId,
                json_last_error_msg(),
            ));

            return [];
        }

        return is_array($decoded) ? $decoded : [];
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

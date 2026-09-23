<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Memory;

use PhpClaw\Exceptions\MemoryException;
use PhpClaw\Hooks\HookDispatcher;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\OpenCart\Contracts\OcDbInterface;
use PhpClaw\OpenCart\Exceptions\ConversationAccessDeniedException;
use PhpClaw\OpenCart\OcTablePrefix;
use PhpClaw\Support\Ulid;

/**
 * Database-backed conversation memory driver for phpClaw in OpenCart 3/4.
 */
final class OcDbConversationMemory implements MemoryInterface
{
    private const DRIVER_NAME = 'oc_conversations';

    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    private readonly string $convTable;

    private readonly string $msgTable;

    /**
     * Bind the database handle, the persistence flag, and the acting identity that scopes
     * every read and write.
     *
     * @param  OcDbInterface|null  $db  OC native DB adapter, or null.
     * @param  string|null  $tablePrefix  OpenCart table prefix; defaults to DB_PREFIX, or 'oc_' when undefined.
     * @param  bool  $storeMessages  Whether to persist message history.
     * @param  int  $actingUserId  The authenticated user's ID. 0 means CLI/unowned sentinel.
     * @param  bool  $manageAll  Whether the caller holds the manage-all conversations grant.
     */
    public function __construct(
        private readonly ?OcDbInterface $db,
        ?string $tablePrefix = null,
        private bool $storeMessages = true,
        private readonly int $actingUserId = 0,
        private readonly bool $manageAll = false,
    ) {
        $resolved = OcTablePrefix::resolve($tablePrefix);
        $this->convTable = $resolved.'phpclaw_conversations';
        $this->msgTable = $resolved.'phpclaw_messages';
    }

    /**
     * Retrieve a conversation by ID.
     *
     * @param  string  $key  Conversation ID.
     * @param  string  $namespace  Memory namespace.
     * @return mixed
     *
     * @throws ConversationAccessDeniedException When the row belongs to another user.
     */
    public function get(string $key, string $namespace = 'default'): mixed
    {
        if ($this->db === null) {
            return null;
        }

        try {
            $result = $this->db->query(
                "SELECT * FROM `{$this->convTable}` WHERE id = ? AND namespace = ?",
                [$key, $namespace],
            );

            if ($result->num_rows === 0) {
                HookDispatcher::memoryRead($key, $namespace, self::DRIVER_NAME, false);

                return null;
            }

            HookDispatcher::memoryRead($key, $namespace, self::DRIVER_NAME, true);

            $conv = $result->row;
            $this->assertOwnership($conv);

            $conv['metadata'] = $this->decodeJson((string) ($conv['metadata'] ?? ''));
            $conv['history'] = $this->storeMessages ? $this->fetchMessages($key) : [];

            return $conv;
        } catch (ConversationAccessDeniedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new MemoryException('Conversation memory read failed.', previous: $e);
        }
    }

    /**
     * Persist a conversation turn.
     *
     * @param  string  $key  Conversation ID.
     * @param  mixed  $value  Conversation payload array.
     * @param  string  $namespace  Memory namespace.
     * @param  int|null  $ttl  Ignored, conversations do not expire.
     * @return void
     */
    public function set(string $key, mixed $value, string $namespace = 'default', ?int $ttl = null): void
    {
        if ($this->db === null || ! is_array($value)) {
            return;
        }

        try {
            $now = date(self::DATETIME_FORMAT);
            $history = (array) ($value['history'] ?? []);

            $this->db->query('BEGIN');
            $this->upsertConversationRow($key, $namespace, $value, $now);
            $this->db->query("DELETE FROM `{$this->msgTable}` WHERE conversation_id = ?", [$key]);
            $this->insertMessages($key, $history, $now);
            $this->db->query('COMMIT');

            HookDispatcher::memoryWrite($key, $namespace, self::DRIVER_NAME, $ttl);
        } catch (\Throwable $e) {
            try {
                $this->db->query('ROLLBACK');
            } catch (\Throwable) {
            }
            throw new MemoryException('Conversation memory write failed.', previous: $e);
        }
    }

    /**
     * Delete a single key after verifying ownership.
     *
     * @param  string  $key  Memory key.
     * @param  string  $namespace  Memory namespace.
     * @return void
     *
     * @throws ConversationAccessDeniedException When the row belongs to another user.
     */
    public function forget(string $key, string $namespace = 'default'): void
    {
        if ($this->db === null) {
            return;
        }

        try {
            $existing = $this->db->query(
                "SELECT owner_id FROM `{$this->convTable}` WHERE id = ? AND namespace = ?",
                [$key, $namespace],
            );

            if ($existing->num_rows > 0) {
                $this->assertOwnership($existing->row);
            }

            $this->db->query(
                "DELETE FROM `{$this->msgTable}` WHERE conversation_id = ?",
                [$key],
            );

            $this->db->query(
                "DELETE FROM `{$this->convTable}` WHERE id = ? AND namespace = ?",
                [$key, $namespace],
            );

            HookDispatcher::memoryForget($key, $namespace, self::DRIVER_NAME);
        } catch (ConversationAccessDeniedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new MemoryException('Conversation memory delete failed.', previous: $e);
        }
    }

    /**
     * Delete all keys in a namespace. Requires the manage-all grant.
     *
     * @param  string  $namespace  Memory namespace.
     * @return void
     *
     * @throws ConversationAccessDeniedException When the caller does not hold manage-all.
     */
    public function flush(string $namespace = 'default'): void
    {
        if ($this->db === null) {
            return;
        }

        if (! $this->manageAll) {
            throw new ConversationAccessDeniedException('Access denied.');
        }

        try {
            $this->db->query(
                "DELETE FROM `{$this->msgTable}`
                 WHERE conversation_id IN (
                     SELECT id FROM `{$this->convTable}` WHERE namespace = ?
                 )",
                [$namespace],
            );
            $this->db->query(
                "DELETE FROM `{$this->convTable}` WHERE namespace = ?",
                [$namespace],
            );
        } catch (\Throwable $e) {
            throw new MemoryException('Conversation memory flush failed.', previous: $e);
        }
    }

    /**
     * Return every conversation record in the given namespace, scoped to the acting user
     * unless the caller holds the manage-all grant.
     *
     * @param  string  $namespace  Memory namespace.
     * @return array<string, mixed>
     */
    public function all(string $namespace = 'default'): array
    {
        if ($this->db === null) {
            return [];
        }

        try {
            if ($this->manageAll) {
                $result = $this->db->query(
                    "SELECT id, title, created_at FROM `{$this->convTable}`
                     WHERE namespace = ? ORDER BY created_at DESC",
                    [$namespace],
                );
            } else {
                $result = $this->db->query(
                    "SELECT id, title, created_at FROM `{$this->convTable}`
                     WHERE namespace = ? AND owner_id = ? ORDER BY created_at DESC",
                    [$namespace, $this->actingUserId],
                );
            }

            $out = [];
            foreach ($result->rows as $row) {
                $out[(string) $row['id']] = $row;
            }

            return $out;
        } catch (\Throwable $e) {
            throw new MemoryException('Conversation memory list failed.', previous: $e);
        }
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
        if ($this->db === null) {
            return false;
        }

        try {
            $result = $this->db->query(
                "SELECT COUNT(*) AS n FROM `{$this->convTable}` WHERE id = ? AND namespace = ?",
                [$key, $namespace],
            );

            return (int) ($result->row['n'] ?? 0) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Assert that the acting user owns the given conversation row, unless the caller was
     * constructed with manage-all rights.
     *
     * @param  array<string, mixed>  $row  Row fetched from phpclaw_conversations.
     * @return void
     *
     * @throws ConversationAccessDeniedException
     */
    private function assertOwnership(array $row): void
    {
        if ($this->manageAll) {
            return;
        }

        $rawOwnerId = $row['owner_id'] ?? null;

        if ($rawOwnerId === null) {
            throw new ConversationAccessDeniedException('Access denied.');
        }

        $rowOwnerId = (int) $rawOwnerId;

        if ($rowOwnerId === 0 || $rowOwnerId !== $this->actingUserId) {
            throw new ConversationAccessDeniedException('Access denied.');
        }
    }

    /**
     * Load the ordered message history for the conversation.
     *
     * @param  string  $conversationId  ULID of the conversation whose messages to load.
     * @return array<int, array<string, mixed>>
     */
    private function fetchMessages(string $conversationId): array
    {
        if ($this->db === null) {
            return [];
        }

        $result = $this->db->query(
            "SELECT role, content, tool_name, tool_input, created_at
             FROM `{$this->msgTable}` WHERE conversation_id = ? ORDER BY created_at ASC, id ASC",
            [$conversationId],
        );

        return array_map(static function (array $row): array {
            if ($row['tool_input'] !== null) {
                $decoded = json_decode((string) $row['tool_input'], associative: true);
                $row['tool_input'] = is_array($decoded) ? $decoded : $row['tool_input'];
            }

            return $row;
        }, $result->rows);
    }

    /**
     * Decode a JSON column value safely, returning null on empty or invalid JSON.
     *
     * @param  string  $raw  Raw column value read from the messages table.
     * @return mixed Decoded associative array, or null when the column
     *               is empty / unset / invalid.
     */
    private function decodeJson(string $raw): mixed
    {
        if ($raw === '' || $raw === 'null') {
            return null;
        }

        $decoded = json_decode($raw, associative: true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Insert or update the conversation record, stamping owner_id only on INSERT.
     *
     * @param  string  $key  Conversation ID.
     * @param  string  $namespace  Memory namespace.
     * @param  array<string, mixed>  $value  Conversation payload array.
     * @param  string  $now  Current timestamp, `Y-m-d H:i:s` format.
     * @return void
     */
    private function upsertConversationRow(string $key, string $namespace, array $value, string $now): void
    {
        $title = (string) ($value['title'] ?? '');
        $history = (array) ($value['history'] ?? []);
        $metadata = json_encode($value['metadata'] ?? new \stdClass);

        if ($title === '' && $history !== []) {
            foreach ($history as $msg) {
                if (($msg['role'] ?? '') === 'user' && ! empty($msg['content'])) {
                    $title = mb_substr((string) $msg['content'], 0, 80);
                    break;
                }
            }
        }

        $exists = $this->db->query(
            "SELECT id FROM `{$this->convTable}` WHERE id = ?",
            [$key],
        )->num_rows > 0;

        if ($exists) {
            $this->db->query(
                "UPDATE `{$this->convTable}` SET title = ?, metadata = ?, updated_at = ? WHERE id = ?",
                [$title ?: null, $metadata, $now, $key],
            );
        } else {
            $this->db->query(
                "INSERT INTO `{$this->convTable}` (id, namespace, owner_id, title, metadata, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$key, $namespace, $this->actingUserId, $title ?: null, $metadata, $now, $now],
            );
        }
    }

    /**
     * Insert the given message-history rows for the conversation.
     *
     * @param  string  $key  Conversation ID.
     * @param  array<int, mixed>  $history  Message history to persist.
     * @param  string  $now  Current timestamp, `Y-m-d H:i:s` format.
     * @return void
     */
    private function insertMessages(string $key, array $history, string $now): void
    {
        foreach ($history as $msg) {
            if (! is_array($msg)) {
                continue;
            }

            $role = (string) ($msg['role'] ?? '');

            if ($role === 'tool_batch') {
                $calls = is_array($msg['batch_calls'] ?? null) ? $msg['batch_calls'] : [];
                $results = is_array($msg['batch_results'] ?? null) ? $msg['batch_results'] : [];

                if ($calls === []) {
                    continue;
                }

                $this->db->query(
                    "INSERT INTO `{$this->msgTable}`
                        (id, conversation_id, role, content, tool_name, tool_input, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [
                        Ulid::generate(),
                        $key,
                        'tool',
                        json_encode($results, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        implode(', ', array_column($calls, 'tool_name')),
                        json_encode(array_column($calls, 'tool_input'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        $now,
                    ],
                );

                continue;
            }

            if (! in_array($role, ['user', 'assistant', 'tool'], true)) {
                continue;
            }

            $this->db->query(
                "INSERT INTO `{$this->msgTable}`
                    (id, conversation_id, role, content, tool_name, tool_input, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [
                    Ulid::generate(),
                    $key,
                    $role,
                    (string) ($msg['content'] ?? ''),
                    $msg['tool_name'] ?? null,
                    isset($msg['tool_input']) ? json_encode($msg['tool_input']) : null,
                    $now,
                ],
            );
        }
    }
}

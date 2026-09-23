<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Memory;

use PhpClaw\Exceptions\MemoryException;
use PhpClaw\Hooks\HookDispatcher;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\PrestaShop\Contracts\PsDbInterface;
use PhpClaw\PrestaShop\Exceptions\ConversationAccessDeniedException;
use PhpClaw\Support\Ulid;

/**
 * DB-backed conversation memory driver for PrestaShop.
 */
final class PsDbConversationMemory implements MemoryInterface
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    private const DEFAULT_NAMESPACE = 'default';

    private const DRIVER_NAME = 'ps_db_conv';

    private const TITLE_MAX_LENGTH = 60;

    private const TITLE_ELLIPSIS = "\u{2026}";

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    private const ROLE_USER = 'user';

    private const ROLE_ASSISTANT = 'assistant';

    private const ROLE_TOOL = 'tool';

    private const ROLE_TOOL_BATCH = 'tool_batch';

    private const PERSISTED_ROLES = [self::ROLE_USER, self::ROLE_ASSISTANT, self::ROLE_TOOL];

    /**
     * Create a new PsDbConversationMemory instance.
     *
     * @param  PsDbInterface|null  $db  PrestaShop DB abstraction; null when unavailable (returns safe defaults).
     * @param  string  $tablePrefix  Table prefix used in PS (e.g. 'ps_').
     * @param  bool|null  $storeMessages  Explicit store-messages flag; null reads the saved settings file.
     * @param  int  $actingEmployeeId  Acting employee id, or the `0` unverified-identity sentinel.
     * @param  bool  $manageAll  Whether the acting identity holds the manage-all-conversations grant.
     */
    public function __construct(
        private readonly ?PsDbInterface $db,
        private readonly string $tablePrefix = 'ps_',
        private readonly ?bool $storeMessages = null,
        private readonly int $actingEmployeeId = 0,
        private readonly bool $manageAll = false,
    ) {}

    /**
     * Retrieve a conversation record and its message history by key.
     *
     * @param  string  $key
     * @param  string  $namespace
     * @return array<string, mixed>|null
     *
     * @throws MemoryException
     */
    public function get(string $key, string $namespace = self::DEFAULT_NAMESPACE): mixed
    {
        if ($this->db === null) {
            return null;
        }

        return $this->guard('get', function () use ($key, $namespace): ?array {
            $row = $this->loadConversationRow($key, $namespace);

            if ($row === null) {
                HookDispatcher::memoryRead($key, $namespace, self::DRIVER_NAME, false);

                return null;
            }

            if (! $this->isVisible($row)) {
                HookDispatcher::memoryRead($key, $namespace, self::DRIVER_NAME, false);

                throw new ConversationAccessDeniedException;
            }

            $data = $this->rowToConversation($row);

            if ($this->resolveStoreMessages()) {
                $data['history'] = $this->loadHistory($key);
            }

            HookDispatcher::memoryRead($key, $namespace, self::DRIVER_NAME, true);

            return $data;
        });
    }

    /**
     * Return summary records for the acting employee's conversations in the given namespace,
     * or for every employee's when the caller holds the manage-all grant.
     *
     * @param  string  $namespace
     * @return array<string, mixed>
     *
     * @throws MemoryException
     */
    public function all(string $namespace = self::DEFAULT_NAMESPACE): array
    {
        if ($this->db === null) {
            return [];
        }

        return $this->guard('all', function () use ($namespace): array {
            $tbl = "`{$this->tablePrefix}phpclaw_conversations`";

            if ($this->manageAll) {
                $rows = $this->db->query(
                    "SELECT id, title, metadata, created_at, updated_at
                     FROM {$tbl}
                     WHERE namespace = ?
                     ORDER BY updated_at DESC, id DESC",
                    [$namespace],
                )->rows;
            } else {
                $rows = $this->db->query(
                    "SELECT id, title, metadata, created_at, updated_at
                     FROM {$tbl}
                     WHERE namespace = ? AND id_employee = ?
                     ORDER BY updated_at DESC, id DESC",
                    [$namespace, $this->actingEmployeeId],
                )->rows;
            }

            $result = [];
            foreach ($rows as $row) {
                $result[(string) $row['id']] = $this->summaryRow($row);
            }

            return $result;
        });
    }

    /**
     * Check whether a conversation ID exists in the given namespace.
     *
     * @param  string  $key
     * @param  string  $namespace
     * @return bool
     *
     * @throws MemoryException
     */
    public function has(string $key, string $namespace = self::DEFAULT_NAMESPACE): bool
    {
        if ($this->db === null) {
            return false;
        }

        return $this->guard('has', function () use ($key, $namespace): bool {
            $row = $this->loadConversationRow($key, $namespace);

            return $row !== null && $this->isVisible($row);
        });
    }

    /**
     * Persist a conversation record and its message history.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @param  string  $namespace
     * @param  int|null  $ttl  Unused, conversations do not expire.
     * @return void
     */
    public function set(string $key, mixed $value, string $namespace = self::DEFAULT_NAMESPACE, ?int $ttl = null): void
    {
        if ($this->db === null || ! is_array($value)) {
            return;
        }

        if (! $this->resolveStoreMessages()) {
            return;
        }

        $this->guard('set', function () use ($key, $value, $namespace): void {
            $existing = $this->loadConversationRow($key, $namespace);

            if ($existing !== null && ! $this->isVisible($existing)) {
                throw new ConversationAccessDeniedException;
            }

            $history = (array) ($value['history'] ?? []);
            $metadata = $this->encodeMetadata($value['metadata'] ?? null);
            $title = $this->resolveTitle($value, $history);
            $now = date(self::DATETIME_FORMAT);

            $this->upsertConversation(
                $key,
                $namespace,
                $title,
                $metadata,
                $now,
                (string) ($value['created_at'] ?? $now),
            );

            if ($history !== []) {
                $this->replaceMessages($key, $history, $now);
            }

            HookDispatcher::memoryWrite($key, $namespace, self::DRIVER_NAME);
        });
    }

    /**
     * Delete a conversation record and its associated messages.
     *
     * @param  string  $key
     * @param  string  $namespace
     * @return void
     *
     * @throws MemoryException
     */
    public function forget(string $key, string $namespace = self::DEFAULT_NAMESPACE): void
    {
        if ($this->db === null) {
            return;
        }

        $this->guard('forget', function () use ($key, $namespace): void {
            $row = $this->loadConversationRow($key, $namespace);

            if ($row === null) {
                return;
            }

            if (! $this->isVisible($row)) {
                throw new ConversationAccessDeniedException;
            }

            $this->deleteMessagesByConversation($key);

            $this->db->query(
                "DELETE FROM `{$this->tablePrefix}phpclaw_conversations`
                 WHERE id = ? AND namespace = ?",
                [$key, $namespace],
            );

            HookDispatcher::memoryForget($key, $namespace, self::DRIVER_NAME);
        });
    }

    /**
     * Delete every conversation and its messages under the given namespace, doing nothing at
     * all unless the caller holds the manage-all grant.
     *
     * @param  string  $namespace
     * @return void
     *
     * @throws MemoryException
     */
    public function flush(string $namespace = self::DEFAULT_NAMESPACE): void
    {
        if ($this->db === null || ! $this->manageAll) {
            return;
        }

        $this->guard('flush', function () use ($namespace): void {
            $tbl = "`{$this->tablePrefix}phpclaw_conversations`";
            $rows = $this->db->query(
                "SELECT id FROM {$tbl} WHERE namespace = ?",
                [$namespace],
            )->rows;

            $ids = array_map(static fn (array $row): mixed => $row['id'], $rows);

            if ($ids !== []) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $this->db->query(
                    "DELETE FROM `{$this->tablePrefix}phpclaw_messages`
                     WHERE conversation_id IN ({$placeholders})",
                    $ids,
                );
            }

            $this->db->query(
                "DELETE FROM {$tbl} WHERE namespace = ?",
                [$namespace],
            );
        });
    }

    /**
     * Load the single conversation row by id + namespace. Null if missing.
     *
     * @param  string  $key
     * @param  string  $namespace
     * @return array<string, mixed>|null
     */
    private function loadConversationRow(string $key, string $namespace): ?array
    {
        $row = $this->db->query(
            "SELECT id, namespace, id_employee, title, metadata, created_at, updated_at
             FROM `{$this->tablePrefix}phpclaw_conversations`
             WHERE id = ? AND namespace = ?
             LIMIT 1",
            [$key, $namespace],
        )->row;

        return ($row !== [] && isset($row['id'])) ? $row : null;
    }

    /**
     * Whether the acting identity may see this row: manage-all grant, or an exact owner match.
     *
     * @param  array<string, mixed>  $row
     * @return bool
     */
    private function isVisible(array $row): bool
    {
        if ($this->manageAll) {
            return true;
        }

        $owner = $row['id_employee'] ?? null;

        return $owner !== null && (int) $owner === $this->actingEmployeeId;
    }

    /**
     * Load the message history for a conversation, ordered by created_at ASC, id ASC.
     *
     * @param  string  $key  Conversation id.
     * @return array<int, array<string, mixed>>
     */
    private function loadHistory(string $key): array
    {
        $rows = $this->db->query(
            "SELECT role, content, tool_name, tool_input
             FROM `{$this->tablePrefix}phpclaw_messages`
             WHERE conversation_id = ?
             ORDER BY created_at ASC, id ASC",
            [$key],
        )->rows;

        return array_map($this->messageRowToEntry(...), $rows);
    }

    /**
     * Map a raw conversation row into the engine-facing data structure.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function rowToConversation(array $row): array
    {
        return [
            'id' => $row['id'],
            'namespace' => $row['namespace'],
            'title' => $row['title'],
            'metadata' => $this->decodeJson($row['metadata'] ?? null),
            'created_at' => $row['created_at'],
            'history' => [],
        ];
    }

    /**
     * Map a raw `{prefix}phpclaw_messages` row into a history entry.
     *
     * @param  array<string, mixed>  $msg
     * @return array<string, mixed>
     */
    private function messageRowToEntry(array $msg): array
    {
        $entry = [
            'role' => $msg['role'],
            'content' => $msg['content'],
            'tool_name' => $msg['tool_name'] ?? null,
        ];

        if (isset($msg['tool_input'])) {
            $decoded = json_decode((string) $msg['tool_input'], associative: true);
            $entry['tool_input'] = is_array($decoded) ? $decoded : null;
        }

        return $entry;
    }

    /**
     * Map a conversation row into the summary shape returned by `all()`.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function summaryRow(array $row): array
    {
        return [
            'id' => $row['id'],
            'title' => $row['title'],
            'metadata' => $this->decodeJson($row['metadata'] ?? null),
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    /**
     * Insert a conversation row, or update it in place leaving id_employee untouched so the owner stamped at INSERT survives every later continuation.
     *
     * @param  string  $key
     * @param  string  $namespace
     * @param  ?string  $title
     * @param  string|null  $metadata  JSON-encoded metadata blob, or null.
     * @param  string  $now  Current timestamp string.
     * @param  string  $createdAt  Original creation timestamp.
     * @return void
     */
    private function upsertConversation(
        string $key,
        string $namespace,
        ?string $title,
        ?string $metadata,
        string $now,
        string $createdAt,
    ): void {
        $this->db->query(
            "INSERT INTO `{$this->tablePrefix}phpclaw_conversations`
             (id, namespace, id_employee, title, metadata, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE namespace = VALUES(namespace), title = VALUES(title),
                                      metadata = VALUES(metadata), updated_at = VALUES(updated_at)",
            [$key, $namespace, $this->actingEmployeeId, $title, $metadata, $createdAt, $now],
        );
    }

    /**
     * Replace the entire message history for a conversation: delete the old rows then re-insert in order.
     *
     * @param  string  $key
     * @param  array<int, mixed>  $history
     * @param  string  $now
     * @return void
     */
    private function replaceMessages(string $key, array $history, string $now): void
    {
        $this->deleteMessagesByConversation($key);

        foreach ($history as $message) {
            if (! is_array($message)) {
                continue;
            }

            $role = (string) ($message['role'] ?? self::ROLE_USER);

            if ($role === self::ROLE_TOOL_BATCH) {
                $this->insertToolBatchMessage($key, $message, $now);

                continue;
            }

            if (! in_array($role, self::PERSISTED_ROLES, strict: true)) {
                continue;
            }

            $this->insertMessage($key, $role, $message, $now);
        }
    }

    /**
     * Collapse a tool_batch message into a single `tool` row with tool names joined and tool_input JSON-encoded.
     *
     * @param  string  $key
     * @param  array<string, mixed>  $message
     * @param  string  $now
     * @return void
     */
    private function insertToolBatchMessage(string $key, array $message, string $now): void
    {
        $calls = is_array($message['batch_calls'] ?? null) ? $message['batch_calls'] : [];
        $results = is_array($message['batch_results'] ?? null) ? $message['batch_results'] : [];

        if ($calls === []) {
            return;
        }

        $toolNames = array_column($calls, 'tool_name');
        $toolInputs = array_column($calls, 'tool_input');

        $this->db->query(
            "INSERT INTO `{$this->tablePrefix}phpclaw_messages`
             (id, conversation_id, role, content, tool_name, tool_input, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                Ulid::generate(),
                $key,
                self::ROLE_TOOL,
                (string) json_encode($results, self::JSON_FLAGS),
                implode(', ', $toolNames),
                (string) json_encode($toolInputs, self::JSON_FLAGS),
                $now,
            ],
        );
    }

    /**
     * Insert one user / assistant / tool message row.
     *
     * @param  string  $key
     * @param  string  $role
     * @param  array<string, mixed>  $message
     * @param  string  $now
     * @return void
     */
    private function insertMessage(string $key, string $role, array $message, string $now): void
    {
        $toolInput = isset($message['tool_input'])
            ? (string) json_encode($message['tool_input'], self::JSON_FLAGS)
            : null;

        $this->db->query(
            "INSERT INTO `{$this->tablePrefix}phpclaw_messages`
             (id, conversation_id, role, content, tool_name, tool_input, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                Ulid::generate(),
                $key,
                $role,
                $message['content'] ?? null,
                $message['tool_name'] ?? null,
                $toolInput,
                $now,
            ],
        );
    }

    /**
     * Delete every message row attached to the given conversation id.
     *
     * @param  string  $key
     * @return void
     */
    private function deleteMessagesByConversation(string $key): void
    {
        $this->db->query(
            "DELETE FROM `{$this->tablePrefix}phpclaw_messages` WHERE conversation_id = ?",
            [$key],
        );
    }

    /**
     * Resolve the title for a conversation row: caller-supplied wins, otherwise auto-extract from the first user message.
     *
     * @param  array<string, mixed>  $value
     * @param  array<int, mixed>  $history
     * @return ?string
     */
    private function resolveTitle(array $value, array $history): ?string
    {
        if (isset($value['title']) && is_string($value['title']) && $value['title'] !== '') {
            return $value['title'];
        }

        return $this->extractTitleFromHistory($history);
    }

    /**
     * Extract a title from the first user message, truncated to TITLE_MAX_LENGTH.
     *
     * @param  array<int, mixed>  $history
     * @return ?string
     */
    private function extractTitleFromHistory(array $history): ?string
    {
        foreach ($history as $msg) {
            if (! is_array($msg) || ($msg['role'] ?? '') !== self::ROLE_USER || empty($msg['content'])) {
                continue;
            }

            $content = (string) $msg['content'];

            return mb_strlen($content) > self::TITLE_MAX_LENGTH
                ? mb_substr($content, 0, self::TITLE_MAX_LENGTH).self::TITLE_ELLIPSIS
                : $content;
        }

        return null;
    }

    /**
     * Encode the metadata array to a JSON string, null when not an array.
     *
     * @param  mixed  $metadata
     * @return ?string
     */
    private function encodeMetadata(mixed $metadata): ?string
    {
        return is_array($metadata) ? json_encode($metadata, self::JSON_FLAGS) : null;
    }

    /**
     * Null-safe JSON decode that defaults to an empty array on any failure.
     *
     * @param  mixed  $raw
     * @return array<string, mixed>
     */
    private function decodeJson(mixed $raw): array
    {
        $decoded = json_decode((string) ($raw ?? ''), associative: true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Determine whether message content should be persisted.
     *
     * @return bool
     */
    private function resolveStoreMessages(): bool
    {
        return $this->storeMessages ?? true;
    }

    /**
     * Run $work, wrapping any non-MemoryException Throwable in a MemoryException tagged with the operation name.
     *
     * @template T
     *
     * @param  string  $op
     * @param  callable(): T  $work
     * @return T
     */
    private function guard(string $op, callable $work): mixed
    {
        try {
            return $work();
        } catch (ConversationAccessDeniedException|MemoryException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new MemoryException(
                "PsDbConversationMemory::{$op} failed: {$e->getMessage()}",
                previous: $e,
            );
        }
    }
}

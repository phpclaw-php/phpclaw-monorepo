<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Component\Administrator\Memory;

use Joomla\Database\DatabaseInterface;
use PhpClaw\Exceptions\MemoryException;
use PhpClaw\Hooks\HookDispatcher;
use PhpClaw\Joomla\Component\Administrator\Database\PhpClawTables;
use PhpClaw\Joomla\Component\Administrator\Exceptions\ConversationAccessDeniedException;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Support\Ulid;

/**
 * DB-backed conversation memory driver for Joomla.
 */
final class JoomlaDbConversationMemory implements MemoryInterface
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    private const DEFAULT_NAMESPACE = 'default';

    private const DRIVER_NAME = 'joomla_db_conv';

    private const TITLE_MAX_LENGTH = 60;

    private const TITLE_ELLIPSIS = "\u{2026}";

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    private const ROLE_USER = 'user';

    private const ROLE_ASSISTANT = 'assistant';

    private const ROLE_TOOL = 'tool';

    private const ROLE_TOOL_BATCH = 'tool_batch';

    private const PERSISTED_ROLES = [self::ROLE_USER, self::ROLE_ASSISTANT, self::ROLE_TOOL];

    private const MESSAGE_COLUMNS = [
        'id', 'conversation_id', 'role', 'content',
        'tool_name', 'tool_input', 'created_at',
    ];

    private const CONVERSATION_COLUMNS = [
        'id', 'namespace', 'user_id', 'title', 'metadata',
        'created_at', 'updated_at',
    ];

    private const UNOWNED_USER_ID = 0;

    /**
     * Bind the Joomla database connection, the message-persistence flag, and the acting
     * identity that scopes every read and write.
     *
     * @param  DatabaseInterface  $db  Joomla database connection.
     * @param  bool  $storeMessages  Whether to persist message content.
     * @param  int  $actingUserId  Owner id stamped on writes and matched on reads.
     * @param  bool  $manageAll  Whether the acting user may reach every owner's records.
     */
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly bool $storeMessages = true,
        private readonly int $actingUserId = self::UNOWNED_USER_ID,
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
        return $this->guard('get', function () use ($key, $namespace): ?array {
            $row = $this->loadConversationRow($key, $namespace);

            HookDispatcher::memoryRead($key, $namespace, self::DRIVER_NAME, hit: $row !== null);

            if ($row === null) {
                return null;
            }

            $this->assertOwnership($row);

            $data = self::rowToConversation($row);

            if ($this->storeMessages) {
                $data['history'] = $this->loadHistory($key);
            }

            return $data;
        });
    }

    /**
     * Return summary records for the acting user's conversations in the given namespace, or
     * for every user's when the caller holds manage-all rights.
     *
     * @param  string  $namespace
     * @return array<string, mixed>
     *
     * @throws MemoryException
     */
    public function all(string $namespace = self::DEFAULT_NAMESPACE): array
    {
        return $this->guard('all', function () use ($namespace): array {
            $query = $this->db->getQuery(true)
                ->select(['id', 'title', 'metadata', 'created_at', 'updated_at'])
                ->from($this->db->quoteName(PhpClawTables::CONVERSATIONS_TABLE))
                ->where($this->db->quoteName('namespace').' = '.$this->db->quote($namespace))
                ->order($this->db->quoteName('updated_at').' DESC');

            if (! $this->manageAll) {
                $query->where($this->db->quoteName('user_id').' = '.(int) $this->actingUserId);
            }

            $rows = $this->db->setQuery($query)->loadAssocList() ?? [];

            $result = [];
            foreach ($rows as $row) {
                $result[(string) $row['id']] = self::summaryRow($row);
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
        return $this->guard('has', function () use ($key, $namespace): bool {
            $query = $this->db->getQuery(true)
                ->select('COUNT(*)')
                ->from($this->db->quoteName(PhpClawTables::CONVERSATIONS_TABLE))
                ->where($this->db->quoteName('id').' = '.$this->db->quote($key))
                ->where($this->db->quoteName('namespace').' = '.$this->db->quote($namespace));

            if (! $this->manageAll) {
                $query->where($this->db->quoteName('user_id').' = '.(int) $this->actingUserId);
            }

            return ((int) $this->db->setQuery($query)->loadResult()) > 0;
        });
    }

    /**
     * Persist a conversation record and its message history.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @param  string  $namespace
     * @param  int|null  $ttl  Unused - conversations do not expire.
     * @return void
     *
     * @throws MemoryException
     */
    public function set(string $key, mixed $value, string $namespace = self::DEFAULT_NAMESPACE, ?int $ttl = null): void
    {
        if (! is_array($value)) {
            throw new MemoryException('JoomlaDbConversationMemory::set expects an array value.');
        }

        if (! $this->storeMessages) {
            HookDispatcher::memoryWrite($key, $namespace, self::DRIVER_NAME, $ttl);

            return;
        }

        $this->guard('set', function () use ($key, $value, $namespace): void {
            $history = (array) ($value['history'] ?? []);
            $metadata = self::encodeMetadata($value['metadata'] ?? null);
            $title = $this->resolveTitle($value, $history);
            $now = date(self::DATETIME_FORMAT);

            $this->upsertConversation($key, $namespace, $title, $metadata, $now, (string) ($value['created_at'] ?? $now));

            if ($history !== []) {
                $this->replaceMessages($key, $history, $now);
            }
        });

        HookDispatcher::memoryWrite($key, $namespace, self::DRIVER_NAME, $ttl);
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
        $this->guard('forget', function () use ($key, $namespace): void {
            $row = $this->loadConversationRow($key, $namespace);

            if ($row !== null) {
                $this->assertOwnership($row);
            }

            $this->deleteMessagesByConversation($key);

            $del = $this->db->getQuery(true)
                ->delete($this->db->quoteName(PhpClawTables::CONVERSATIONS_TABLE))
                ->where($this->db->quoteName('id').' = '.$this->db->quote($key))
                ->where($this->db->quoteName('namespace').' = '.$this->db->quote($namespace));

            $this->db->setQuery($del)->execute();
        });

        HookDispatcher::memoryForget($key, $namespace, self::DRIVER_NAME);
    }

    /**
     * Delete the acting user's conversations and their messages in the given namespace, or
     * every user's when the caller holds manage-all rights.
     *
     * @param  string  $namespace
     * @return void
     *
     * @throws MemoryException
     */
    public function flush(string $namespace = self::DEFAULT_NAMESPACE): void
    {
        $this->guard('flush', function () use ($namespace): void {
            $idsQuery = $this->db->getQuery(true)
                ->select('id')
                ->from($this->db->quoteName(PhpClawTables::CONVERSATIONS_TABLE))
                ->where($this->db->quoteName('namespace').' = '.$this->db->quote($namespace));

            if (! $this->manageAll) {
                $idsQuery->where($this->db->quoteName('user_id').' = '.(int) $this->actingUserId);
            }

            $convIds = $this->db->setQuery($idsQuery)->loadColumn() ?? [];

            if ($convIds !== []) {
                $quoted = implode(',', array_map(fn (mixed $id): string => $this->db->quote((string) $id), $convIds));
                $delMsg = $this->db->getQuery(true)
                    ->delete($this->db->quoteName(PhpClawTables::MESSAGES_TABLE))
                    ->where($this->db->quoteName('conversation_id').' IN ('.$quoted.')');
                $this->db->setQuery($delMsg)->execute();
            }

            $delConv = $this->db->getQuery(true)
                ->delete($this->db->quoteName(PhpClawTables::CONVERSATIONS_TABLE))
                ->where($this->db->quoteName('namespace').' = '.$this->db->quote($namespace));

            if (! $this->manageAll) {
                $delConv->where($this->db->quoteName('user_id').' = '.(int) $this->actingUserId);
            }

            $this->db->setQuery($delConv)->execute();
        });
    }

    /**
     * Assert the acting user owns the given conversation row, unless the caller was constructed
     * with manage-all rights.
     *
     * @param  array<string, mixed>  $row  Row loaded from the conversations table.
     * @return void
     *
     * @throws ConversationAccessDeniedException
     */
    private function assertOwnership(array $row): void
    {
        if ($this->manageAll) {
            return;
        }

        $owner = $row['user_id'] ?? null;

        if ($owner === null || (int) $owner !== $this->actingUserId) {
            throw new ConversationAccessDeniedException('Access denied.');
        }
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
        $query = $this->db->getQuery(true)
            ->select(['id', 'namespace', 'user_id', 'title', 'metadata', 'created_at'])
            ->from($this->db->quoteName(PhpClawTables::CONVERSATIONS_TABLE))
            ->where($this->db->quoteName('id').' = '.$this->db->quote($key))
            ->where($this->db->quoteName('namespace').' = '.$this->db->quote($namespace));

        return $this->db->setQuery($query)->loadAssoc();
    }

    /**
     * Load the message history for a conversation, ordered by created_at ASC then id ASC.
     * created_at is DATETIME, so the id tiebreaker is what preserves turn order on reload.
     *
     * @param  string  $key
     * @return array<int, array<string, mixed>>
     */
    private function loadHistory(string $key): array
    {
        $query = $this->db->getQuery(true)
            ->select(['role', 'content', 'tool_name', 'tool_input'])
            ->from($this->db->quoteName(PhpClawTables::MESSAGES_TABLE))
            ->where($this->db->quoteName('conversation_id').' = '.$this->db->quote($key))
            ->order($this->db->quoteName('created_at').' ASC, '.$this->db->quoteName('id').' ASC');

        $messages = $this->db->setQuery($query)->loadAssocList() ?? [];

        return array_map(self::messageRowToEntry(...), $messages);
    }

    /**
     * Map a raw conversation row into the engine-facing data structure.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function rowToConversation(array $row): array
    {
        return [
            'id' => $row['id'],
            'namespace' => $row['namespace'],
            'title' => $row['title'],
            'metadata' => self::decodeJson($row['metadata'] ?? null),
            'created_at' => $row['created_at'],
            'history' => [],
        ];
    }

    /**
     * Map a raw `#__phpclaw_messages` row into a history entry.
     *
     * @param  array<string, mixed>  $msg
     * @return array<string, mixed>
     */
    private static function messageRowToEntry(array $msg): array
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
    private static function summaryRow(array $row): array
    {
        return [
            'id' => $row['id'],
            'title' => $row['title'],
            'metadata' => self::decodeJson($row['metadata'] ?? null),
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    /**
     * Insert a conversation row or update an existing one in place.
     *
     * @param  string  $key
     * @param  string  $namespace
     * @param  ?string  $title
     * @param  ?string  $metadata
     * @param  string  $now
     * @param  string  $createdAt
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
        $existingRow = $this->loadConversationRow($key, $namespace);

        if ($existingRow === null) {
            $this->insertConversation($key, $namespace, $title, $metadata, $createdAt, $now);

            return;
        }

        $this->assertOwnership($existingRow);
        $this->updateConversation($key, $namespace, $title, $metadata, $now);
    }

    /**
     * Update an existing conversation row in place.
     *
     * @param  string  $key
     * @param  string  $namespace
     * @param  ?string  $title
     * @param  string|null  $metadata  JSON-encoded metadata blob, or null.
     * @param  string  $now  Current timestamp string used for `updated_at`.
     * @return void
     */
    private function updateConversation(
        string $key,
        string $namespace,
        ?string $title,
        ?string $metadata,
        string $now,
    ): void {
        $update = $this->db->getQuery(true)
            ->update($this->db->quoteName(PhpClawTables::CONVERSATIONS_TABLE))
            ->set($this->db->quoteName('namespace').' = '.$this->db->quote($namespace))
            ->set($this->db->quoteName('metadata').' = '.$this->db->quote((string) $metadata))
            ->set($this->db->quoteName('updated_at').' = '.$this->db->quote($now))
            ->where($this->db->quoteName('id').' = '.$this->db->quote($key));

        if ($title !== null && $title !== '') {
            $update->set($this->db->quoteName('title').' = '.$this->db->quote($title));
        }

        $this->db->setQuery($update)->execute();
    }

    /**
     * Insert a new conversation row.
     *
     * @param  string  $key
     * @param  string  $namespace
     * @param  ?string  $title
     * @param  string|null  $metadata  JSON-encoded metadata blob, or null.
     * @param  string  $createdAt  Original creation timestamp.
     * @param  string  $now  Current timestamp used for `updated_at`.
     * @return void
     */
    private function insertConversation(
        string $key,
        string $namespace,
        ?string $title,
        ?string $metadata,
        string $createdAt,
        string $now,
    ): void {
        $insert = $this->db->getQuery(true)
            ->insert($this->db->quoteName(PhpClawTables::CONVERSATIONS_TABLE))
            ->columns(self::CONVERSATION_COLUMNS)
            ->values(implode(',', [
                $this->db->quote($key),
                $this->db->quote($namespace),
                (string) $this->actingUserId,
                $this->db->quote((string) $title),
                $this->db->quote((string) $metadata),
                $this->db->quote($createdAt),
                $this->db->quote($now),
            ]));

        $this->db->setQuery($insert)->execute();
    }

    /**
     * Replace the entire message history for a conversation.
     *
     * @param  string  $key
     * @param  array<int, mixed>  $history
     * @param  string  $now
     * @return void
     */
    private function replaceMessages(string $key, array $history, string $now): void
    {
        $this->db->transactionStart();

        try {
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

            $this->db->transactionCommit();
        } catch (\Throwable $e) {
            $this->db->transactionRollback();
            throw $e;
        }
    }

    /**
     * Collapse a tool_batch message into a single `tool` row.
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

        $insert = $this->db->getQuery(true)
            ->insert($this->db->quoteName(PhpClawTables::MESSAGES_TABLE))
            ->columns(self::MESSAGE_COLUMNS)
            ->values(implode(',', [
                $this->db->quote(Ulid::generate()),
                $this->db->quote($key),
                $this->db->quote(self::ROLE_TOOL),
                $this->db->quote((string) json_encode($results, self::JSON_FLAGS)),
                $this->db->quote(implode(', ', $toolNames)),
                $this->db->quote((string) json_encode($toolInputs, self::JSON_FLAGS)),
                $this->db->quote($now),
            ]));

        $this->db->setQuery($insert)->execute();
    }

    /**
     * Insert one user/assistant/tool message row.
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
            ? (string) json_encode($message['tool_input'])
            : '';

        $insert = $this->db->getQuery(true)
            ->insert($this->db->quoteName(PhpClawTables::MESSAGES_TABLE))
            ->columns(self::MESSAGE_COLUMNS)
            ->values(implode(',', [
                $this->db->quote(Ulid::generate()),
                $this->db->quote($key),
                $this->db->quote($role),
                $this->db->quote((string) ($message['content'] ?? '')),
                $this->db->quote((string) ($message['tool_name'] ?? '')),
                $this->db->quote($toolInput),
                $this->db->quote($now),
            ]));

        $this->db->setQuery($insert)->execute();
    }

    /**
     * Delete every message row attached to the given conversation id.
     *
     * @param  string  $key
     * @return void
     */
    private function deleteMessagesByConversation(string $key): void
    {
        $del = $this->db->getQuery(true)
            ->delete($this->db->quoteName(PhpClawTables::MESSAGES_TABLE))
            ->where($this->db->quoteName('conversation_id').' = '.$this->db->quote($key));

        $this->db->setQuery($del)->execute();
    }

    /**
     * Resolve the title for a conversation row.
     *
     * @param  array<string, mixed>  $value
     * @param  array<int, mixed>  $history
     * @return ?string
     */
    private function resolveTitle(array $value, array $history): ?string
    {
        if (isset($value['title']) && is_string($value['title'])) {
            return $value['title'];
        }

        return $this->storeMessages ? self::extractTitleFromHistory($history) : null;
    }

    /**
     * Extract a title from the first user message, truncated to TITLE_MAX_LENGTH.
     *
     * @param  array<int, mixed>  $history
     * @return ?string
     */
    private static function extractTitleFromHistory(array $history): ?string
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
    private static function encodeMetadata(mixed $metadata): ?string
    {
        return is_array($metadata) ? json_encode($metadata) : null;
    }

    /**
     * Null-safe JSON decode that defaults to an empty array on any failure.
     *
     * @param  mixed  $raw
     * @return array<string, mixed>
     */
    private static function decodeJson(mixed $raw): array
    {
        $decoded = json_decode((string) ($raw ?? ''), associative: true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Run $work, wrapping any non-MemoryException Throwable in a MemoryException.
     *
     * @template T
     *
     * @param  string  $op  Operation name used in the wrapped error message.
     * @param  callable(): T  $work
     * @return T
     *
     * @throws ConversationAccessDeniedException
     * @throws MemoryException
     */
    private function guard(string $op, callable $work): mixed
    {
        try {
            return $work();
        } catch (ConversationAccessDeniedException|MemoryException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new MemoryException(
                "JoomlaDbConversationMemory::{$op} failed: {$e->getMessage()}",
                previous: $e,
            );
        }
    }
}

<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Memory;

use Doctrine\DBAL\Connection;
use PhpClaw\Exceptions\MemoryException;
use PhpClaw\Support\Ulid;
use PhpClaw\Symfony\Contracts\AssertsConversationAccess;
use PhpClaw\Symfony\Exceptions\ConversationAccessDeniedException;
use PhpClaw\Symfony\SymfonyIdentityResolver;

/**
 * DBAL-backed conversation memory storing history to phpclaw_conversations and phpclaw_messages.
 */
final class DoctrineConversationMemory extends AbstractDoctrineMemory implements AssertsConversationAccess
{
    public const CONVERSATIONS = 'phpclaw_conversations';

    public const MESSAGES = 'phpclaw_messages';

    /**
     * Constructs the conversation memory driver with the given DBAL connection and message storage flag.
     *
     * @param  Connection  $connection  Doctrine DBAL connection for the phpclaw_conversations/messages tables.
     * @param  bool  $storeMessages  When true, message history is persisted to phpclaw_messages.
     * @param  SymfonyIdentityResolver|null  $identity  Names the acting user for ownership scoping; null leaves rows unowned.
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly bool $storeMessages = true,
        private readonly ?SymfonyIdentityResolver $identity = null,
    ) {}

    /**
     * The acting user identifier, or an empty string when there is no logged-in user.
     *
     * @return string
     */
    private function actingUserId(): string
    {
        return $this->identity?->actingUserId() ?? '';
    }

    /**
     * Whether the acting user may reach every user's conversations.
     *
     * @return bool
     */
    private function manageAll(): bool
    {
        return $this->identity?->manageAll() ?? false;
    }

    /**
     * Whether the acting user may reach the given conversation row.
     *
     * @param  array<string, mixed>  $row  Row from phpclaw_conversations.
     * @return bool
     */
    private function isVisible(array $row): bool
    {
        if ($this->manageAll()) {
            return true;
        }

        return (string) ($row['user_id'] ?? '') === $this->actingUserId();
    }

    /**
     * Assert the acting user may reach this conversation, before any response is committed.
     *
     * @param  string  $key  Conversation ULID.
     * @param  string  $namespace  Scoping namespace.
     * @return void
     */
    public function assertAccess(string $key, string $namespace = 'conversations'): void
    {
        $this->assertWritable($key, $namespace);
    }

    /**
     * Throw when the conversation exists and belongs to a different user.
     *
     * @param  string  $key  Conversation ULID.
     * @param  string  $namespace  Scoping namespace.
     * @return void
     */
    private function assertWritable(string $key, string $namespace): void
    {
        if ($this->manageAll()) {
            return;
        }

        $owner = $this->connection->fetchOne(
            'SELECT user_id FROM '.self::CONVERSATIONS.' WHERE id = ? AND namespace = ?',
            [$key, $namespace],
        );

        if ($owner !== false && (string) $owner !== $this->actingUserId()) {
            throw new ConversationAccessDeniedException;
        }
    }

    /**
     * Owner predicate appended to list queries, empty for the manage-all tier.
     *
     * @return string
     */
    private function ownerSql(): string
    {
        return $this->manageAll() ? '' : ' AND user_id = ?';
    }

    /**
     * Append the acting identifier to a bind list when the owner predicate is in play.
     *
     * @param  list<mixed>  $bind  Existing positional bind values.
     * @return list<mixed>
     */
    private function ownerBind(array $bind): array
    {
        if (! $this->manageAll()) {
            $bind[] = $this->actingUserId();
        }

        return $bind;
    }

    /**
     * Retrieves a conversation record with optional message history.
     *
     * @param  string  $key
     * @param  string  $namespace
     * @return mixed
     *
     * @throws MemoryException
     */
    public function get(string $key, string $namespace = 'default'): mixed
    {
        return $this->guard('DoctrineConversationMemory::get', function () use ($key, $namespace): mixed {
            $row = $this->connection->fetchAssociative(
                'SELECT * FROM '.self::CONVERSATIONS.' WHERE id = ? AND namespace = ?',
                [$key, $namespace],
            );

            if ($row === false) {
                return null;
            }

            if (! $this->isVisible($row)) {
                throw new ConversationAccessDeniedException;
            }

            $data = [
                'id' => (string) $row['id'],
                'namespace' => (string) $row['namespace'],
                'title' => $row['title'],
                'metadata' => $row['metadata'] !== null ? json_decode((string) $row['metadata'], true) : [],
                'created_at' => (string) $row['created_at'],
                'history' => [],
            ];

            if ($this->storeMessages) {
                $data['history'] = $this->readHistory($key);
            }

            return $data;
        });
    }

    /**
     * Upserts a conversation record and optionally rewrites its message history.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @param  string  $namespace
     * @param  ?int  $ttl
     * @return void
     *
     * @throws MemoryException
     */
    public function set(string $key, mixed $value, string $namespace = 'default', ?int $ttl = null): void
    {
        if (! is_array($value)) {
            throw new MemoryException('DoctrineConversationMemory::set expects an array value.');
        }

        $this->guard('DoctrineConversationMemory::set', function () use ($key, $value, $namespace): void {
            $now = $this->utcNow();
            $title = isset($value['title']) && is_string($value['title'])
                ? $value['title']
                : self::extractTitle((array) ($value['history'] ?? []));
            $metadataEncoded = isset($value['metadata']) && is_array($value['metadata'])
                ? json_encode($value['metadata'], JSON_UNESCAPED_UNICODE)
                : false;
            $metadata = $metadataEncoded !== false ? $metadataEncoded : null;

            $this->assertWritable($key, $namespace);

            $existing = $this->connection->fetchOne(
                'SELECT COUNT(*) FROM '.self::CONVERSATIONS.' WHERE id = ? AND namespace = ?',
                [$key, $namespace],
            );

            if ((int) $existing > 0) {
                $this->connection->executeStatement(
                    'UPDATE '.self::CONVERSATIONS.' SET title = ?, metadata = ?, updated_at = ? WHERE id = ? AND namespace = ?'.$this->ownerSql(),
                    $this->ownerBind([$title, $metadata, $now, $key, $namespace]),
                );
            } else {
                $this->connection->executeStatement(
                    'INSERT INTO '.self::CONVERSATIONS.' (id, namespace, user_id, title, metadata, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$key, $namespace, $this->actingUserId(), $title, $metadata, $value['created_at'] ?? $now, $now],
                );
            }

            if ($this->storeMessages && isset($value['history']) && is_array($value['history'])) {
                $this->rewriteHistory($key, $value['history'], $now);
            }
        });
    }

    /**
     * Deletes a conversation and its messages by ID.
     *
     * @param  string  $key
     * @param  string  $namespace
     * @return void
     *
     * @throws MemoryException
     */
    public function forget(string $key, string $namespace = 'default'): void
    {
        $this->guard('DoctrineConversationMemory::forget', function () use ($key, $namespace): void {
            $this->assertWritable($key, $namespace);

            $this->connection->executeStatement(
                'DELETE FROM '.self::CONVERSATIONS.' WHERE id = ? AND namespace = ?'.$this->ownerSql(),
                $this->ownerBind([$key, $namespace]),
            );
        });
    }

    /**
     * Deletes the acting user's conversations and their messages in the given namespace, or
     * every user's for the manage-all tier.
     *
     * @param  string  $namespace
     * @return void
     *
     * @throws MemoryException
     */
    public function flush(string $namespace = 'default'): void
    {
        $this->guard('DoctrineConversationMemory::flush', function () use ($namespace): void {
            $ids = $this->connection->fetchFirstColumn(
                'SELECT id FROM '.self::CONVERSATIONS.' WHERE namespace = ?'.$this->ownerSql(),
                $this->ownerBind([$namespace]),
            );

            if ($ids !== []) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $this->connection->executeStatement(
                    'DELETE FROM '.self::MESSAGES.' WHERE conversation_id IN ('.$placeholders.')',
                    $ids,
                );
            }

            if ($ids !== []) {
                $idPlaceholders = implode(',', array_fill(0, count($ids), '?'));
                $this->connection->executeStatement(
                    'DELETE FROM '.self::CONVERSATIONS.' WHERE id IN ('.$idPlaceholders.')',
                    $ids,
                );
            }
        });
    }

    /**
     * Returns a summary map of the acting user's conversations in the namespace, or every
     * user's for the manage-all tier, without history.
     *
     * @param  string  $namespace
     * @return array<string, mixed>
     *
     * @throws MemoryException
     */
    public function all(string $namespace = 'default'): array
    {
        return (array) $this->guard('DoctrineConversationMemory::all', function () use ($namespace): array {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT id, title, metadata, created_at FROM '.self::CONVERSATIONS.' WHERE namespace = ?'.$this->ownerSql().' ORDER BY created_at, id',
                $this->ownerBind([$namespace]),
            );

            $result = [];
            foreach ($rows as $row) {
                $id = (string) $row['id'];
                $result[$id] = [
                    'id' => $id,
                    'title' => $row['title'],
                    'metadata' => $row['metadata'] !== null ? json_decode((string) $row['metadata'], true) : [],
                    'created_at' => (string) $row['created_at'],
                ];
            }

            return $result;
        });
    }

    /**
     * Returns true if a conversation with the given ID exists in the namespace.
     *
     * @param  string  $key
     * @param  string  $namespace
     * @return bool
     *
     * @throws MemoryException
     */
    public function has(string $key, string $namespace = 'default'): bool
    {
        return (bool) $this->guard('DoctrineConversationMemory::has', function () use ($key, $namespace): bool {
            return (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM '.self::CONVERSATIONS.' WHERE id = ? AND namespace = ?'.$this->ownerSql(),
                $this->ownerBind([$key, $namespace]),
            ) > 0;
        });
    }

    /**
     * Reads all messages for a conversation in chronological order.
     *
     * @param  string  $conversationId
     * @return array<int, array<string, mixed>>
     */
    private function readHistory(string $conversationId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT role, content, tool_name, tool_input FROM '.self::MESSAGES
            .' WHERE conversation_id = ? ORDER BY created_at, id',
            [$conversationId],
        );

        $history = [];
        foreach ($rows as $msg) {
            $history[] = [
                'role' => (string) $msg['role'],
                'content' => $msg['content'],
                'tool_name' => $msg['tool_name'],
                'tool_input' => $msg['tool_input'] !== null ? json_decode((string) $msg['tool_input'], true) : null,
            ];
        }

        return $history;
    }

    /**
     * Deletes and re-inserts all message rows for a conversation.
     *
     * @param  string  $conversationId
     * @param  array<int, array<string, mixed>>  $history
     * @param  string  $now
     * @return void
     */
    private function rewriteHistory(string $conversationId, array $history, string $now): void
    {
        $this->connection->executeStatement(
            'DELETE FROM '.self::MESSAGES.' WHERE conversation_id = ?',
            [$conversationId],
        );

        $baseTs = strtotime($now) ?: time();

        foreach ($history as $i => $msg) {
            if (! is_array($msg)) {
                continue;
            }

            $toolInput = null;
            if (isset($msg['tool_input'])) {
                $encoded = json_encode($msg['tool_input'], JSON_UNESCAPED_UNICODE);
                $toolInput = $encoded !== false ? $encoded : null;
            }

            $this->connection->executeStatement(
                'INSERT INTO '.self::MESSAGES.' (id, conversation_id, role, content, tool_name, tool_input, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    Ulid::generate(),
                    $conversationId,
                    $msg['role'] ?? 'user',
                    $msg['content'] ?? null,
                    $msg['tool_name'] ?? null,
                    $toolInput,
                    gmdate('Y-m-d H:i:s', $baseTs + $i),
                ],
            );
        }
    }

    /**
     * Derives a conversation title from the first user message in history.
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

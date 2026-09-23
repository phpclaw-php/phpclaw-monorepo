<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Memory;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PhpClaw\Hooks\HookDispatcher;
use PhpClaw\Magento\Exception\ConversationAccessDeniedException;
use PhpClaw\Magento\Model\Config;
use PhpClaw\Magento\Model\IdentityResolver;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Support\Ulid;

/**
 * Conversation-aware memory driver for phpClaw on Magento.
 */
// non-final: Magento interceptor required
class ConversationMemory implements MemoryInterface
{
    private const TABLE_CONVERSATIONS = 'phpclaw_conversations';

    private const TABLE_MESSAGES = 'phpclaw_messages';

    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    private const TITLE_MAX_LEN = 80;

    private const DRIVER_NAME = 'magento.conversation';

    private readonly AdapterInterface $connection;

    private readonly string $conversationsTable;

    private readonly string $messagesTable;

    /**
     * Bind the database connection and the acting identity that scopes every read and write.
     *
     * @param  ResourceConnection  $resourceConnection  Magento's DB connection provider used to obtain the adapter and prefixed table names.
     * @param  Config  $config  Module configuration, read for the store-messages switch.
     * @param  IdentityResolver  $identity  Resolves the acting admin user and whether it may reach every conversation.
     * @return void
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        private readonly Config $config,
        private readonly IdentityResolver $identity,
    ) {
        $this->connection = $resourceConnection->getConnection();
        $this->conversationsTable = $resourceConnection->getTableName(self::TABLE_CONVERSATIONS);
        $this->messagesTable = $resourceConnection->getTableName(self::TABLE_MESSAGES);
    }

    /**
     * Return the full conversation structure or null when not found.
     *
     * @param  string  $key  Conversation ULID.
     * @param  string  $namespace  Scoping namespace stored in phpclaw_conversations.namespace.
     * @return array<string, mixed>|null Full conversation array with 'id', 'title', 'created_at', 'metadata', 'history', or null if not found.
     */
    public function get(string $key, string $namespace = 'default'): mixed
    {
        $row = $this->connection->fetchRow(
            "SELECT `id`, `admin_user_id`, `title`, `metadata`, `created_at` FROM `{$this->conversationsTable}`
             WHERE `id` = ? AND `namespace` = ?",
            [$key, $namespace],
        );

        HookDispatcher::memoryRead($key, $namespace, self::DRIVER_NAME, $row !== false);

        if ($row === false) {
            return null;
        }

        if (! $this->isVisible($row)) {
            throw new ConversationAccessDeniedException;
        }

        $msgRows = $this->config->isStoreMessages() ? $this->connection->fetchAll(
            "SELECT `role`, `content`, `tool_name`, `tool_input`
             FROM   `{$this->messagesTable}`
             WHERE  `conversation_id` = ?
             ORDER  BY `created_at` ASC, `id` ASC",
            [$key],
        ) : [];

        return [
            'id' => (string) $row['id'],
            'title' => (string) ($row['title'] ?? ''),
            'created_at' => (string) $row['created_at'],
            'metadata' => $this->decodeMetadata($row['metadata'] ?? ''),
            'history' => $this->hydrateHistory($msgRows ?: []),
        ];
    }

    /**
     * Persist a conversation (Conversation::toArray() structure) to DB.
     *
     * @param  string  $key  Conversation ULID used as the primary key.
     * @param  mixed  $value  Full conversation array with 'id', 'history', 'created_at', and 'metadata'.
     * @param  string  $namespace  Scoping namespace stored in phpclaw_conversations.namespace.
     * @param  int|null  $ttl  Ignored; DB-backed storage has no TTL support.
     * @return void
     */
    public function set(string $key, mixed $value, string $namespace = 'default', ?int $ttl = null): void
    {
        $now = date(self::DATETIME_FORMAT);
        $data = is_array($value) ? $value : [];
        $id = (string) ($data['id'] ?? $key ?: Ulid::generate());
        $this->assertWritable($id, $namespace);
        $history = is_array($data['history'] ?? null) ? $data['history'] : [];
        $title = $this->extractTitle($history);
        $metadata = is_array($data['metadata'] ?? null) && $data['metadata'] !== []
            ? (json_encode($data['metadata']) ?: json_encode(new \stdClass, JSON_FORCE_OBJECT))
            : json_encode(new \stdClass, JSON_FORCE_OBJECT);

        $this->connection->insertOnDuplicate(
            $this->conversationsTable,
            [
                'id' => $id,
                'namespace' => $namespace,
                'admin_user_id' => $this->identity->actingUserId(),
                'title' => $title,
                'metadata' => $metadata,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['title', 'metadata', 'updated_at'],
        );

        $this->connection->beginTransaction();
        try {
            $this->connection->delete($this->messagesTable, ['conversation_id = ?' => $id]);
            foreach ($history as $msg) {
                $role = $msg['role'] ?? 'user';
                if ($role === MessageRole::ToolBatch->value) {
                    $this->insertToolBatch($msg, $id, $now);
                } elseif (MessageRole::tryFrom($role) !== null) {
                    $this->insertStandardRow($msg, $id, $now);
                }
            }
            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }

        HookDispatcher::memoryWrite($id, $namespace, self::DRIVER_NAME, $ttl);
    }

    /**
     * Delete a single conversation (FK cascade removes its messages).
     *
     * @param  string  $key  Conversation ULID to delete.
     * @param  string  $namespace  Scoping namespace used to scope the delete.
     * @return void
     */
    public function forget(string $key, string $namespace = 'default'): void
    {
        $this->assertWritable($key, $namespace);

        $where = [
            'id = ?' => $key,
            'namespace = ?' => $namespace,
        ];

        if (! $this->identity->manageAll()) {
            $where['admin_user_id = ?'] = $this->identity->actingUserId();
        }

        $this->connection->delete($this->conversationsTable, $where);

        HookDispatcher::memoryForget($key, $namespace, self::DRIVER_NAME);
    }

    /**
     * Delete the acting admin's conversations in a namespace, or every admin's when the
     * caller holds the manage-all tier.
     *
     * @param  string  $namespace  Scoping namespace; all matching rows in phpclaw_conversations are removed (FK cascade handles messages).
     * @return void
     */
    public function flush(string $namespace = 'default'): void
    {
        $where = ['namespace = ?' => $namespace];

        if (! $this->identity->manageAll()) {
            $where['admin_user_id = ?'] = $this->identity->actingUserId();
        }

        $this->connection->delete($this->conversationsTable, $where);

        HookDispatcher::memoryForget('*', $namespace, self::DRIVER_NAME);
    }

    /**
     * Return the acting admin's conversations as an id to full-structure map, or every
     * admin's when the caller holds the manage-all tier.
     *
     * @param  string  $namespace  Scoping namespace to query.
     * @return array<string, array<string, mixed>> Map of conversation ULID → full conversation array.
     */
    public function all(string $namespace = 'default'): array
    {
        $rows = $this->connection->fetchAll(
            "SELECT `id` FROM `{$this->conversationsTable}`
             WHERE  `namespace` = ?".$this->ownerSql().'
             ORDER  BY `created_at` ASC, `id` ASC',
            $this->ownerBind([$namespace]),
        );

        $result = [];
        foreach ($rows as $row) {
            $id = (string) $row['id'];
            $conv = $this->get($id, $namespace);
            if ($conv !== null) {
                $result[$id] = $conv;
            }
        }

        return $result;
    }

    /**
     * Return the 50 most-recent conversations with their full message history.
     *
     * @param  string  $namespace  Scoping namespace to query.
     * @return list<array{id: string, title: string|null, created_at: string, messages: list<array{role: string, content: string|null}>}>
     */
    public function listConversations(string $namespace = 'default'): array
    {
        $rows = $this->connection->fetchAll(
            "SELECT `id`, `title`, `created_at`
             FROM   `{$this->conversationsTable}`
             WHERE  `namespace` = ?".$this->ownerSql().'
             ORDER  BY `created_at` DESC
             LIMIT  50',
            $this->ownerBind([$namespace]),
        );

        $storeMessages = $this->config->isStoreMessages();
        $result = [];
        foreach ($rows ?: [] as $r) {
            $id = (string) $r['id'];

            $msgRows = $storeMessages ? $this->connection->fetchAll(
                "SELECT `role`, `content`, `tool_name`, `tool_input`
                 FROM   `{$this->messagesTable}`
                 WHERE  `conversation_id` = ?
                 ORDER  BY `created_at` ASC, `id` ASC",
                [$id],
            ) : [];

            $result[] = [
                'id' => $id,
                'title' => $r['title'] ?? null,
                'created_at' => (string) $r['created_at'],
                'messages' => array_map(static function (array $m): array {
                    $out = [
                        'role' => (string) $m['role'],
                        'content' => $m['content'] !== null ? (string) $m['content'] : '',
                    ];
                    if ($m['role'] === 'tool') {
                        $out['tool_name'] = (string) ($m['tool_name'] ?? '');
                        $decoded = json_decode((string) ($m['tool_input'] ?? ''), true);
                        $out['tool_input'] = is_array($decoded) ? $decoded : [];
                    }

                    return $out;
                }, $msgRows ?: []),
            ];
        }

        return $result;
    }

    /**
     * Whether a conversation exists.
     *
     * @param  string  $key  Conversation ULID to check.
     * @param  string  $namespace  Scoping namespace to look up.
     * @return bool True when the conversation row exists, false otherwise.
     */
    public function has(string $key, string $namespace = 'default'): bool
    {
        try {
            return $this->get($key, $namespace) !== null;
        } catch (ConversationAccessDeniedException) {
            return false;
        }
    }

    /**
     * Whether the acting admin may reach the given conversation row.
     *
     * @param  array<string, mixed>  $row  Row from phpclaw_conversations.
     * @return bool
     */
    private function isVisible(array $row): bool
    {
        if ($this->identity->manageAll()) {
            return true;
        }

        return (int) ($row['admin_user_id'] ?? 0) === $this->identity->actingUserId();
    }

    /**
     * Throw when the conversation exists and belongs to a different admin.
     *
     * @param  string  $key  Conversation ULID.
     * @param  string  $namespace  Scoping namespace.
     * @return void
     */
    private function assertWritable(string $key, string $namespace): void
    {
        if ($this->identity->manageAll()) {
            return;
        }

        $owner = $this->connection->fetchOne(
            "SELECT `admin_user_id` FROM `{$this->conversationsTable}`
             WHERE `id` = ? AND `namespace` = ?",
            [$key, $namespace],
        );

        if ($owner !== false && (int) $owner !== $this->identity->actingUserId()) {
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
        return $this->identity->manageAll() ? '' : ' AND `admin_user_id` = ?';
    }

    /**
     * Append the acting admin ID to a bind list when the owner predicate is in play.
     *
     * @param  list<mixed>  $bind  Existing positional bind values.
     * @return list<mixed>
     */
    private function ownerBind(array $bind): array
    {
        if (! $this->identity->manageAll()) {
            $bind[] = $this->identity->actingUserId();
        }

        return $bind;
    }

    /**
     * Convert raw message rows into the conversation history structure.
     *
     * @param  array<int, array<string, mixed>>  $msgRows  Rows from phpclaw_messages.
     * @return list<array<string, mixed>> History entries, with tool_input JSON-decoded.
     */
    private function hydrateHistory(array $msgRows): array
    {
        return array_map(static function (array $msg): array {
            $out = [
                'role' => $msg['role'],
                'content' => $msg['content'],
                'tool_name' => $msg['tool_name'] ?? null,
            ];
            if (isset($msg['tool_input'])) {
                $decoded = json_decode((string) $msg['tool_input'], true);
                $out['tool_input'] = is_array($decoded) ? $decoded : null;
            }

            return $out;
        }, $msgRows);
    }

    /**
     * Decode a stored metadata JSON blob into an array.
     *
     * @param  mixed  $raw  Raw metadata column value.
     * @return array<string, mixed> Decoded metadata, or an empty array when absent or invalid.
     */
    private function decodeMetadata(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Insert a single tool_batch message as one consolidated DB row.
     *
     * @param  array<string, mixed>  $msg  Conversation history entry with role='tool_batch'.
     * @param  string  $id  Conversation ULID used as the FK.
     * @param  string  $now  Formatted datetime for created_at.
     * @return void
     */
    private function insertToolBatch(array $msg, string $id, string $now): void
    {
        $calls = is_array($msg['batch_calls'] ?? null) ? $msg['batch_calls'] : [];
        $results = is_array($msg['batch_results'] ?? null) ? $msg['batch_results'] : [];

        if ($calls === []) {
            return;
        }

        $toolNames = array_column($calls, 'tool_name');
        $toolInputs = array_column($calls, 'tool_input');

        $this->connection->insert(
            $this->messagesTable,
            [
                'id' => Ulid::generate(),
                'conversation_id' => $id,
                'role' => 'tool',
                'content' => json_encode($results, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'tool_name' => implode(', ', $toolNames),
                'tool_input' => json_encode($toolInputs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
            ],
        );
    }

    /**
     * Insert a standard user / assistant / tool message row.
     *
     * @param  array<string, mixed>  $msg  Conversation history entry.
     * @param  string  $id  Conversation ULID used as the FK.
     * @param  string  $now  Formatted datetime for created_at.
     * @return void
     */
    private function insertStandardRow(array $msg, string $id, string $now): void
    {
        $this->connection->insert(
            $this->messagesTable,
            [
                'id' => Ulid::generate(),
                'conversation_id' => $id,
                'role' => $msg['role'] ?? 'user',
                'content' => isset($msg['content']) ? (string) $msg['content'] : null,
                'tool_name' => $msg['tool_name'] ?? null,
                'tool_input' => isset($msg['tool_input']) ? json_encode($msg['tool_input']) : null,
                'created_at' => $now,
            ],
        );
    }

    /**
     * Derive a conversation title from the first user message in the history.
     *
     * @param  array<int, array<string, mixed>>  $history  Ordered message array from the Conversation::toArray() shape.
     * @return string|null First 80 characters of the first user message, or null when none exists.
     */
    private function extractTitle(array $history): ?string
    {
        foreach ($history as $msg) {
            if (($msg['role'] ?? '') === 'user' && isset($msg['content'])) {
                $raw = (string) $msg['content'];

                return $raw !== '' ? mb_substr($raw, 0, self::TITLE_MAX_LEN) : null;
            }
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Memory;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelInterface;
use PhpClaw\Drupal\DrupalIdentityResolver;
use PhpClaw\Drupal\Exceptions\ConversationAccessDeniedException;
use PhpClaw\Hooks\HookDispatcher;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Support\Ulid;

/**
 * Conversation-aware memory driver for Drupal.
 */
final class DrupalDbConversationMemory implements MemoryInterface
{
    use ConversationTitleTrait;

    private const DRIVER_NAME = 'drupal_db_conversation';

    /**
     * Create a new DrupalDbConversationMemory instance.
     *
     * @param  Connection  $database  The Drupal database connection.
     * @param  ConfigFactoryInterface  $configFactory  Config factory, read for the store-messages switch.
     * @param  LoggerChannelInterface|null  $logger  phpClaw logger channel (nullable for direct construction in tests).
     * @param  int  $actingUserId  Drupal user ID the driver acts as; 0 means no logged-in user.
     * @param  bool  $manageAll  Whether the acting user may reach every user's conversations.
     * @return void
     */
    public function __construct(
        private readonly Connection $database,
        private readonly ConfigFactoryInterface $configFactory,
        private readonly ?LoggerChannelInterface $logger = null,
        private readonly int $actingUserId = 0,
        private readonly bool $manageAll = false,
    ) {}

    /**
     * Build the driver with the acting identity resolved from Drupal's session.
     *
     * @param  Connection  $database
     * @param  ConfigFactoryInterface  $configFactory
     * @param  ?LoggerChannelInterface  $logger
     * @return self
     */
    public static function createScoped(
        Connection $database,
        ConfigFactoryInterface $configFactory,
        ?LoggerChannelInterface $logger = null,
    ): self {
        return new self(
            $database,
            $configFactory,
            $logger,
            DrupalIdentityResolver::actingUserId(),
            DrupalIdentityResolver::manageAll(),
        );
    }

    /**
     * Whether the acting user may reach the given conversation row.
     *
     * @param  array<string, mixed>  $row  Row from phpclaw_conversations.
     * @return bool
     */
    private function isVisible(array $row): bool
    {
        if ($this->manageAll) {
            return true;
        }

        return (int) ($row['user_id'] ?? 0) === $this->actingUserId;
    }

    /**
     * Retrieve a conversation (with full message history) by id and namespace.
     *
     * @param  string  $key  Conversation ULID used as the primary key.
     * @param  string  $namespace  Namespace the conversation belongs to; defaults to 'default'.
     * @return mixed Associative array with id/title/metadata/history, or null if not found.
     */
    public function get(string $key, string $namespace = 'default'): mixed
    {
        try {
            $row = $this->database->query(
                'SELECT id, namespace, user_id, title, metadata, created_at, updated_at
                 FROM {phpclaw_conversations}
                 WHERE id = :id AND namespace = :ns
                 LIMIT 1',
                [':id' => $key, ':ns' => $namespace]
            )->fetchAssoc();
        } catch (\Throwable) {
            return null;
        }

        $hit = (bool) $row;
        HookDispatcher::memoryRead($key, $namespace, self::DRIVER_NAME, $hit);

        if (! $hit) {
            return null;
        }

        if (! $this->isVisible($row)) {
            throw new ConversationAccessDeniedException;
        }

        $data = [
            'id' => $row['id'],
            'namespace' => $row['namespace'],
            'title' => $row['title'],
            'metadata' => json_decode((string) ($row['metadata'] ?? '[]'), true) ?: [],
            'created_at' => gmdate('Y-m-d H:i:s', (int) $row['created_at']),
            'history' => [],
        ];

        $storeMessages = (bool) ($this->configFactory->get('phpclaw.settings')->get('store_messages') ?? true);

        try {
            $messages = [];

            if ($storeMessages) {
                $stmt = $this->database->query(
                    'SELECT role, content, tool_name, tool_input
                     FROM {phpclaw_messages}
                     WHERE conversation_id = :cid
                     ORDER BY created_at ASC, id ASC',
                    [':cid' => $key]
                );
                while ($row = $stmt->fetchAssoc()) {
                    $messages[] = $row;
                }
            }

            foreach ($messages as $msg) {
                $entry = [
                    'role' => $msg['role'],
                    'content' => $msg['content'],
                ];
                if ($msg['tool_name'] !== null) {
                    $entry['tool_name'] = $msg['tool_name'];
                    $decoded = json_decode((string) ($msg['tool_input'] ?? ''), true);
                    $entry['tool_input'] = is_array($decoded) ? $decoded : null;
                }
                $data['history'][] = $entry;
            }
        } catch (\Throwable $e) {
            $this->logger?->error('Conversation read failed: @class', ['@class' => $e::class]);
        }

        return $data;
    }

    /**
     * Store or update a conversation along with its message history.
     *
     * @param  string  $key  Conversation ULID used as the primary key.
     * @param  mixed  $value  Associative array containing 'title', 'metadata', and 'history' entries.
     * @param  string  $namespace  Namespace to store the conversation under; defaults to 'default'.
     * @param  int|null  $ttl  Unused for database-backed storage; passed through to the hook dispatcher.
     * @return void
     */
    public function set(string $key, mixed $value, string $namespace = 'default', ?int $ttl = null): void
    {
        if (! is_array($value)) {
            return;
        }

        $now = time();
        $history = (array) ($value['history'] ?? []);
        $metadata = isset($value['metadata']) && is_array($value['metadata'])
            ? json_encode($value['metadata'])
            : null;

        $title = isset($value['title']) && is_string($value['title'])
            ? $value['title']
            : self::extractTitle($history);

        $transaction = $this->database->startTransaction();

        try {
            $existing = $this->database->query(
                'SELECT user_id FROM {phpclaw_conversations} WHERE id = :id LIMIT 1',
                [':id' => $key]
            )->fetchAssoc();

            if ($existing !== false && $existing !== null && ! $this->isVisible($existing)) {
                throw new ConversationAccessDeniedException;
            }

            if ($existing !== false && $existing !== null) {
                $update = [
                    'namespace' => $namespace,
                    'metadata' => $metadata,
                    'updated_at' => $now,
                ];

                if ($title !== null) {
                    $currentTitle = $this->database->query(
                        'SELECT title FROM {phpclaw_conversations} WHERE id = :id LIMIT 1',
                        [':id' => $key]
                    )->fetchField();

                    if ($currentTitle === null || $currentTitle === '' || $currentTitle === false) {
                        $update['title'] = $title;
                    }
                }

                $this->database->update('phpclaw_conversations')
                    ->fields($update)
                    ->condition('id', $key)
                    ->execute();
            } else {
                $createdAt = isset($value['created_at'])
                    ? self::toTimestamp($value['created_at'])
                    : $now;

                $this->database->insert('phpclaw_conversations')
                    ->fields([
                        'id' => $key,
                        'namespace' => $namespace,
                        'user_id' => $this->actingUserId,
                        'title' => $title,
                        'metadata' => $metadata,
                        'created_at' => $createdAt,
                        'updated_at' => $now,
                    ])
                    ->execute();
            }

            $payloads = self::buildMessagePayloads($history);

            $existingStmt = $this->database->query(
                'SELECT id FROM {phpclaw_messages} WHERE conversation_id = :cid ORDER BY created_at ASC, id ASC',
                [':cid' => $key]
            );
            $existingIds = [];
            while ($row = $existingStmt->fetchAssoc()) {
                $existingIds[] = $row['id'];
            }

            foreach ($payloads as $i => $payload) {
                if (isset($existingIds[$i])) {
                    $this->database->update('phpclaw_messages')
                        ->fields($payload)
                        ->condition('id', $existingIds[$i])
                        ->execute();

                    continue;
                }

                $this->database->insert('phpclaw_messages')
                    ->fields($payload + [
                        'id' => Ulid::generate(),
                        'conversation_id' => $key,
                        'created_at' => $now,
                    ])
                    ->execute();
            }

            $staleIds = array_slice($existingIds, count($payloads));
            if ($staleIds !== []) {
                $this->database->delete('phpclaw_messages')
                    ->condition('id', $staleIds, 'IN')
                    ->execute();
            }
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->logger?->error('Conversation set failed: @class', ['@class' => $e::class]);

            return;
        }

        HookDispatcher::memoryWrite($key, $namespace, self::DRIVER_NAME, $ttl);
    }

    /**
     * Delete a conversation and all its messages.
     *
     * @param  string  $key  Conversation ULID to delete.
     * @param  string  $namespace  Namespace the conversation belongs to; defaults to 'default'.
     * @return void
     */
    public function forget(string $key, string $namespace = 'default'): void
    {
        try {
            $row = $this->database->query(
                'SELECT user_id FROM {phpclaw_conversations} WHERE id = :id AND namespace = :ns LIMIT 1',
                [':id' => $key, ':ns' => $namespace]
            )->fetchAssoc();

            if ($row !== false && $row !== null && ! $this->isVisible($row)) {
                throw new ConversationAccessDeniedException;
            }

            $this->database->delete('phpclaw_messages')
                ->condition('conversation_id', $key)
                ->execute();

            $this->database->delete('phpclaw_conversations')
                ->condition('id', $key)
                ->condition('namespace', $namespace)
                ->execute();
        } catch (ConversationAccessDeniedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger?->error('Conversation forget failed: @class', ['@class' => $e::class]);
        }

        HookDispatcher::memoryForget($key, $namespace, self::DRIVER_NAME);
    }

    /**
     * Delete the acting user's conversations and their messages within a namespace, or every
     * user's when the caller holds the manage-all permission.
     *
     * @param  string  $namespace  Namespace whose conversations and messages will be removed; defaults to 'default'.
     * @return void
     */
    public function flush(string $namespace = 'default'): void
    {
        try {
            $ids = $this->database->query(
                $this->manageAll
                    ? 'SELECT id FROM {phpclaw_conversations} WHERE namespace = :ns'
                    : 'SELECT id FROM {phpclaw_conversations} WHERE namespace = :ns AND user_id = :uid',
                $this->manageAll
                    ? [':ns' => $namespace]
                    : [':ns' => $namespace, ':uid' => $this->actingUserId]
            )->fetchCol();

            if ($ids !== []) {
                $this->database->delete('phpclaw_messages')
                    ->condition('conversation_id', $ids, 'IN')
                    ->execute();

                $this->database->delete('phpclaw_conversations')
                    ->condition('id', $ids, 'IN')
                    ->execute();
            }
        } catch (\Throwable $e) {
            $this->logger?->error('Conversation flush failed: @class', ['@class' => $e::class]);
        }

        HookDispatcher::memoryForget('*', $namespace, self::DRIVER_NAME);
    }

    /**
     * List the acting user's conversations in a namespace, most recently updated first, or
     * every user's when the caller holds the manage-all permission.
     *
     * @param  string  $namespace  Namespace to query; defaults to 'default'.
     * @return array<string, array{id: string, title: string|null, created_at: string, updated_at: string}>
     */
    public function all(string $namespace = 'default'): array
    {
        try {
            $stmt = $this->database->query(
                $this->manageAll
                    ? 'SELECT id, title, metadata, created_at, updated_at
                       FROM {phpclaw_conversations}
                       WHERE namespace = :ns
                       ORDER BY updated_at DESC'
                    : 'SELECT id, title, metadata, created_at, updated_at
                       FROM {phpclaw_conversations}
                       WHERE namespace = :ns AND user_id = :uid
                       ORDER BY updated_at DESC',
                $this->manageAll
                    ? [':ns' => $namespace]
                    : [':ns' => $namespace, ':uid' => $this->actingUserId]
            );
            $rows = [];
            while ($row = $stmt->fetchAssoc()) {
                $rows[] = $row;
            }
        } catch (\Throwable) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['id']] = [
                'id' => $row['id'],
                'title' => $row['title'],
                'created_at' => gmdate('Y-m-d H:i:s', (int) $row['created_at']),
                'updated_at' => gmdate('Y-m-d H:i:s', (int) $row['updated_at']),
            ];
        }

        return $result;
    }

    /**
     * Check whether a conversation exists.
     *
     * @param  string  $key  Conversation ULID to check.
     * @param  string  $namespace  Namespace to search within; defaults to 'default'.
     * @return bool True if the conversation exists, false otherwise.
     */
    public function has(string $key, string $namespace = 'default'): bool
    {
        try {
            $exists = (bool) $this->database->query(
                $this->manageAll
                    ? 'SELECT 1 FROM {phpclaw_conversations} WHERE id = :id AND namespace = :ns LIMIT 1'
                    : 'SELECT 1 FROM {phpclaw_conversations} WHERE id = :id AND namespace = :ns AND user_id = :uid LIMIT 1',
                $this->manageAll
                    ? [':id' => $key, ':ns' => $namespace]
                    : [':id' => $key, ':ns' => $namespace, ':uid' => $this->actingUserId]
            )->fetchField();
        } catch (\Throwable) {
            return false;
        }

        return $exists;
    }

    /**
     * Convert a date value to a Unix timestamp integer for DB storage.
     *
     * @param  mixed  $val  A Y-m-d H:i:s string or Unix timestamp integer.
     * @return int Unix timestamp, or the current time if parsing fails.
     */
    private static function toTimestamp(mixed $val): int
    {
        if (is_int($val) && $val > 0) {
            return $val;
        }
        $ts = strtotime((string) $val);

        return ($ts !== false && $ts > 0) ? $ts : time();
    }

    /**
     * Flatten a history array into row payloads (role/content/tool_name/tool_input), collapsing tool_batch entries into one row each.
     *
     * @param  array<int, mixed>  $history
     * @return list<array{role: string, content: mixed, tool_name: mixed, tool_input: mixed}>
     */
    private static function buildMessagePayloads(array $history): array
    {
        $payloads = [];

        foreach ($history as $msg) {
            $role = $msg['role'] ?? '';

            if ($role === 'tool_batch') {
                $calls = is_array($msg['batch_calls'] ?? null) ? $msg['batch_calls'] : [];
                $results = is_array($msg['batch_results'] ?? null) ? $msg['batch_results'] : [];

                if ($calls === []) {
                    continue;
                }

                $toolNames = array_column($calls, 'tool_name');
                $toolInputs = array_column($calls, 'tool_input');

                $payloads[] = [
                    'role' => 'tool',
                    'content' => json_encode($results, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'tool_name' => implode(', ', $toolNames),
                    'tool_input' => json_encode($toolInputs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ];

                continue;
            }

            if (! in_array($role, ['user', 'assistant', 'tool'], true)) {
                continue;
            }

            $payloads[] = [
                'role' => $role,
                'content' => $msg['content'] ?? null,
                'tool_name' => $msg['tool_name'] ?? null,
                'tool_input' => isset($msg['tool_input']) ? json_encode($msg['tool_input']) : null,
            ];
        }

        return $payloads;
    }
}

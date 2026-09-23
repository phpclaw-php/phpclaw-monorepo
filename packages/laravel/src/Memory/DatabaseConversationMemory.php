<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Memory;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpClaw\Exceptions\MemoryException;
use PhpClaw\Laravel\Exceptions\ConversationAccessDeniedException;
use PhpClaw\Laravel\LaravelIdentityResolver;
use PhpClaw\Support\Ulid;

/**
 * DB-backed conversation memory driver (phpclaw_conversations + phpclaw_messages); message history only when store_messages is true.
 */
final class DatabaseConversationMemory extends AbstractDatabaseMemory
{
    public const CONVERSATIONS_TABLE = 'phpclaw_conversations';

    public const MESSAGES_TABLE = 'phpclaw_messages';

    /**
     * Retrieve a conversation record by ID, including message history when store_messages is enabled.
     *
     * @param  string  $key
     * @param  string  $namespace
     * @return mixed
     */
    public function get(string $key, string $namespace = 'default'): mixed
    {
        return $this->guard('DatabaseConversationMemory::get', function () use ($key, $namespace): mixed {
            $row = DB::table(self::CONVERSATIONS_TABLE)
                ->where('id', $key)
                ->where('namespace', $namespace)
                ->first();

            if ($row === null) {
                return null;
            }

            if (! self::isVisible($row)) {
                throw new ConversationAccessDeniedException;
            }

            $data = [
                'id' => $row->id,
                'namespace' => $row->namespace,
                'title' => $row->title,
                'metadata' => $row->metadata !== null ? json_decode($row->metadata, associative: true) : [],
                'created_at' => $row->created_at,
                'history' => [],
            ];

            if ((bool) config('phpclaw.store_messages', true)) {
                $messages = DB::table(self::MESSAGES_TABLE)
                    ->where('conversation_id', $key)
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->get();

                $history = [];
                foreach ($messages as $msg) {
                    $history[] = [
                        'role' => $msg->role,
                        'content' => $msg->content,
                        'tool_name' => $msg->tool_name,
                        'tool_input' => $msg->tool_input !== null ? json_decode($msg->tool_input, associative: true) : null,
                    ];
                }

                $data['history'] = $history;
            }

            return $data;
        });
    }

    /**
     * Persist a conversation record and, when store_messages is enabled, its message history.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @param  string  $namespace
     * @param  ?int  $ttl
     * @return void
     */
    public function set(string $key, mixed $value, string $namespace = 'default', ?int $ttl = null): void
    {
        $this->guard('DatabaseConversationMemory::set', function () use ($key, $value, $namespace): void {
            if (! is_array($value)) {
                throw new MemoryException('DatabaseConversationMemory::set expects an array value.');
            }

            $metadata = isset($value['metadata']) && is_array($value['metadata'])
                ? json_encode($value['metadata'])
                : null;

            $title = isset($value['title']) && is_string($value['title'])
                ? $value['title']
                : self::extractTitle((array) ($value['history'] ?? []));

            $now = Carbon::now('UTC')->toDateTimeString();

            self::assertWritable($key, $namespace);

            DB::table(self::CONVERSATIONS_TABLE)->upsert(
                values: [
                    [
                        'id' => $key,
                        'namespace' => $namespace,
                        'user_id' => LaravelIdentityResolver::actingUserId(),
                        'title' => $title,
                        'metadata' => $metadata,
                        'created_at' => $value['created_at'] ?? $now,
                        'updated_at' => $now,
                    ],
                ],
                uniqueBy: ['id'],
                update: ['namespace', 'title', 'metadata', 'updated_at'],
            );

            if ((bool) config('phpclaw.store_messages', true) && isset($value['history']) && is_array($value['history'])) {
                DB::table(self::MESSAGES_TABLE)
                    ->where('conversation_id', $key)
                    ->delete();

                $rows = [];
                foreach ($value['history'] as $message) {
                    if (! is_array($message)) {
                        continue;
                    }

                    $rows[] = [
                        'id' => Ulid::generate(),
                        'conversation_id' => $key,
                        'role' => $message['role'] ?? 'user',
                        'content' => $message['content'] ?? null,
                        'tool_name' => $message['tool_name'] ?? null,
                        'tool_input' => isset($message['tool_input']) ? json_encode($message['tool_input']) : null,
                        'created_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table(self::MESSAGES_TABLE)->insert($rows);
                }
            }
        });
    }

    /**
     * Delete a conversation record by ID from the given namespace.
     *
     * @param  string  $key
     * @param  string  $namespace
     * @return void
     */
    public function forget(string $key, string $namespace = 'default'): void
    {
        $this->guard('DatabaseConversationMemory::forget', function () use ($key, $namespace): void {
            self::assertWritable($key, $namespace);

            self::scope(
                DB::table(self::CONVERSATIONS_TABLE)
                    ->where('id', $key)
                    ->where('namespace', $namespace)
            )->delete();
        });
    }

    /**
     * Delete the acting user's conversation records in the given namespace with their
     * messages, or every user's when the caller may reach every conversation.
     *
     * @param  string  $namespace
     * @return void
     */
    public function flush(string $namespace = 'default'): void
    {
        $this->guard('DatabaseConversationMemory::flush', function () use ($namespace): void {
            $ids = self::scope(
                DB::table(self::CONVERSATIONS_TABLE)->where('namespace', $namespace)
            )->pluck('id')->toArray();

            if ($ids !== []) {
                DB::table(self::MESSAGES_TABLE)
                    ->whereIn('conversation_id', $ids)
                    ->delete();
            }

            DB::table(self::CONVERSATIONS_TABLE)
                ->whereIn('id', $ids)
                ->delete();
        });
    }

    /**
     * Return the acting user's conversation records in the given namespace as an id-keyed
     * array, or every user's when the caller may reach every conversation.
     *
     * @param  string  $namespace
     * @return array<string, mixed>
     */
    public function all(string $namespace = 'default'): array
    {
        return $this->guard('DatabaseConversationMemory::all', function () use ($namespace): array {
            $rows = self::scope(
                DB::table(self::CONVERSATIONS_TABLE)->where('namespace', $namespace)
            )->orderBy('created_at')
                ->orderBy('id')
                ->get(['id', 'title', 'metadata', 'created_at']);

            $result = [];
            foreach ($rows as $row) {
                $result[$row->id] = [
                    'id' => $row->id,
                    'title' => $row->title,
                    'metadata' => $row->metadata !== null ? json_decode($row->metadata, associative: true) : [],
                    'created_at' => $row->created_at,
                ];
            }

            return $result;
        });
    }

    /**
     * Return true when a conversation record exists for the given ID and namespace.
     *
     * @param  string  $key
     * @param  string  $namespace
     * @return bool
     */
    public function has(string $key, string $namespace = 'default'): bool
    {
        return $this->guard('DatabaseConversationMemory::has', function () use ($key, $namespace): bool {
            return self::scope(
                DB::table(self::CONVERSATIONS_TABLE)
                    ->where('id', $key)
                    ->where('namespace', $namespace)
            )->exists();
        });
    }

    /**
     * Apply the ownership predicate unless the acting user may reach every conversation.
     *
     * @param  Builder  $query
     * @return Builder
     */
    private static function scope(Builder $query): Builder
    {
        if (LaravelIdentityResolver::manageAll()) {
            return $query;
        }

        return $query->where('user_id', LaravelIdentityResolver::actingUserId());
    }

    /**
     * Whether the acting user may reach the given conversation row.
     *
     * @param  object  $row  Row from phpclaw_conversations.
     * @return bool
     */
    private static function isVisible(object $row): bool
    {
        if (LaravelIdentityResolver::manageAll()) {
            return true;
        }

        return (string) ($row->user_id ?? '') === LaravelIdentityResolver::actingUserId();
    }

    /**
     * Throw when the conversation exists and belongs to a different user, before any
     * response is committed.
     *
     * @param  string  $key  Conversation ULID.
     * @param  string  $namespace  Scoping namespace.
     * @return void
     */
    public static function assertAccess(string $key, string $namespace = 'conversations'): void
    {
        self::assertWritable($key, $namespace);
    }

    /**
     * Throw when the acting user owns neither the conversation nor the manage-all ability.
     *
     * @param  string  $key  Conversation ULID.
     * @param  string  $namespace  Scoping namespace.
     * @return void
     */
    private static function assertWritable(string $key, string $namespace): void
    {
        if (LaravelIdentityResolver::manageAll()) {
            return;
        }

        $owner = DB::table(self::CONVERSATIONS_TABLE)
            ->where('id', $key)
            ->where('namespace', $namespace)
            ->value('user_id');

        if ($owner !== null && (string) $owner !== LaravelIdentityResolver::actingUserId()) {
            throw new ConversationAccessDeniedException;
        }
    }

    /**
     * Delete every conversation owned by a user, together with its messages. Deliberately
     * not ownership-scoped: a server-side erasure hook, unreachable from agent, REST or CLI.
     *
     * @param  string  $userId  Owner identifier as stored in phpclaw_conversations.user_id.
     * @param  string  $namespace  Scoping namespace.
     * @return int Number of conversations deleted.
     */
    public static function purgeUser(string $userId, string $namespace = 'conversations'): int
    {
        $ids = DB::table(self::CONVERSATIONS_TABLE)
            ->where('user_id', $userId)
            ->where('namespace', $namespace)
            ->pluck('id')
            ->toArray();

        if ($ids === []) {
            return 0;
        }

        DB::table(self::MESSAGES_TABLE)
            ->whereIn('conversation_id', $ids)
            ->delete();

        return DB::table(self::CONVERSATIONS_TABLE)
            ->whereIn('id', $ids)
            ->delete();
    }

    /**
     * Auto-generate an 80-char title from the first user message, or null if none exists.
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

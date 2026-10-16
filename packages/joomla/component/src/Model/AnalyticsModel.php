<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Component\Administrator\Model;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\Database\DatabaseInterface;
use PhpClaw\Joomla\Component\Administrator\Database\PhpClawTables;
use PhpClaw\Joomla\Component\Administrator\Model\Concerns\ReadsPhpClawParams;

/**
 * Analytics model - usage stats from the phpClaw memory/conversation/message tables.
 */
final class AnalyticsModel extends BaseDatabaseModel
{
    use ReadsPhpClawParams;

    private const CACHE_GROUP = 'phpclaw';

    private const CACHE_TTL = 300;

    private const SECONDS_PER_DAY = 86400;

    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    private const EMPTY_STATS = [
        'conversations' => 0,
        'messages' => 0,
        'active_24h' => 0,
    ];

    private ?string $engineError = null;

    /**
     * Gather all analytics data from the database tables.
     *
     * Scoped to the acting user's own conversations unless they hold phpclaw.chat.manageall.
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        try {
            $db = $this->getDatabase();
            $conversationTable = $db->quoteName(PhpClawTables::CONVERSATIONS_TABLE);
            $messageTable = $db->quoteName(PhpClawTables::MESSAGES_TABLE);
            $userId = $this->canManageAll() ? null : $this->resolveActingUserId();
            $cacheSuffix = $userId === null ? 'all' : "user_{$userId}";

            return [
                'conversations' => self::cached("analytics_total_conversations_{$cacheSuffix}", static fn (): int => self::countConversations($db, $conversationTable, $userId)),
                'messages' => self::cached("analytics_total_messages_{$cacheSuffix}", static fn (): int => self::countMessages($db, $conversationTable, $messageTable, $userId)),
                'active_24h' => self::cached("analytics_active_24h_{$cacheSuffix}", static fn (): int => self::countActive24h($db, $conversationTable, $userId)),
            ];
        } catch (\Throwable $e) {
            error_log('phpClaw AnalyticsModel: '.$e->getMessage());
            $this->engineError = Text::_('COM_PHPCLAW_ERROR_ANALYTICS_LOAD');

            return self::EMPTY_STATS;
        }
    }

    /**
     * Last engine error message, or null.
     *
     * @return ?string
     */
    public function getEngineError(): ?string
    {
        return $this->engineError;
    }

    /**
     * Count conversations updated in the last 24 hours, optionally scoped to one user.
     *
     * @param  DatabaseInterface  $db
     * @param  string  $quotedConvTable
     * @param  ?int  $userId  Null for every user's conversations, an id to scope to one user.
     * @return int
     */
    private static function countActive24h(DatabaseInterface $db, string $quotedConvTable, ?int $userId): int
    {
        try {
            $updatedAt = $db->quoteName('updated_at');
            $since = date(self::DATETIME_FORMAT, time() - self::SECONDS_PER_DAY);
            $query = "SELECT COUNT(*) FROM {$quotedConvTable} WHERE {$updatedAt} >= ".$db->quote($since);

            if ($userId !== null) {
                $query .= ' AND '.$db->quoteName('user_id').' = '.(int) $userId;
            }

            return (int) $db->setQuery($query)->loadResult();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Count conversations, optionally scoped to one user.
     *
     * @param  DatabaseInterface  $db
     * @param  string  $quotedConvTable
     * @param  ?int  $userId  Null for every user's conversations, an id to scope to one user.
     * @return int
     */
    private static function countConversations(DatabaseInterface $db, string $quotedConvTable, ?int $userId): int
    {
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($quotedConvTable);

        if ($userId !== null) {
            $query->where($db->quoteName('user_id').' = '.(int) $userId);
        }

        return (int) $db->setQuery($query)->loadResult();
    }

    /**
     * Count messages through their conversation, optionally scoped to one user.
     *
     * @param  DatabaseInterface  $db
     * @param  string  $quotedConvTable
     * @param  string  $quotedMsgTable
     * @param  ?int  $userId  Null for every user's messages, an id to scope to one user's conversations.
     * @return int
     */
    private static function countMessages(DatabaseInterface $db, string $quotedConvTable, string $quotedMsgTable, ?int $userId): int
    {
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($quotedMsgTable)
            ->innerJoin($quotedConvTable.' ON '.$quotedConvTable.'.'.$db->quoteName('id').' = '.$quotedMsgTable.'.'.$db->quoteName('conversation_id'));

        if ($userId !== null) {
            $query->where($quotedConvTable.'.'.$db->quoteName('user_id').' = '.(int) $userId);
        }

        return (int) $db->setQuery($query)->loadResult();
    }

    /**
     * Wrap an int-producing callable in Joomla's output cache.
     *
     * @param  string  $id
     * @param  callable(): int  $producer
     * @return int
     */
    private static function cached(string $id, callable $producer): int
    {
        try {
            $cache = Factory::getCache(self::CACHE_GROUP, 'output');
            $cache->setLifeTime(self::CACHE_TTL);

            $cached = $cache->get($id);
            if ($cached !== false) {
                return (int) $cached;
            }

            $value = $producer();
            $cache->store($value, $id);

            return $value;
        } catch (\Throwable) {
            return $producer();
        }
    }
}

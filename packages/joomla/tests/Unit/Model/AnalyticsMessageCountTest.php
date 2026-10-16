<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Unit\Model;

use Joomla\Database\DatabaseInterface;
use Joomla\Database\Mysqli\MysqliQuery;
use PhpClaw\Joomla\Component\Administrator\Model\AnalyticsModel;
use PHPUnit\Framework\TestCase;

final class AnalyticsMessageCountTest extends TestCase
{
    private const CONV_TABLE = '`jos_phpclaw_conversations`';

    private const MSG_TABLE = '`jos_phpclaw_messages`';

    public function test_the_unscoped_count_joins_through_the_conversation_table(): void
    {
        $sql = $this->buildQuery(null);

        self::assertStringContainsString('INNER JOIN', $sql);
        self::assertStringContainsString('conversation_id', $sql);
    }

    public function test_the_unscoped_count_does_not_filter_by_user(): void
    {
        $sql = $this->buildQuery(null);

        self::assertStringNotContainsString('user_id', $sql);
    }

    public function test_the_scoped_count_joins_and_filters_by_that_user(): void
    {
        $sql = $this->buildQuery(153);

        self::assertStringContainsString('INNER JOIN', $sql);
        self::assertStringContainsString('user_id', $sql);
        self::assertStringContainsString('153', $sql);
    }

    public function test_both_branches_build_the_same_join(): void
    {
        $unscoped = $this->buildQuery(null);
        $scoped = $this->buildQuery(153);

        $join = 'INNER JOIN '.self::CONV_TABLE;

        self::assertStringContainsString($join, $unscoped);
        self::assertStringContainsString($join, $scoped);
    }

    private function buildQuery(?int $userId): string
    {
        $captured = null;

        $db = $this->createMock(DatabaseInterface::class);
        $db->method('getQuery')->willReturnCallback(static fn (): MysqliQuery => new MysqliQuery);
        $db->method('quoteName')->willReturnCallback(static fn (string $n): string => '`'.$n.'`');
        $db->method('quote')->willReturnCallback(static fn (string $v): string => "'".$v."'");
        $db->method('setQuery')->willReturnCallback(
            static function ($query) use (&$captured, $db) {
                $captured = (string) $query;

                return $db;
            },
        );
        $db->method('loadResult')->willReturn(0);

        $method = new \ReflectionMethod(AnalyticsModel::class, 'countMessages');
        $method->invoke(null, $db, self::CONV_TABLE, self::MSG_TABLE, $userId);

        return (string) $captured;
    }
}

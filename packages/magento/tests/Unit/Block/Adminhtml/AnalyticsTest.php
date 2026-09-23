<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PhpClaw\Magento\Block\Adminhtml\Analytics;
use PhpClaw\Magento\Model\IdentityResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class AnalyticsTest extends TestCase
{
    private Template\Context&MockObject $context;

    private ResourceConnection&MockObject $resource;

    private AdapterInterface&MockObject $connection;

    private CacheInterface&MockObject $cache;

    private IdentityResolver&MockObject $identity;

    private Analytics $block;

    protected function setUp(): void
    {
        $this->context = $this->createMock(Template\Context::class);
        $this->resource = $this->createMock(ResourceConnection::class);
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->cache = $this->createMock(CacheInterface::class);

        $this->resource->method('getConnection')->willReturn($this->connection);
        $this->resource->method('getTableName')->willReturnArgument(0);

        $this->cache->method('load')->willReturn(false);
        $this->cache->method('save')->willReturn(true);

        $this->identity = $this->createMock(IdentityResolver::class);
        $this->identity->method('actingUserId')->willReturn(0);
        $this->identity->method('manageAll')->willReturn(true);

        $this->block = new Analytics($this->context, $this->resource, $this->cache, $this->identity);
    }

    private function scopedBlock(int $actingUserId, bool $manageAll, ?CacheInterface $cache = null): Analytics
    {
        $identity = $this->createMock(IdentityResolver::class);
        $identity->method('actingUserId')->willReturn($actingUserId);
        $identity->method('manageAll')->willReturn($manageAll);

        return new Analytics($this->context, $this->resource, $cache ?? $this->cache, $identity);
    }

    public function test_get_stats_returns_array_with_expected_keys(): void
    {
        $this->connection->method('isTableExists')->willReturn(false);

        $stats = $this->block->getStats();

        self::assertArrayHasKey('conversations', $stats);
        self::assertArrayHasKey('messages', $stats);
        self::assertArrayHasKey('active_24h', $stats);
    }

    public function test_get_stats_returns_zeros_when_tables_missing(): void
    {
        $this->connection->method('isTableExists')->willReturn(false);

        $stats = $this->block->getStats();

        self::assertSame(0, $stats['conversations']);
        self::assertSame(0, $stats['messages']);
        self::assertSame(0, $stats['active_24h']);
    }

    public function test_get_stats_reads_conversations_when_table_exists(): void
    {
        $this->connection->method('isTableExists')
            ->willReturnCallback(fn (string $t) => $t === 'phpclaw_conversations');

        $this->connection->method('fetchOne')
            ->willReturnOnConsecutiveCalls('7', '3');

        $stats = $this->block->getStats();

        self::assertSame(7, $stats['conversations']);
        self::assertSame(3, $stats['active_24h']);
        self::assertSame(0, $stats['messages']);
    }

    public function test_get_stats_reads_messages_when_table_exists(): void
    {
        $this->connection->method('isTableExists')
            ->willReturnCallback(fn (string $t) => $t === 'phpclaw_messages');

        $this->connection->method('fetchOne')->willReturn('42');

        $stats = $this->block->getStats();

        self::assertSame(42, $stats['messages']);
        self::assertSame(0, $stats['conversations']);
    }

    public function test_get_stats_both_tables_exist(): void
    {
        $this->connection->method('isTableExists')->willReturn(true);

        $this->connection->method('fetchOne')
            ->willReturnOnConsecutiveCalls('5', '2', '99');

        $stats = $this->block->getStats();

        self::assertSame(5, $stats['conversations']);
        self::assertSame(2, $stats['active_24h']);
        self::assertSame(99, $stats['messages']);
    }

    public function test_get_stats_swallows_exception_and_returns_zeros(): void
    {
        $this->connection->method('isTableExists')
            ->willThrowException(new \RuntimeException('DB error'));

        $stats = $this->block->getStats();

        self::assertSame(0, $stats['conversations']);
        self::assertSame(0, $stats['messages']);
        self::assertSame(0, $stats['active_24h']);
    }

    public function test_get_stats_casts_every_count_to_an_integer(): void
    {
        $this->connection->method('isTableExists')->willReturn(true);
        $this->connection->method('fetchOne')->willReturn('10');

        self::assertSame(
            ['conversations' => 10, 'messages' => 10, 'active_24h' => 10],
            $this->block->getStats(),
        );
    }

    public function test_chat_tier_counts_are_filtered_by_owner(): void
    {
        $captured = [];
        $this->connection->method('isTableExists')->willReturn(true);
        $this->connection->method('fetchOne')
            ->willReturnCallback(function (string $sql, array $bind = []) use (&$captured): int {
                $captured[] = ['sql' => $sql, 'bind' => $bind];

                return 1;
            });

        $this->scopedBlock(6, false)->getStats();

        self::assertStringContainsString('`admin_user_id` = ?', $captured[0]['sql']);
        self::assertSame([6], $captured[0]['bind']);
        self::assertStringContainsString('`admin_user_id` = ?', $captured[1]['sql']);
        self::assertSame(6, $captured[1]['bind'][1]);
        self::assertStringContainsString('INNER JOIN', $captured[2]['sql']);
        self::assertSame([6], $captured[2]['bind']);
    }

    public function test_manage_all_counts_are_not_filtered(): void
    {
        $captured = [];
        $this->connection->method('isTableExists')->willReturn(true);
        $this->connection->method('fetchOne')
            ->willReturnCallback(function (string $sql, array $bind = []) use (&$captured): int {
                $captured[] = ['sql' => $sql, 'bind' => $bind];

                return 1;
            });

        $this->scopedBlock(1, true)->getStats();

        self::assertCount(3, $captured, 'all three counts must be queried');

        foreach ($captured as $call) {
            self::assertNotContains(1, $call['bind'], 'a manage-all reader never binds the acting owner id');
            self::assertStringNotContainsString('admin_user_id', $call['sql'], 'a manage-all reader carries no ownership predicate');
        }

        $messageSql = array_values(array_filter(
            array_column($captured, 'sql'),
            static fn (string $sql): bool => str_contains($sql, 'phpclaw_messages'),
        ));

        self::assertStringContainsString(
            'INNER JOIN',
            $messageSql[0] ?? '',
            'the message count is reached through the conversation, so an orphaned message cannot inflate it',
        );
    }

    public function test_cache_keys_are_scoped_to_the_acting_admin(): void
    {
        $keys = [];
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(false);
        $cache->method('save')
            ->willReturnCallback(function (string $data, string $key) use (&$keys): bool {
                $keys[] = $key;

                return true;
            });

        $this->connection->method('isTableExists')->willReturn(true);
        $this->connection->method('fetchOne')->willReturn(1);

        $this->scopedBlock(6, false, $cache)->getStats();

        self::assertSame([
            'phpclaw_analytics_total_conversations_6',
            'phpclaw_analytics_active_24h_6',
            'phpclaw_analytics_total_messages_6',
        ], $keys);
    }

    public function test_two_admins_never_share_a_cache_key(): void
    {
        $keys = [];
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(false);
        $cache->method('save')
            ->willReturnCallback(function (string $data, string $key) use (&$keys): bool {
                $keys[] = $key;

                return true;
            });

        $this->connection->method('isTableExists')->willReturn(true);
        $this->connection->method('fetchOne')->willReturn(1);

        $this->scopedBlock(6, false, $cache)->getStats();
        $sixKeys = $keys;
        $keys = [];
        $this->scopedBlock(7, false, $cache)->getStats();

        self::assertSame([], array_intersect($sixKeys, $keys));
    }

    public function test_manage_all_uses_a_distinct_cache_scope(): void
    {
        $keys = [];
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(false);
        $cache->method('save')
            ->willReturnCallback(function (string $data, string $key) use (&$keys): bool {
                $keys[] = $key;

                return true;
            });

        $this->connection->method('isTableExists')->willReturn(true);
        $this->connection->method('fetchOne')->willReturn(1);

        $this->scopedBlock(1, true, $cache)->getStats();

        self::assertSame([
            'phpclaw_analytics_total_conversations_all',
            'phpclaw_analytics_active_24h_all',
            'phpclaw_analytics_total_messages_all',
        ], $keys);
    }
}

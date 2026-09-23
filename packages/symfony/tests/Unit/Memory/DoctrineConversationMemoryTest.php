<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit\Memory;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use PhpClaw\Exceptions\MemoryException;
use PhpClaw\Symfony\Memory\DoctrineConversationMemory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DoctrineConversationMemoryTest extends TestCase
{
    private Connection&MockObject $conn;

    protected function setUp(): void
    {
        $this->conn = $this->createMock(Connection::class);
    }

    public function test_get_returns_null_when_not_found(): void
    {
        $this->conn->method('fetchAssociative')->willReturn(false);

        $memory = new DoctrineConversationMemory($this->conn, storeMessages: false);

        self::assertNull($memory->get('no-such-id', 'conversations'));
    }

    public function test_get_returns_conversation_array(): void
    {
        $this->conn->method('fetchAssociative')->willReturn([
            'id' => 'c-1',
            'namespace' => 'conversations',
            'title' => 'My conv',
            'metadata' => '{"k":"v"}',
            'created_at' => '2026-04-15 10:00:00',
        ]);

        $memory = new DoctrineConversationMemory($this->conn, storeMessages: false);
        $result = $memory->get('c-1', 'conversations');

        self::assertIsArray($result);
        self::assertSame('c-1', $result['id']);
        self::assertSame('My conv', $result['title']);
        self::assertSame(['k' => 'v'], $result['metadata']);
        self::assertSame([], $result['history']);
    }

    public function test_get_includes_history_when_store_messages_true(): void
    {
        $this->conn
            ->method('fetchAssociative')
            ->willReturn([
                'id' => 'c-2',
                'namespace' => 'default',
                'title' => null,
                'metadata' => null,
                'created_at' => '2026-04-15 10:00:00',
            ]);

        $this->conn
            ->method('fetchAllAssociative')
            ->willReturn([
                ['role' => 'user', 'content' => 'hello', 'tool_name' => null, 'tool_input' => null],
                ['role' => 'assistant', 'content' => 'hi', 'tool_name' => null, 'tool_input' => null],
            ]);

        $memory = new DoctrineConversationMemory($this->conn, storeMessages: true);
        $result = $memory->get('c-2', 'default');

        self::assertIsArray($result);
        self::assertCount(2, $result['history']);
        self::assertSame('user', $result['history'][0]['role']);
        self::assertSame('hello', $result['history'][0]['content']);
    }

    public function test_get_history_decodes_tool_input_json(): void
    {
        $this->conn
            ->method('fetchAssociative')
            ->willReturn([
                'id' => 'c-3',
                'namespace' => 'default',
                'title' => null,
                'metadata' => null,
                'created_at' => '2026-04-15 10:00:00',
            ]);

        $this->conn
            ->method('fetchAllAssociative')
            ->willReturn([
                ['role' => 'tool', 'content' => 'result', 'tool_name' => 'db_query', 'tool_input' => '{"sql":"SELECT 1"}'],
            ]);

        $memory = new DoctrineConversationMemory($this->conn, storeMessages: true);
        $result = $memory->get('c-3', 'default');

        self::assertSame(['sql' => 'SELECT 1'], $result['history'][0]['tool_input']);
    }

    public function test_get_returns_empty_metadata_when_null(): void
    {
        $this->conn->method('fetchAssociative')->willReturn([
            'id' => 'c-4',
            'namespace' => 'default',
            'title' => null,
            'metadata' => null,
            'created_at' => '2026-04-15 10:00:00',
        ]);

        $memory = new DoctrineConversationMemory($this->conn, storeMessages: false);
        $result = $memory->get('c-4', 'default');

        self::assertSame([], $result['metadata']);
    }

    public function test_set_throws_when_value_is_not_array(): void
    {
        $this->expectException(MemoryException::class);

        $memory = new DoctrineConversationMemory($this->conn);
        $memory->set('c-1', 'not-an-array', 'conversations');
    }

    public function test_set_inserts_new_conversation(): void
    {
        $this->conn->method('fetchOne')->willReturnOnConsecutiveCalls(false, 0, 0);

        $this->conn
            ->expects($this->once())
            ->method('executeStatement')
            ->with($this->stringContains('INSERT INTO phpclaw_conversations'));

        $memory = new DoctrineConversationMemory($this->conn, storeMessages: false);
        $memory->set('c-new', ['title' => 'New chat'], 'default');
    }

    public function test_set_updates_existing_conversation(): void
    {
        $this->conn->method('fetchOne')->willReturnOnConsecutiveCalls(false, 1, 1);

        $this->conn
            ->expects($this->once())
            ->method('executeStatement')
            ->with($this->stringContains('UPDATE phpclaw_conversations'));

        $memory = new DoctrineConversationMemory($this->conn, storeMessages: false);
        $memory->set('c-existing', ['title' => 'Updated'], 'default');
    }

    public function test_set_rewrites_history_when_store_messages_true(): void
    {
        $this->conn->method('fetchOne')->willReturnOnConsecutiveCalls(false, 0, 0);

        $callCount = 0;
        $this->conn
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql) use (&$callCount): int {
                $callCount++;

                return 1;
            });

        $memory = new DoctrineConversationMemory($this->conn, storeMessages: true);
        $memory->set('c-hist', [
            'title' => 'With history',
            'history' => [
                ['role' => 'user', 'content' => 'hello'],
                ['role' => 'assistant', 'content' => 'hi'],
            ],
        ], 'default');

        self::assertGreaterThanOrEqual(3, $callCount);
    }

    public function test_set_extracts_title_from_first_user_message(): void
    {
        $this->conn->method('fetchOne')->willReturnOnConsecutiveCalls(false, 0, 0);

        $capturedSql = '';
        $capturedParams = [];
        $this->conn
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params = []) use (&$capturedSql, &$capturedParams): int {
                if (str_contains($sql, 'INSERT INTO phpclaw_conversations')) {
                    $capturedSql = $sql;
                    $capturedParams = $params;
                }

                return 1;
            });

        $memory = new DoctrineConversationMemory($this->conn, storeMessages: false);
        $memory->set('c-auto', [
            'history' => [
                ['role' => 'user', 'content' => 'What is Symfony?'],
            ],
        ], 'default');

        self::assertStringContainsString('id, namespace, user_id, title', $capturedSql);
        self::assertSame('', $capturedParams[2] ?? null, 'Position 2 is the owner: empty when there is no logged-in user.');
        self::assertStringContainsString('What is Symfony', (string) ($capturedParams[3] ?? ''));
    }

    public function test_forget_deletes_conversation(): void
    {
        $this->conn
            ->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('DELETE FROM phpclaw_conversations'),
                ['c-del', 'default', ''],
            );

        $memory = new DoctrineConversationMemory($this->conn, storeMessages: false);
        $memory->forget('c-del', 'default');
    }

    public function test_flush_deletes_all_conversations_in_namespace(): void
    {
        $this->conn
            ->method('fetchFirstColumn')
            ->willReturn(['c-1', 'c-2']);

        $calls = [];
        $this->conn
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql) use (&$calls): int {
                $calls[] = $sql;

                return 1;
            });

        $memory = new DoctrineConversationMemory($this->conn, storeMessages: false);
        $memory->flush('default');

        self::assertCount(2, $calls);
        self::assertStringContainsString('DELETE FROM phpclaw_messages', $calls[0]);
        self::assertStringContainsString('DELETE FROM phpclaw_conversations', $calls[1]);
    }

    public function test_flush_deletes_nothing_when_no_conversations_are_visible(): void
    {
        $this->conn->method('fetchFirstColumn')->willReturn([]);

        $this->conn->expects($this->never())->method('executeStatement');

        $memory = new DoctrineConversationMemory($this->conn, storeMessages: false);
        $memory->flush('empty-ns');
    }

    public function test_has_delegates_to_count_query(): void
    {
        $this->conn->method('fetchOne')->willReturn(1);

        $memory = new DoctrineConversationMemory($this->conn);

        self::assertTrue($memory->has('c-1', 'conversations'));
    }

    public function test_has_returns_false_for_missing(): void
    {
        $this->conn->method('fetchOne')->willReturn(0);

        $memory = new DoctrineConversationMemory($this->conn);

        self::assertFalse($memory->has('nope', 'conversations'));
    }

    public function test_all_returns_empty_for_unused_namespace(): void
    {
        $this->conn->method('fetchAllAssociative')->willReturn([]);

        $memory = new DoctrineConversationMemory($this->conn);

        self::assertSame([], $memory->all('never-used'));
    }

    public function test_all_returns_metadata_only(): void
    {
        $this->conn->method('fetchAllAssociative')->willReturn([
            ['id' => 'c-1', 'title' => 'A', 'metadata' => null, 'created_at' => '2026-04-15'],
            ['id' => 'c-2', 'title' => 'B', 'metadata' => '{"x":1}', 'created_at' => '2026-04-16'],
        ]);

        $memory = new DoctrineConversationMemory($this->conn);
        $all = $memory->all('conversations');

        self::assertCount(2, $all);
        self::assertSame('A', $all['c-1']['title']);
        self::assertSame(['x' => 1], $all['c-2']['metadata']);
    }

    public function test_all_order_by_includes_id_tiebreaker(): void
    {
        $capturedSql = '';
        $this->conn
            ->method('fetchAllAssociative')
            ->willReturnCallback(function (string $sql) use (&$capturedSql): array {
                $capturedSql = $sql;

                return [];
            });

        $memory = new DoctrineConversationMemory($this->conn);
        $memory->all('default');

        self::assertStringContainsString('ORDER BY created_at, id', $capturedSql);
    }

    public function test_get_wraps_dbal_exception_in_memory_exception(): void
    {
        $this->conn
            ->method('fetchAssociative')
            ->willThrowException($this->createMock(Exception::class));

        $this->expectException(MemoryException::class);

        $memory = new DoctrineConversationMemory($this->conn);
        $memory->get('bad', 'default');
    }

    public function test_default_store_messages_is_true(): void
    {
        $memory = new DoctrineConversationMemory($this->conn);

        $ref = new \ReflectionClass($memory);
        $prop = $ref->getProperty('storeMessages');
        $value = $prop->getValue($memory);

        self::assertTrue($value);
    }

    public function test_set_skips_rewrite_history_when_store_messages_false(): void
    {
        $this->conn->method('fetchOne')->willReturnOnConsecutiveCalls(false, 0, 0);

        $callCount = 0;
        $this->conn
            ->method('executeStatement')
            ->willReturnCallback(function () use (&$callCount): int {
                $callCount++;

                return 1;
            });

        $memory = new DoctrineConversationMemory($this->conn, storeMessages: false);
        $memory->set('c-no-history', [
            'title' => 'No history written',
            'history' => [['role' => 'user', 'content' => 'hello']],
        ], 'default');

        self::assertSame(1, $callCount);
    }

    public function test_set_rewrite_history_skips_non_array_entries(): void
    {
        $this->conn->method('fetchOne')->willReturnOnConsecutiveCalls(false, 0, 0);

        $callCount = 0;
        $this->conn
            ->method('executeStatement')
            ->willReturnCallback(function () use (&$callCount): int {
                $callCount++;

                return 1;
            });

        $memory = new DoctrineConversationMemory($this->conn, storeMessages: true);
        $memory->set('c-skip-bad', [
            'history' => [
                'not-an-array',
                ['role' => 'user', 'content' => 'valid'],
            ],
        ], 'default');

        self::assertSame(3, $callCount);
    }

    public function test_set_encodes_tool_input_in_history(): void
    {
        $this->conn->method('fetchOne')->willReturnOnConsecutiveCalls(false, 0, 0);

        $capturedParams = [];
        $this->conn
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params = []) use (&$capturedParams): int {
                if (str_contains($sql, 'INSERT INTO phpclaw_messages')) {
                    $capturedParams = $params;
                }

                return 1;
            });

        $memory = new DoctrineConversationMemory($this->conn, storeMessages: true);
        $memory->set('c-tool', [
            'history' => [
                ['role' => 'tool', 'content' => 'result', 'tool_name' => 'db_query', 'tool_input' => ['sql' => 'SELECT 1']],
            ],
        ], 'default');

        self::assertStringContainsString('SELECT 1', (string) ($capturedParams[5] ?? ''));
    }
}

<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit\Memory;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use PhpClaw\Exceptions\MemoryException;
use PhpClaw\Symfony\Memory\DoctrineMemory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DoctrineMemoryTest extends TestCase
{
    private Connection&MockObject $connection;

    private DoctrineMemory $memory;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);

        $this->connection
            ->method('getDatabasePlatform')
            ->willReturn(new SQLitePlatform);

        $this->memory = new DoctrineMemory($this->connection);
    }

    public function test_set_executes_insert_when_key_does_not_exist(): void
    {
        $this->connection
            ->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn(false);

        $this->connection
            ->expects($this->once())
            ->method('executeStatement')
            ->with($this->stringContains('INSERT INTO phpclaw_memory'));

        $this->memory->set(key: 'mykey', value: ['data' => 'value'], namespace: 'test');
    }

    public function test_set_executes_update_when_key_already_exists(): void
    {
        $this->connection
            ->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn(['id' => '01ABCDEF1234567890123456', 'created_at' => '2024-01-01 00:00:00']);

        $this->connection
            ->expects($this->once())
            ->method('executeStatement')
            ->with($this->stringContains('UPDATE phpclaw_memory'));

        $this->memory->set(key: 'mykey', value: 'hello', namespace: 'test');
    }

    public function test_set_with_ttl_calculates_expires_at(): void
    {
        $this->connection
            ->method('fetchAssociative')
            ->willReturn(false);

        $this->connection
            ->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->anything(),
                $this->callback(function (array $params): bool {
                    return $params[4] !== null;
                }),
            );

        $this->memory->set(key: 'ttlkey', value: 'data', namespace: 'default', ttl: 300);
    }

    public function test_get_returns_decoded_value(): void
    {
        $this->connection
            ->method('fetchAssociative')
            ->willReturn([
                'id' => '01ABCDEF1234567890123456',
                'value' => '{"foo":"bar"}',
                'expires_at' => null,
            ]);

        $result = $this->memory->get(key: 'mykey', namespace: 'test');

        $this->assertSame(['foo' => 'bar'], $result);
    }

    public function test_get_returns_null_when_key_missing(): void
    {
        $this->connection
            ->method('fetchAssociative')
            ->willReturn(false);

        $result = $this->memory->get(key: 'nonexistent', namespace: 'default');

        $this->assertNull($result);
    }

    public function test_get_returns_null_and_deletes_expired_entry(): void
    {
        $pastTime = date('Y-m-d H:i:s', time() - 3600);

        $this->connection
            ->method('fetchAssociative')
            ->willReturn([
                'id' => '01ABCDEF1234567890123456',
                'value' => '"stale"',
                'expires_at' => $pastTime,
            ]);

        $this->connection
            ->expects($this->once())
            ->method('executeStatement')
            ->with($this->stringContains('DELETE FROM phpclaw_memory WHERE id'));

        $result = $this->memory->get(key: 'expiredkey', namespace: 'default');

        $this->assertNull($result);
    }

    public function test_forget_deletes_correct_key(): void
    {
        $this->connection
            ->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('DELETE FROM phpclaw_memory WHERE namespace'),
                ['test', 'mykey'],
            );

        $this->memory->forget(key: 'mykey', namespace: 'test');
    }

    public function test_flush_deletes_all_keys_in_namespace(): void
    {
        $this->connection
            ->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('DELETE FROM phpclaw_memory WHERE namespace'),
                ['myns'],
            );

        $this->memory->flush(namespace: 'myns');
    }

    public function test_has_returns_true_for_existing_non_expired_key(): void
    {
        $this->connection
            ->method('fetchOne')
            ->willReturn('1');

        $this->assertTrue($this->memory->has(key: 'exists', namespace: 'default'));
    }

    public function test_has_returns_false_for_missing_key(): void
    {
        $this->connection
            ->method('fetchOne')
            ->willReturn('0');

        $this->assertFalse($this->memory->has(key: 'missing', namespace: 'default'));
    }

    public function test_all_returns_non_expired_key_value_map(): void
    {
        $this->connection
            ->method('fetchAllAssociative')
            ->willReturn([
                ['lookup_key' => 'alpha', 'value' => '"hello"'],
                ['lookup_key' => 'beta',  'value' => '{"num":42}'],
            ]);

        $result = $this->memory->all(namespace: 'default');

        $this->assertSame([
            'alpha' => 'hello',
            'beta' => ['num' => 42],
        ], $result);
    }

    public function test_all_returns_empty_array_when_no_entries(): void
    {
        $this->connection
            ->method('fetchAllAssociative')
            ->willReturn([]);

        $result = $this->memory->all(namespace: 'empty');

        $this->assertSame([], $result);
    }

    public function test_get_wraps_dbal_exception_in_memory_exception(): void
    {
        $dbalException = $this->createMock(Exception::class);

        $this->connection
            ->method('fetchAssociative')
            ->willThrowException($dbalException);

        $this->expectException(MemoryException::class);
        $this->memory->get(key: 'key', namespace: 'ns');
    }

    public function test_set_uses_mysql_upsert_on_mysql_platform(): void
    {
        $mysqlConn = $this->createMock(Connection::class);
        $mysqlConn
            ->method('getDatabasePlatform')
            ->willReturn(new MySQLPlatform);

        $mysqlConn
            ->expects($this->once())
            ->method('executeStatement')
            ->with($this->stringContains('ON DUPLICATE KEY UPDATE'));

        $memory = new DoctrineMemory($mysqlConn);
        $memory->set(key: 'k', value: 'v', namespace: 'default');
    }

    public function test_delete_by_id_swallows_dbal_exception_silently(): void
    {
        $pastTime = date('Y-m-d H:i:s', time() - 3600);

        $this->connection
            ->method('fetchAssociative')
            ->willReturn([
                'id' => 'SOME-ID-01234',
                'value' => '"stale"',
                'expires_at' => $pastTime,
            ]);

        $this->connection
            ->method('executeStatement')
            ->willThrowException($this->createMock(Exception::class));

        $result = $this->memory->get(key: 'expiredkey', namespace: 'default');

        self::assertNull($result);
    }

    public function test_set_with_no_ttl_leaves_expires_at_null(): void
    {
        $this->connection
            ->method('fetchAssociative')
            ->willReturn(false);

        $this->connection
            ->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->anything(),
                $this->callback(static function (array $params): bool {
                    return $params[4] === null;
                }),
            );

        $this->memory->set(key: 'k', value: 'v', namespace: 'default', ttl: null);
    }
}

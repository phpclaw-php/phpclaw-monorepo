<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Memory;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PhpClaw\Magento\Memory\ResourceMemory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ResourceMemoryTest extends TestCase
{
    private AdapterInterface&MockObject $connection;

    private ResourceConnection&MockObject $resourceConnection;

    private ResourceMemory $memory;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);

        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        $this->resourceConnection->method('getTableName')->willReturn('phpclaw_memory');

        $this->memory = new ResourceMemory($this->resourceConnection);
    }

    public function test_get_returns_null_when_not_found(): void
    {
        $this->connection->method('fetchOne')->willReturn(false);

        self::assertNull($this->memory->get('missing'));
    }

    public function test_get_returns_string_value(): void
    {
        $this->connection->method('fetchOne')->willReturn('hello magento');

        self::assertSame('hello magento', $this->memory->get('greeting'));
    }

    public function test_get_deserializes_array_value(): void
    {
        $this->connection->method('fetchOne')->willReturn(serialize(['debug' => true]));

        self::assertSame(['debug' => true], $this->memory->get('config'));
    }

    public function test_get_passes_namespace_and_key_as_bindings(): void
    {
        $captured = [];
        $this->connection->method('fetchOne')
            ->willReturnCallback(function (string $sql, array $bind) use (&$captured): bool {
                $captured = $bind;

                return false;
            });

        $this->memory->get('my_key', 'my_ns');

        self::assertSame('my_ns', $captured[0]);
        self::assertSame('my_key', $captured[1]);
    }

    public function test_set_calls_insert_on_duplicate(): void
    {
        $this->connection->expects(self::once())->method('insertOnDuplicate');

        $this->memory->set('new_key', 'value');
    }

    public function test_set_includes_ulid_id(): void
    {
        $capturedData = [];
        $this->connection->method('insertOnDuplicate')
            ->willReturnCallback(function (string $table, array $data) use (&$capturedData): void {
                $capturedData = $data;
            });

        $this->memory->set('key', 'value');

        self::assertArrayHasKey('id', $capturedData);
        self::assertSame(26, strlen($capturedData['id']));
    }

    public function test_set_uses_lookup_key_column(): void
    {
        $capturedData = [];
        $this->connection->method('insertOnDuplicate')
            ->willReturnCallback(function (string $table, array $data) use (&$capturedData): void {
                $capturedData = $data;
            });

        $this->memory->set('my_key', 'value', 'ns');

        self::assertArrayHasKey('lookup_key', $capturedData);
        self::assertSame('my_key', $capturedData['lookup_key']);
        self::assertSame('ns', $capturedData['namespace']);
    }

    public function test_set_serializes_array_value(): void
    {
        $capturedData = [];
        $this->connection->method('insertOnDuplicate')
            ->willReturnCallback(function (string $table, array $data) use (&$capturedData): void {
                $capturedData = $data;
            });

        $this->memory->set('arr', ['x' => 1]);

        self::assertTrue(str_starts_with($capturedData['value'], 'a:'), 'Array should be PHP-serialized');
    }

    public function test_set_stores_string_as_plain(): void
    {
        $capturedData = [];
        $this->connection->method('insertOnDuplicate')
            ->willReturnCallback(function (string $table, array $data) use (&$capturedData): void {
                $capturedData = $data;
            });

        $this->memory->set('str', 'plain text');

        self::assertSame('plain text', $capturedData['value']);
    }

    public function test_set_with_ttl_sets_expires_at(): void
    {
        $capturedData = [];
        $this->connection->method('insertOnDuplicate')
            ->willReturnCallback(function (string $table, array $data) use (&$capturedData): void {
                $capturedData = $data;
            });

        $before = time();
        $this->memory->set('ttl_key', 'value', 'default', 3600);
        $after = time();

        $expiresAt = strtotime((string) $capturedData['expires_at']);

        self::assertGreaterThanOrEqual($before + 3600, $expiresAt);
        self::assertLessThanOrEqual($after + 3600, $expiresAt);
    }

    public function test_set_without_ttl_null_expires_at(): void
    {
        $capturedData = [];
        $this->connection->method('insertOnDuplicate')
            ->willReturnCallback(function (string $table, array $data) use (&$capturedData): void {
                $capturedData = $data;
            });

        $this->memory->set('no_ttl', 'value');

        self::assertNull($capturedData['expires_at']);
    }

    public function test_forget_calls_delete_with_namespace_and_key(): void
    {
        $capturedWhere = [];
        $this->connection->expects(self::once())
            ->method('delete')
            ->willReturnCallback(function (string $table, array $where) use (&$capturedWhere): void {
                $capturedWhere = $where;
            });

        $this->memory->forget('to_delete', 'my_ns');

        self::assertArrayHasKey('namespace = ?', $capturedWhere);
        self::assertSame('my_ns', $capturedWhere['namespace = ?']);
        self::assertArrayHasKey('lookup_key = ?', $capturedWhere);
        self::assertSame('to_delete', $capturedWhere['lookup_key = ?']);
    }

    public function test_flush_calls_delete_with_namespace_only(): void
    {
        $capturedWhere = [];
        $this->connection->expects(self::once())
            ->method('delete')
            ->willReturnCallback(function (string $table, array $where) use (&$capturedWhere): void {
                $capturedWhere = $where;
            });

        $this->memory->flush('target_ns');

        self::assertArrayHasKey('namespace = ?', $capturedWhere);
        self::assertSame('target_ns', $capturedWhere['namespace = ?']);
        self::assertCount(1, $capturedWhere);
    }

    public function test_all_returns_empty_array_when_no_rows(): void
    {
        $this->connection->method('fetchPairs')->willReturn([]);

        self::assertSame([], $this->memory->all());
    }

    public function test_all_returns_deserialized_pairs(): void
    {
        $this->connection->method('fetchPairs')->willReturn([
            'name' => 'Alice',
            'score' => serialize(99),
        ]);

        $result = $this->memory->all('ns');

        self::assertSame('Alice', $result['name']);
        self::assertSame(99, $result['score']);
    }

    public function test_has_returns_true_when_found(): void
    {
        $this->connection->method('fetchOne')->willReturn('value');

        self::assertTrue($this->memory->has('present'));
    }

    public function test_has_returns_false_when_not_found(): void
    {
        $this->connection->method('fetchOne')->willReturn(false);

        self::assertFalse($this->memory->has('absent'));
    }
}

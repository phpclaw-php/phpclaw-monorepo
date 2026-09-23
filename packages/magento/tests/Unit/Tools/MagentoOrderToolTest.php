<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Tools;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PhpClaw\Magento\Model\IdentityResolver;
use PhpClaw\Magento\Tools\MagentoOrderTool;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class MagentoOrderToolTest extends TestCase
{
    private AdapterInterface&MockObject $connection;

    private ResourceConnection&MockObject $resourceConnection;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        $this->resourceConnection->method('getTableName')->willReturnArgument(0);
    }

    private function tool(bool $allowed = true, bool $console = false): MagentoOrderTool
    {
        $identity = $this->createMock(IdentityResolver::class);
        $identity->method('runningInConsole')->willReturn($console);

        $acl = $this->createMock(AuthorizationInterface::class);
        $acl->method('isAllowed')->willReturn($allowed);

        return new MagentoOrderTool($this->resourceConnection, $identity, $acl);
    }

    private function envelope(string $result): array
    {
        $decoded = json_decode($result, true);

        self::assertIsArray($decoded, 'the tool must return a JSON envelope');

        return $decoded;
    }

    public function test_name(): void
    {
        self::assertSame('magento_orders', $this->tool()->name());
    }

    public function test_required_capability_is_the_chat_resource(): void
    {
        self::assertSame('PhpClaw_Magento::phpclaw_chat', $this->tool()->requiredCapability());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        self::assertStringContainsString('FETCH Magento sales orders', $this->tool()->description());
    }

    public function test_input_schema_has_increment_id_property(): void
    {
        $schema = $this->tool()->inputSchema();

        self::assertArrayHasKey('increment_id', $schema['properties']);
    }

    public function test_a_caller_without_the_chat_resource_is_forbidden(): void
    {
        $envelope = $this->envelope($this->tool(allowed: false)->execute([]));

        self::assertFalse($envelope['success']);
        self::assertSame('FORBIDDEN', $envelope['error']['code']);
        self::assertStringContainsString('PhpClaw_Magento::phpclaw_chat', $envelope['error']['message']);
    }

    public function test_the_console_reaches_the_tool_without_the_chat_resource(): void
    {
        $rows = [
            ['increment_id' => '000000001', 'status' => 'complete', 'grand_total' => '50.00'],
        ];
        $this->connection->method('fetchAll')->willReturn($rows);

        $envelope = $this->envelope($this->tool(allowed: false, console: true)->execute([]));

        self::assertTrue($envelope['success']);
    }

    public function test_single_order_returns_order_with_items(): void
    {
        $order = [[
            'entity_id' => 42, 'increment_id' => '000000042', 'status' => 'complete',
            'grand_total' => '99.99', 'base_currency_code' => 'USD',
        ]];
        $items = [['sku' => 'SKU-1', 'name' => 'Widget', 'qty_ordered' => 2, 'price' => '49.99']];

        $this->connection->method('fetchAll')
            ->willReturnOnConsecutiveCalls($order, $items);

        $envelope = $this->envelope($this->tool()->execute(['increment_id' => '000000042']));

        self::assertTrue($envelope['success']);
        self::assertSame('000000042', $envelope['data']['order']['increment_id']);
        self::assertCount(1, $envelope['data']['order']['items']);
        self::assertSame('SKU-1', $envelope['data']['order']['items'][0]['sku']);
    }

    public function test_single_order_not_found_returns_error_envelope(): void
    {
        $this->connection->method('fetchAll')->willReturn([]);

        $envelope = $this->envelope($this->tool()->execute(['increment_id' => '000000099']));

        self::assertFalse($envelope['success']);
        self::assertSame('NOT_FOUND', $envelope['error']['code']);
        self::assertStringContainsString('000000099', $envelope['error']['message']);
    }

    public function test_list_orders_returns_orders_in_envelope(): void
    {
        $rows = [
            ['increment_id' => '000000001', 'status' => 'complete', 'grand_total' => '50.00'],
            ['increment_id' => '000000002', 'status' => 'processing', 'grand_total' => '75.00'],
        ];
        $this->connection->method('fetchAll')->willReturn($rows);

        $envelope = $this->envelope($this->tool()->execute([]));

        self::assertTrue($envelope['success']);
        self::assertCount(2, $envelope['data']['orders']);
        self::assertSame('000000001', $envelope['data']['orders'][0]['increment_id']);
    }

    public function test_list_orders_uses_status_binding(): void
    {
        $capturedBindings = null;
        $this->connection->method('fetchAll')
            ->willReturnCallback(function (string $sql, array $bindings) use (&$capturedBindings): array {
                $capturedBindings = $bindings;

                return [];
            });

        $this->tool()->execute(['status' => 'pending']);

        self::assertContains('pending', $capturedBindings);
    }

    public function test_list_orders_uses_from_to_bindings(): void
    {
        $capturedBindings = null;
        $this->connection->method('fetchAll')
            ->willReturnCallback(function (string $sql, array $bindings) use (&$capturedBindings): array {
                $capturedBindings = $bindings;

                return [];
            });

        $this->tool()->execute(['from' => '2024-01-01', 'to' => '2024-01-31']);

        self::assertContains('2024-01-01 00:00:00', $capturedBindings);
        self::assertContains('2024-01-31 23:59:59', $capturedBindings);
    }

    public function test_list_orders_default_limit_is_20(): void
    {
        $capturedSql = '';
        $this->connection->method('fetchAll')
            ->willReturnCallback(function (string $sql) use (&$capturedSql): array {
                $capturedSql = $sql;

                return [];
            });

        $this->tool()->execute([]);

        self::assertStringContainsString('LIMIT 20', $capturedSql);
    }

    public function test_list_orders_respects_custom_limit(): void
    {
        $capturedSql = '';
        $this->connection->method('fetchAll')
            ->willReturnCallback(function (string $sql) use (&$capturedSql): array {
                $capturedSql = $sql;

                return [];
            });

        $this->tool()->execute(['limit' => 5]);

        self::assertStringContainsString('LIMIT 5', $capturedSql);
    }

    public function test_list_orders_clamps_limit_to_100(): void
    {
        $capturedSql = '';
        $this->connection->method('fetchAll')
            ->willReturnCallback(function (string $sql) use (&$capturedSql): array {
                $capturedSql = $sql;

                return [];
            });

        $this->tool()->execute(['limit' => 9999]);

        self::assertStringContainsString('LIMIT 100', $capturedSql);
    }

    public function test_invalid_status_returns_error_envelope(): void
    {
        $envelope = $this->envelope($this->tool()->execute(['status' => 'nonexistent_status']));

        self::assertFalse($envelope['success']);
        self::assertSame('INVALID_ARGUMENT', $envelope['error']['code']);
    }

    public function test_unknown_argument_is_rejected(): void
    {
        $envelope = $this->envelope($this->tool()->execute(['nope' => 1]));

        self::assertFalse($envelope['success']);
        self::assertSame('UNKNOWN_ARGUMENT', $envelope['error']['code']);
    }

    public function test_meta_reflects_applied_filters_in_list_mode(): void
    {
        $this->connection->method('fetchAll')->willReturn([]);

        $envelope = $this->envelope(
            $this->tool()->execute(['status' => 'processing', 'from' => '2024-06-01', 'to' => '2024-06-30', 'limit' => 10]),
        );

        self::assertTrue($envelope['success']);
        self::assertSame('list', $envelope['meta']['mode']);
        self::assertSame('processing', $envelope['meta']['filters']['status']);
        self::assertSame('2024-06-01', $envelope['meta']['filters']['from']);
        self::assertSame('2024-06-30', $envelope['meta']['filters']['to']);
        self::assertSame(10, $envelope['meta']['filters']['limit']);
    }

    public function test_meta_reflects_increment_id_in_single_mode(): void
    {
        $order = [[
            'entity_id' => 7, 'increment_id' => '000000007', 'status' => 'complete',
            'grand_total' => '10.00',
        ]];
        $items = [];

        $this->connection->method('fetchAll')
            ->willReturnOnConsecutiveCalls($order, $items);

        $envelope = $this->envelope($this->tool()->execute(['increment_id' => '000000007']));

        self::assertTrue($envelope['success']);
        self::assertSame('single', $envelope['meta']['mode']);
        self::assertSame('000000007', $envelope['meta']['increment_id']);
    }
}

<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Tools;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PhpClaw\Magento\Model\IdentityResolver;
use PhpClaw\Magento\Tools\MagentoReportTool;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class MagentoReportToolTest extends TestCase
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

    private function tool(bool $allowed = true, bool $console = false): MagentoReportTool
    {
        $identity = $this->createMock(IdentityResolver::class);
        $identity->method('runningInConsole')->willReturn($console);

        $acl = $this->createMock(AuthorizationInterface::class);
        $acl->method('isAllowed')->willReturn($allowed);

        return new MagentoReportTool($this->resourceConnection, $identity, $acl);
    }

    private function envelope(string $result): array
    {
        $decoded = json_decode($result, true);

        self::assertIsArray($decoded, 'the tool must return a JSON envelope');

        return $decoded;
    }

    public function test_name(): void
    {
        self::assertSame('magento_report', $this->tool()->name());
    }

    public function test_required_capability_is_the_chat_resource(): void
    {
        self::assertSame('PhpClaw_Magento::phpclaw_chat', $this->tool()->requiredCapability());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        self::assertStringContainsString('RUN a Magento sales report for a date range', $this->tool()->description());
    }

    public function test_input_schema_requires_from_to_metric(): void
    {
        $schema = $this->tool()->inputSchema();
        self::assertContains('from', $schema['required']);
        self::assertContains('to', $schema['required']);
        self::assertContains('metric', $schema['required']);
    }

    public function test_a_caller_without_the_chat_resource_is_forbidden(): void
    {
        $envelope = $this->envelope($this->tool(allowed: false)->execute([
            'from' => '2024-01-01', 'to' => '2024-01-31', 'metric' => 'revenue',
        ]));

        self::assertFalse($envelope['success']);
        self::assertSame('FORBIDDEN', $envelope['error']['code']);
        self::assertStringContainsString('PhpClaw_Magento::phpclaw_chat', $envelope['error']['message']);
    }

    public function test_the_console_reaches_the_tool_without_the_chat_resource(): void
    {
        $this->connection->method('fetchAll')->willReturn([]);

        $envelope = $this->envelope($this->tool(allowed: false, console: true)->execute([
            'from' => '2024-01-01', 'to' => '2024-01-31', 'metric' => 'revenue',
        ]));

        self::assertTrue($envelope['success']);
    }

    public function test_revenue_metric_returns_structured_result(): void
    {
        $this->connection->method('fetchAll')->willReturn([
            ['revenue' => '1500.00', 'order_count' => 30, 'base_currency_code' => 'USD'],
        ]);

        $envelope = $this->envelope($this->tool()->execute([
            'from' => '2024-01-01', 'to' => '2024-01-31', 'metric' => 'revenue',
        ]));

        self::assertTrue($envelope['success']);
        self::assertSame('revenue', $envelope['meta']['metric']);
        self::assertArrayHasKey('period', $envelope['meta']);
        self::assertArrayHasKey('data', $envelope['data']);
        self::assertSame('1500.00', $envelope['data']['data'][0]['revenue']);
    }

    public function test_orders_metric_includes_status_breakdown(): void
    {
        $breakdown = [
            ['status' => 'complete', 'count' => 20, 'total' => '1000.00'],
            ['status' => 'canceled', 'count' => 5,  'total' => '250.00'],
        ];
        $this->connection->method('fetchAll')->willReturn($breakdown);

        $envelope = $this->envelope($this->tool()->execute([
            'from' => '2024-01-01', 'to' => '2024-01-31', 'metric' => 'orders',
        ]));

        self::assertTrue($envelope['success']);
        self::assertSame('orders', $envelope['meta']['metric']);
        self::assertSame(25, $envelope['data']['total_orders']);
        self::assertCount(2, $envelope['data']['by_status']);
    }

    public function test_avg_order_value_metric(): void
    {
        $this->connection->method('fetchAll')->willReturn([
            ['avg_order_value' => '75.50', 'order_count' => 20, 'base_currency_code' => 'USD'],
        ]);

        $envelope = $this->envelope($this->tool()->execute([
            'from' => '2024-01-01', 'to' => '2024-01-31', 'metric' => 'avg_order_value',
        ]));

        self::assertTrue($envelope['success']);
        self::assertSame('avg_order_value', $envelope['meta']['metric']);
        self::assertSame('75.50', $envelope['data']['data'][0]['avg_order_value']);
    }

    public function test_top_products_metric_returns_bestsellers(): void
    {
        $products = [
            ['sku' => 'WIDGET-001', 'name' => 'Blue Widget', 'qty_sold' => 100, 'revenue' => '999.00'],
            ['sku' => 'GADGET-002', 'name' => 'Red Gadget',  'qty_sold' => 50,  'revenue' => '499.00'],
        ];
        $this->connection->method('fetchAll')->willReturn($products);

        $envelope = $this->envelope($this->tool()->execute([
            'from' => '2024-01-01', 'to' => '2024-01-31', 'metric' => 'top_products',
        ]));

        self::assertTrue($envelope['success']);
        self::assertSame('top_products', $envelope['meta']['metric']);
        self::assertCount(2, $envelope['data']['data']);
        self::assertSame('WIDGET-001', $envelope['data']['data'][0]['sku']);
    }

    public function test_top_products_uses_limit_binding(): void
    {
        $capturedSql = '';
        $this->connection->method('fetchAll')
            ->willReturnCallback(function (string $sql) use (&$capturedSql): array {
                $capturedSql = $sql;

                return [];
            });

        $this->tool()->execute([
            'from' => '2024-01-01', 'to' => '2024-01-31',
            'metric' => 'top_products', 'limit' => 5,
        ]);

        self::assertStringContainsString('LIMIT 5', $capturedSql);
    }

    public function test_date_range_is_passed_as_bindings(): void
    {
        $capturedBindings = null;
        $this->connection->method('fetchAll')
            ->willReturnCallback(function (string $sql, array $bindings) use (&$capturedBindings): array {
                $capturedBindings = $bindings;

                return [];
            });

        $this->tool()->execute(['from' => '2024-01-01', 'to' => '2024-01-31', 'metric' => 'revenue']);

        self::assertContains('2024-01-01 00:00:00', $capturedBindings);
        self::assertContains('2024-01-31 23:59:59', $capturedBindings);
    }

    public function test_missing_from_returns_invalid_argument_error(): void
    {
        $envelope = $this->envelope($this->tool()->execute(['to' => '2024-01-31', 'metric' => 'revenue']));

        self::assertFalse($envelope['success']);
        self::assertSame('INVALID_ARGUMENT', $envelope['error']['code']);
        self::assertStringContainsString("'from' and 'to' dates are required", $envelope['error']['message']);
    }

    public function test_missing_to_returns_invalid_argument_error(): void
    {
        $envelope = $this->envelope($this->tool()->execute(['from' => '2024-01-01', 'metric' => 'revenue']));

        self::assertFalse($envelope['success']);
        self::assertSame('INVALID_ARGUMENT', $envelope['error']['code']);
    }

    public function test_invalid_from_format_returns_invalid_argument_error(): void
    {
        $envelope = $this->envelope($this->tool()->execute([
            'from' => '01/01/2024', 'to' => '2024-01-31', 'metric' => 'revenue',
        ]));

        self::assertFalse($envelope['success']);
        self::assertSame('INVALID_ARGUMENT', $envelope['error']['code']);
        self::assertStringContainsString('YYYY-MM-DD', $envelope['error']['message']);
    }

    public function test_invalid_to_format_returns_invalid_argument_error(): void
    {
        $envelope = $this->envelope($this->tool()->execute([
            'from' => '2024-01-01', 'to' => 'Jan 31 2024', 'metric' => 'revenue',
        ]));

        self::assertFalse($envelope['success']);
        self::assertSame('INVALID_ARGUMENT', $envelope['error']['code']);
        self::assertStringContainsString('YYYY-MM-DD', $envelope['error']['message']);
    }

    public function test_invalid_metric_returns_invalid_argument_error(): void
    {
        $envelope = $this->envelope($this->tool()->execute([
            'from' => '2024-01-01', 'to' => '2024-01-31', 'metric' => 'sales_figure',
        ]));

        self::assertFalse($envelope['success']);
        self::assertSame('INVALID_ARGUMENT', $envelope['error']['code']);
        self::assertStringContainsString('invalid metric', $envelope['error']['message']);
    }

    public function test_a_list_over_the_byte_budget_is_capped_and_reported_in_meta(): void
    {
        $rows = [];
        for ($i = 0; $i < 400; $i++) {
            $rows[] = [
                'revenue' => '1500.00',
                'order_count' => $i,
                'base_currency_code' => str_pad((string) $i, 40, 'X', STR_PAD_LEFT),
            ];
        }
        $this->connection->method('fetchAll')->willReturn($rows);

        $envelope = $this->envelope($this->tool()->execute([
            'from' => '2024-01-01', 'to' => '2024-01-31', 'metric' => 'revenue',
        ]));

        self::assertTrue($envelope['success']);
        self::assertTrue($envelope['meta']['truncated'], 'a list past the byte budget must report truncation');
        self::assertSame(400, $envelope['meta']['total']);
        self::assertLessThan(400, $envelope['meta']['shown']);
        self::assertCount($envelope['meta']['shown'], $envelope['data']['data']);
    }
}

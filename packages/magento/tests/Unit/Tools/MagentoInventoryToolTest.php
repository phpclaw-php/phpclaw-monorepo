<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Tools;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PhpClaw\Magento\Model\IdentityResolver;
use PhpClaw\Magento\Tools\MagentoInventoryTool;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class MagentoInventoryToolTest extends TestCase
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

    private function tool(bool $allowed = true, bool $console = false): MagentoInventoryTool
    {
        $identity = $this->createMock(IdentityResolver::class);
        $identity->method('runningInConsole')->willReturn($console);

        $acl = $this->createMock(AuthorizationInterface::class);
        $acl->method('isAllowed')->willReturn($allowed);

        return new MagentoInventoryTool($this->resourceConnection, $identity, $acl);
    }

    private function envelope(string $result): array
    {
        $decoded = json_decode($result, true);

        self::assertIsArray($decoded, 'the tool must return a JSON envelope');

        return $decoded;
    }

    public function test_a_list_over_the_byte_budget_is_capped_and_reported_in_meta(): void
    {
        $rows = [];
        for ($i = 0; $i < 400; $i++) {
            $rows[] = [
                'sku' => 'SKU-'.str_pad((string) $i, 40, '0', STR_PAD_LEFT),
                'qty' => $i,
                'is_in_stock' => 0,
                'manage_stock' => 1,
                'min_qty' => 0,
            ];
        }
        $this->connection->method('fetchAll')->willReturn($rows);

        $envelope = $this->envelope($this->tool()->execute(['threshold' => 5]));

        self::assertTrue($envelope['success']);
        self::assertTrue($envelope['meta']['truncated'], 'a list past the byte budget must report truncation');
        self::assertSame(400, $envelope['meta']['total']);
        self::assertLessThan(400, $envelope['meta']['shown']);
        self::assertCount($envelope['meta']['shown'], $envelope['data']['items']);
    }

    public function test_name(): void
    {
        self::assertSame('magento_inventory', $this->tool()->name());
    }

    public function test_required_capability_is_the_chat_resource(): void
    {
        self::assertSame('PhpClaw_Magento::phpclaw_chat', $this->tool()->requiredCapability());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        self::assertStringContainsString('CHECK Magento product stock', $this->tool()->description());
    }

    public function test_input_schema_has_sku_and_threshold(): void
    {
        $schema = $this->tool()->inputSchema();

        self::assertArrayHasKey('sku', $schema['properties']);
        self::assertArrayHasKey('threshold', $schema['properties']);
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
        $this->connection->method('fetchAll')->willReturn([]);

        $envelope = $this->envelope($this->tool(allowed: false, console: true)->execute([]));

        self::assertTrue($envelope['success']);
    }

    public function test_sku_lookup_returns_stock_data(): void
    {
        $stockRow = [['sku' => 'WIDGET-001', 'qty' => 15, 'is_in_stock' => 1, 'manage_stock' => 1]];
        $this->connection->method('fetchAll')->willReturn($stockRow);

        $result = $this->tool()->execute(['sku' => 'WIDGET-001']);
        $envelope = $this->envelope($result);

        self::assertTrue($envelope['success']);
        self::assertSame('WIDGET-001', $envelope['data']['sku']);
        self::assertSame(15, $envelope['data']['qty']);
    }

    public function test_sku_lookup_returns_a_not_found_envelope(): void
    {
        $this->connection->method('fetchAll')->willReturn([]);

        $envelope = $this->envelope($this->tool()->execute(['sku' => 'MISSING-SKU']));

        self::assertFalse($envelope['success']);
        self::assertSame('NOT_FOUND', $envelope['error']['code']);
        self::assertStringContainsString('MISSING-SKU', $envelope['error']['message']);
    }

    public function test_low_stock_scan_returns_list(): void
    {
        $rows = [
            ['sku' => 'OUT-001', 'qty' => 0, 'is_in_stock' => 0],
            ['sku' => 'LOW-002', 'qty' => 2, 'is_in_stock' => 1],
        ];
        $this->connection->method('fetchAll')->willReturn($rows);

        $result = $this->tool()->execute([]);
        $envelope = $this->envelope($result);

        self::assertTrue($envelope['success']);
        self::assertCount(2, $envelope['data']['items']);
    }

    public function test_low_stock_scan_uses_threshold_binding(): void
    {
        $capturedBindings = null;
        $this->connection->method('fetchAll')
            ->willReturnCallback(function (string $sql, array $bindings) use (&$capturedBindings): array {
                $capturedBindings = $bindings;

                return [];
            });

        $this->tool()->execute(['threshold' => 5]);

        self::assertContains(5, $capturedBindings);
    }

    public function test_low_stock_default_threshold_is_0(): void
    {
        $capturedBindings = null;
        $this->connection->method('fetchAll')
            ->willReturnCallback(function (string $sql, array $bindings) use (&$capturedBindings): array {
                $capturedBindings = $bindings;

                return [];
            });

        $this->tool()->execute([]);

        self::assertContains(0, $capturedBindings);
    }

    public function test_low_stock_respects_limit(): void
    {
        $capturedSql = '';
        $this->connection->method('fetchAll')
            ->willReturnCallback(function (string $sql) use (&$capturedSql): array {
                $capturedSql = $sql;

                return [];
            });

        $this->tool()->execute(['limit' => 10]);

        self::assertStringContainsString('LIMIT 10', $capturedSql);
    }

    public function test_low_stock_clamps_limit_to_100(): void
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
}

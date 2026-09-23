<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Tools;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PhpClaw\Magento\Model\IdentityResolver;
use PhpClaw\Magento\Tools\MagentoCategoryTool;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class MagentoCategoryToolTest extends TestCase
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

    private function tool(bool $allowed = true, bool $console = false): MagentoCategoryTool
    {
        $identity = $this->createMock(IdentityResolver::class);
        $identity->method('runningInConsole')->willReturn($console);

        $acl = $this->createMock(AuthorizationInterface::class);
        $acl->method('isAllowed')->willReturn($allowed);

        return new MagentoCategoryTool($this->resourceConnection, $identity, $acl);
    }

    private function envelope(string $result): array
    {
        $decoded = json_decode($result, true);

        self::assertIsArray($decoded, 'the tool must return a JSON envelope');

        return $decoded;
    }

    public function test_name(): void
    {
        self::assertSame('magento_categories', $this->tool()->name());
    }

    public function test_required_capability_is_the_chat_resource(): void
    {
        self::assertSame('PhpClaw_Magento::phpclaw_chat', $this->tool()->requiredCapability());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        self::assertStringContainsString('EXPLORE the Magento category tree', $this->tool()->description());
        self::assertStringContainsString('show_products=true', $this->tool()->description());
    }

    public function test_input_schema_has_expected_properties(): void
    {
        $schema = $this->tool()->inputSchema();
        self::assertArrayHasKey('category_id', $schema['properties']);
        self::assertArrayHasKey('parent_id', $schema['properties']);
        self::assertArrayHasKey('show_products', $schema['properties']);
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

    public function test_no_input_uses_root_category_2_as_parent(): void
    {
        $capturedBindings = null;
        $this->connection->method('fetchAll')
            ->willReturnCallback(function (string $_sql, array $bindings) use (&$capturedBindings): array {
                $capturedBindings = $bindings;

                return [];
            });

        $this->tool()->execute([]);

        self::assertContains(2, $capturedBindings);
    }

    public function test_parent_id_overrides_default_root(): void
    {
        $capturedBindings = null;
        $this->connection->method('fetchAll')
            ->willReturnCallback(function (string $_sql, array $bindings) use (&$capturedBindings): array {
                $capturedBindings = $bindings;

                return [];
            });

        $this->tool()->execute(['parent_id' => 10]);

        self::assertContains(10, $capturedBindings);
    }

    public function test_list_children_returns_category_data(): void
    {
        $children = [
            ['entity_id' => 3, 'parent_id' => 2, 'name' => 'Electronics', 'is_active' => 1],
            ['entity_id' => 4, 'parent_id' => 2, 'name' => 'Clothing',    'is_active' => 1],
        ];
        $this->connection->method('fetchAll')->willReturn($children);

        $envelope = $this->envelope($this->tool()->execute([]));

        self::assertTrue($envelope['success']);
        self::assertCount(2, $envelope['data']['categories']);
        self::assertSame('Electronics', $envelope['data']['categories'][0]['name']);
    }

    public function test_show_products_lists_assigned_products(): void
    {
        $products = [
            ['product_id' => 1, 'sku' => 'PHONE-001', 'name' => 'Phone Pro',  'position' => 0],
            ['product_id' => 2, 'sku' => 'PHONE-002', 'name' => 'Phone Lite', 'position' => 1],
        ];
        $this->connection->method('fetchAll')->willReturn($products);

        $envelope = $this->envelope($this->tool()->execute(['category_id' => 5, 'show_products' => true]));

        self::assertTrue($envelope['success']);
        self::assertCount(2, $envelope['data']['products']);
        self::assertSame('PHONE-001', $envelope['data']['products'][0]['sku']);
    }

    public function test_show_products_uses_category_id_binding(): void
    {
        $capturedBindings = null;
        $this->connection->method('fetchAll')
            ->willReturnCallback(function (string $_sql, array $bindings) use (&$capturedBindings): array {
                $capturedBindings = $bindings;

                return [];
            });

        $this->tool()->execute(['category_id' => 7, 'show_products' => true]);

        self::assertContains(7, $capturedBindings);
    }

    public function test_show_products_respects_limit(): void
    {
        $capturedSql = '';
        $this->connection->method('fetchAll')
            ->willReturnCallback(function (string $sql) use (&$capturedSql): array {
                $capturedSql = $sql;

                return [];
            });

        $this->tool()->execute(['category_id' => 3, 'show_products' => true, 'limit' => 10]);

        self::assertStringContainsString('LIMIT 10', $capturedSql);
    }

    public function test_a_list_over_the_byte_budget_is_capped_and_reported_in_meta(): void
    {
        $rows = [];
        for ($i = 0; $i < 400; $i++) {
            $rows[] = [
                'entity_id' => $i,
                'parent_id' => 2,
                'level' => 2,
                'position' => $i,
                'children_count' => 0,
                'path' => '1/2/'.str_pad((string) $i, 20, '0', STR_PAD_LEFT),
                'name' => 'Category '.str_pad((string) $i, 30, 'X', STR_PAD_LEFT),
                'is_active' => 1,
            ];
        }
        $this->connection->method('fetchAll')->willReturn($rows);

        $envelope = $this->envelope($this->tool()->execute([]));

        self::assertTrue($envelope['success']);
        self::assertTrue($envelope['meta']['truncated'], 'a list past the byte budget must report truncation');
        self::assertSame(400, $envelope['meta']['total']);
        self::assertLessThan(400, $envelope['meta']['shown']);
        self::assertCount($envelope['meta']['shown'], $envelope['data']['categories']);
    }
}

<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Tools;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PhpClaw\Magento\Model\IdentityResolver;
use PhpClaw\Magento\Tools\MagentoProductTool;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class MagentoProductToolTest extends TestCase
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

    private function tool(bool $allowed = true, bool $console = false): MagentoProductTool
    {
        $identity = $this->createMock(IdentityResolver::class);
        $identity->method('runningInConsole')->willReturn($console);

        $acl = $this->createMock(AuthorizationInterface::class);
        $acl->method('isAllowed')->willReturn($allowed);

        return new MagentoProductTool($this->resourceConnection, $identity, $acl);
    }

    private function envelope(string $result): array
    {
        $decoded = json_decode($result, true);

        self::assertIsArray($decoded, 'the tool must return a JSON envelope');

        return $decoded;
    }

    public function test_name(): void
    {
        self::assertSame('magento_products', $this->tool()->name());
    }

    public function test_required_capability_is_the_chat_resource(): void
    {
        self::assertSame('PhpClaw_Magento::phpclaw_chat', $this->tool()->requiredCapability());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        self::assertStringContainsString('FETCH Magento catalog products from the live database', $this->tool()->description());
    }

    public function test_input_schema_has_sku_and_query(): void
    {
        $schema = $this->tool()->inputSchema();
        self::assertArrayHasKey('sku', $schema['properties']);
        self::assertArrayHasKey('query', $schema['properties']);
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

    public function test_sku_lookup_returns_product_data(): void
    {
        $product = [[
            'entity_id' => 1, 'sku' => 'SKU-001', 'name' => 'Blue Widget',
            'price' => '29.99', 'qty' => 10, 'is_in_stock' => 1,
        ]];
        $this->connection->method('fetchAll')->willReturn($product);

        $envelope = $this->envelope($this->tool()->execute(['sku' => 'SKU-001']));

        self::assertTrue($envelope['success']);
        self::assertSame('SKU-001', $envelope['data']['product']['sku']);
        self::assertSame('Blue Widget', $envelope['data']['product']['name']);
    }

    public function test_sku_lookup_returns_a_not_found_envelope(): void
    {
        $this->connection->method('fetchAll')->willReturn([]);

        $envelope = $this->envelope($this->tool()->execute(['sku' => 'MISSING-SKU']));

        self::assertFalse($envelope['success']);
        self::assertSame('NOT_FOUND', $envelope['error']['code']);
        self::assertStringContainsString('MISSING-SKU', $envelope['error']['message']);
    }

    public function test_query_search_returns_product_list(): void
    {
        $rows = [
            ['entity_id' => 1, 'sku' => 'WIDGET-BLU', 'name' => 'Blue Widget', 'qty' => 5],
            ['entity_id' => 2, 'sku' => 'WIDGET-RED', 'name' => 'Red Widget',  'qty' => 3],
        ];
        $this->connection->method('fetchAll')->willReturn($rows);

        $envelope = $this->envelope($this->tool()->execute(['query' => 'widget']));

        self::assertTrue($envelope['success']);
        self::assertCount(2, $envelope['data']['products']);
    }

    public function test_query_search_uses_like_bindings(): void
    {
        $capturedBindings = [];
        $this->connection->method('fetchAll')
            ->willReturnCallback(function (string $_sql, array $bindings) use (&$capturedBindings): array {
                $capturedBindings = $bindings;

                return [];
            });

        $this->tool()->execute(['query' => 'widget']);

        self::assertContains('%widget%', $capturedBindings);
    }

    public function test_query_search_respects_limit(): void
    {
        $capturedSql = '';
        $this->connection->method('fetchAll')
            ->willReturnCallback(function (string $sql) use (&$capturedSql): array {
                $capturedSql = $sql;

                return [];
            });

        $this->tool()->execute(['query' => 'test', 'limit' => 5]);

        self::assertStringContainsString('LIMIT 5', $capturedSql);
    }

    public function test_query_search_clamps_limit_to_50(): void
    {
        $capturedSql = '';
        $this->connection->method('fetchAll')
            ->willReturnCallback(function (string $sql) use (&$capturedSql): array {
                $capturedSql = $sql;

                return [];
            });

        $this->tool()->execute(['query' => 'test', 'limit' => 9999]);

        self::assertStringContainsString('LIMIT 50', $capturedSql);
    }

    public function test_empty_input_lists_all_products(): void
    {
        $capturedSql = '';
        $this->connection->method('fetchAll')
            ->willReturnCallback(function (string $sql) use (&$capturedSql): array {
                $capturedSql = $sql;

                return [];
            });

        $this->tool()->execute([]);

        self::assertStringContainsString('LIKE', $capturedSql);
    }

    public function test_short_query_is_rejected_without_running_a_query(): void
    {
        $this->connection->expects(self::never())->method('fetchAll');

        $envelope = $this->envelope($this->tool()->execute(['query' => 'a']));

        self::assertFalse($envelope['success']);
        self::assertSame('INVALID_ARGUMENT', $envelope['error']['code']);
        self::assertStringContainsString('2 characters', $envelope['error']['message']);
    }

    public function test_unknown_argument_is_rejected(): void
    {
        $this->connection->expects(self::never())->method('fetchAll');

        $envelope = $this->envelope($this->tool()->execute(['nope' => 'bad']));

        self::assertFalse($envelope['success']);
        self::assertSame('UNKNOWN_ARGUMENT', $envelope['error']['code']);
    }

    public function test_search_result_carries_totals_and_limit_in_meta(): void
    {
        $rows = [
            ['entity_id' => 1, 'sku' => 'A', 'name' => 'Alpha'],
            ['entity_id' => 2, 'sku' => 'B', 'name' => 'Beta'],
        ];
        $this->connection->method('fetchAll')->willReturn($rows);

        $envelope = $this->envelope($this->tool()->execute(['query' => 'al', 'limit' => 10]));

        self::assertTrue($envelope['success']);
        self::assertSame(2, $envelope['meta']['total']);
        self::assertSame(2, $envelope['meta']['shown']);
        self::assertFalse($envelope['meta']['truncated']);
        self::assertSame(10, $envelope['meta']['limit']);
        self::assertSame('al', $envelope['meta']['query']);
        self::assertSame('search', $envelope['meta']['mode']);
    }

    public function test_sku_lookup_meta_carries_sku_and_mode(): void
    {
        $this->connection->method('fetchAll')->willReturn([
            ['entity_id' => 5, 'sku' => 'SKU-X', 'name' => 'X Product'],
        ]);

        $envelope = $this->envelope($this->tool()->execute(['sku' => 'SKU-X']));

        self::assertTrue($envelope['success']);
        self::assertSame('SKU-X', $envelope['meta']['sku']);
        self::assertSame('sku_lookup', $envelope['meta']['mode']);
    }
}

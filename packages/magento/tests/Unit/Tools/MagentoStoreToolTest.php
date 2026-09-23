<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Tools;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PhpClaw\Magento\Model\IdentityResolver;
use PhpClaw\Magento\Tools\MagentoStoreTool;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class MagentoStoreToolTest extends TestCase
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

    private function tool(bool $allowed = true, bool $console = false): MagentoStoreTool
    {
        $identity = $this->createMock(IdentityResolver::class);
        $identity->method('runningInConsole')->willReturn($console);

        $acl = $this->createMock(AuthorizationInterface::class);
        $acl->method('isAllowed')->willReturn($allowed);

        return new MagentoStoreTool($this->resourceConnection, $identity, $acl);
    }

    private function envelope(string $result): array
    {
        $decoded = json_decode($result, true);

        self::assertIsArray($decoded, 'the tool must return a JSON envelope');

        return $decoded;
    }

    public function test_name(): void
    {
        self::assertSame('magento_stores', $this->tool()->name());
    }

    public function test_required_capability_is_the_chat_resource(): void
    {
        self::assertSame('PhpClaw_Magento::phpclaw_chat', $this->tool()->requiredCapability());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        self::assertStringContainsString('LIST all Magento websites, store groups, and store views', $this->tool()->description());
    }

    public function test_input_schema_has_no_required_properties(): void
    {
        $schema = $this->tool()->inputSchema();
        self::assertNotTrue(isset($schema['required']));
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

    public function test_returns_nested_hierarchy(): void
    {
        $rows = [
            [
                'website_id' => 1,
                'website_code' => 'base',
                'website_name' => 'Main Website',
                'website_is_default' => 1,
                'group_id' => 1,
                'group_code' => 'main_website_store',
                'group_name' => 'Main Website Store',
                'root_category_id' => 2,
                'default_store_id' => 1,
                'store_id' => 1,
                'store_code' => 'default',
                'store_name' => 'Default Store View',
                'is_active' => 1,
            ],
        ];
        $this->connection->method('fetchAll')->willReturn($rows);

        $envelope = $this->envelope($this->tool()->execute([]));

        self::assertTrue($envelope['success']);
        self::assertCount(1, $envelope['data']['websites']);
        self::assertSame('Main Website', $envelope['data']['websites'][0]['website_name']);
        self::assertCount(1, $envelope['data']['websites'][0]['store_groups']);
        self::assertSame('Main Website Store', $envelope['data']['websites'][0]['store_groups'][0]['group_name']);
        self::assertCount(1, $envelope['data']['websites'][0]['store_groups'][0]['store_views']);
        self::assertSame('default', $envelope['data']['websites'][0]['store_groups'][0]['store_views'][0]['store_code']);
    }

    public function test_multiple_websites_are_separate_entries(): void
    {
        $rows = [
            [
                'website_id' => 1, 'website_code' => 'base', 'website_name' => 'Main',
                'website_is_default' => 1, 'group_id' => 1, 'group_code' => 'main',
                'group_name' => 'Main Store', 'root_category_id' => 2, 'default_store_id' => 1,
                'store_id' => 1, 'store_code' => 'default', 'store_name' => 'Default', 'is_active' => 1,
            ],
            [
                'website_id' => 2, 'website_code' => 'en', 'website_name' => 'English',
                'website_is_default' => 0, 'group_id' => 2, 'group_code' => 'en_store',
                'group_name' => 'English Store', 'root_category_id' => 3, 'default_store_id' => 2,
                'store_id' => 2, 'store_code' => 'en', 'store_name' => 'English View', 'is_active' => 1,
            ],
        ];
        $this->connection->method('fetchAll')->willReturn($rows);

        $envelope = $this->envelope($this->tool()->execute([]));

        self::assertTrue($envelope['success']);
        self::assertCount(2, $envelope['data']['websites']);
    }

    public function test_empty_store_returns_empty_array(): void
    {
        $this->connection->method('fetchAll')->willReturn([]);

        $envelope = $this->envelope($this->tool()->execute([]));

        self::assertTrue($envelope['success']);
        self::assertIsArray($envelope['data']['websites']);
        self::assertEmpty($envelope['data']['websites']);
    }

    public function test_a_list_over_the_byte_budget_is_capped_and_reported_in_meta(): void
    {
        $rows = [];
        for ($i = 1; $i <= 60; $i++) {
            $rows[] = [
                'website_id' => $i,
                'website_code' => 'website_'.str_pad((string) $i, 30, '0', STR_PAD_LEFT),
                'website_name' => 'Website Name '.str_pad((string) $i, 30, '0', STR_PAD_LEFT),
                'website_is_default' => 0,
                'group_id' => $i,
                'group_code' => 'group_code_'.str_pad((string) $i, 30, '0', STR_PAD_LEFT),
                'group_name' => 'Group Name '.str_pad((string) $i, 30, '0', STR_PAD_LEFT),
                'root_category_id' => $i * 2,
                'default_store_id' => $i,
                'store_id' => $i,
                'store_code' => 'store_code_'.str_pad((string) $i, 30, '0', STR_PAD_LEFT),
                'store_name' => 'Store Name '.str_pad((string) $i, 30, '0', STR_PAD_LEFT),
                'is_active' => 1,
            ];
        }
        $this->connection->method('fetchAll')->willReturn($rows);

        $envelope = $this->envelope($this->tool()->execute([]));

        self::assertTrue($envelope['success']);
        self::assertTrue($envelope['meta']['truncated'], 'a list past the byte budget must report truncation');
        self::assertSame(60, $envelope['meta']['total']);
        self::assertLessThan(60, $envelope['meta']['shown']);
        self::assertCount($envelope['meta']['shown'], $envelope['data']['websites']);
    }
}

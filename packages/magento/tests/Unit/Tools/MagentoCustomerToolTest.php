<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Tools;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PhpClaw\Magento\Model\IdentityResolver;
use PhpClaw\Magento\Tools\MagentoCustomerTool;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class MagentoCustomerToolTest extends TestCase
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

    private function tool(bool $allowed = true, bool $console = false): MagentoCustomerTool
    {
        $identity = $this->createMock(IdentityResolver::class);
        $identity->method('runningInConsole')->willReturn($console);

        $acl = $this->createMock(AuthorizationInterface::class);
        $acl->method('isAllowed')->willReturn($allowed);

        return new MagentoCustomerTool($this->resourceConnection, $identity, $acl);
    }

    private function envelope(string $result): array
    {
        $decoded = json_decode($result, true);

        self::assertIsArray($decoded, 'the tool must return a JSON envelope');

        return $decoded;
    }

    public function test_name(): void
    {
        self::assertSame('magento_customer', $this->tool()->name());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        self::assertStringContainsString('LOOKUP a Magento customer by email or customer_id', $this->tool()->description());
    }

    public function test_input_schema_has_email_and_customer_id(): void
    {
        $schema = $this->tool()->inputSchema();

        self::assertArrayHasKey('email', $schema['properties']);
        self::assertArrayHasKey('customer_id', $schema['properties']);
    }

    public function test_required_capability_is_the_chat_resource(): void
    {
        self::assertSame('PhpClaw_Magento::phpclaw_chat', $this->tool()->requiredCapability());
    }

    public function test_a_caller_without_the_chat_resource_is_forbidden(): void
    {
        $envelope = $this->envelope($this->tool(allowed: false)->execute(['count' => true]));

        self::assertFalse($envelope['success']);
        self::assertSame('FORBIDDEN', $envelope['error']['code']);
        self::assertStringContainsString('PhpClaw_Magento::phpclaw_chat', $envelope['error']['message']);
    }

    public function test_the_console_reaches_the_tool_without_the_chat_resource(): void
    {
        $this->connection->method('fetchOne')->willReturn('5');

        $envelope = $this->envelope($this->tool(allowed: false, console: true)->execute(['count' => true]));

        self::assertTrue($envelope['success']);
        self::assertSame(5, $envelope['data']['count']);
    }

    public function test_lookup_by_email_returns_customer_with_recent_orders(): void
    {
        $customer = [[
            'entity_id' => '7', 'email' => 'john@example.com',
            'firstname' => 'John', 'lastname' => 'Doe',
            'order_count' => '3', 'lifetime_value' => '250.00',
        ]];
        $recentOrders = [
            ['increment_id' => '000000001', 'status' => 'complete', 'grand_total' => '100.00'],
        ];

        $this->connection->method('fetchAll')
            ->willReturnOnConsecutiveCalls($customer, $recentOrders);

        $envelope = $this->envelope($this->tool()->execute(['email' => 'john@example.com']));

        self::assertTrue($envelope['success']);
        self::assertSame('john@example.com', $envelope['data']['email']);
        self::assertSame('3', $envelope['data']['order_count']);
        self::assertCount(1, $envelope['data']['recent_orders']);
    }

    public function test_lookup_by_email_uses_email_as_binding(): void
    {
        $capturedBindings = [];

        $this->connection->method('fetchAll')
            ->willReturnCallback(function (string $sql, array $bindings) use (&$capturedBindings): array {
                $capturedBindings[] = $bindings;

                return count($capturedBindings) === 1
                    ? [['entity_id' => 1, 'email' => 'a@b.com', 'firstname' => '', 'lastname' => '', 'order_count' => 0, 'lifetime_value' => 0]]
                    : [];
            });

        $this->tool()->execute(['email' => 'jane@example.com']);

        self::assertContains('jane@example.com', $capturedBindings[0]);
    }

    public function test_lookup_by_customer_id(): void
    {
        $customer = [[
            'entity_id' => '42', 'email' => 'cust@example.com',
            'firstname' => 'Jane', 'lastname' => 'Smith',
            'order_count' => '1', 'lifetime_value' => '49.99',
        ]];

        $this->connection->method('fetchAll')
            ->willReturnOnConsecutiveCalls($customer, []);

        $envelope = $this->envelope($this->tool()->execute(['customer_id' => 42]));

        self::assertTrue($envelope['success']);
        self::assertSame('42', $envelope['data']['entity_id']);
    }

    public function test_count_returns_total_customer_count(): void
    {
        $this->connection->method('fetchOne')->willReturn('123');

        $envelope = $this->envelope($this->tool()->execute(['count' => true]));

        self::assertTrue($envelope['success']);
        self::assertSame(123, $envelope['data']['count']);
        self::assertSame('count', $envelope['meta']['mode']);
    }

    public function test_customer_not_found_by_email_returns_not_found_envelope(): void
    {
        $this->connection->method('fetchAll')->willReturn([]);

        $envelope = $this->envelope($this->tool()->execute(['email' => 'nobody@example.com']));

        self::assertFalse($envelope['success']);
        self::assertSame('NOT_FOUND', $envelope['error']['code']);
        self::assertStringContainsString("email 'nobody@example.com'", $envelope['error']['message']);
    }

    public function test_customer_not_found_by_id_returns_not_found_envelope(): void
    {
        $this->connection->method('fetchAll')->willReturn([]);

        $envelope = $this->envelope($this->tool()->execute(['customer_id' => 9999]));

        self::assertFalse($envelope['success']);
        self::assertSame('NOT_FOUND', $envelope['error']['code']);
        self::assertStringContainsString('ID 9999', $envelope['error']['message']);
    }

    public function test_missing_identifiers_returns_missing_argument_envelope(): void
    {
        $envelope = $this->envelope($this->tool()->execute([]));

        self::assertFalse($envelope['success']);
        self::assertSame('MISSING_ARGUMENT', $envelope['error']['code']);
        self::assertStringContainsString('email', $envelope['error']['message']);
        self::assertStringContainsString('customer_id', $envelope['error']['message']);
    }
}

<?php

declare(strict_types=1);

namespace PhpClaw\WooCommerce\Tests\Unit\Tools;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\WooCommerce\Tools\CustomerTool;
use PhpClaw\WordPress\Tests\Concerns\StubsCapabilities;
use PHPUnit\Framework\TestCase;
use WC_Customer;

final class CustomerToolTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use StubsCapabilities;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->grantCapability('phpclaw_use_chat');
        Functions\when('get_userdata')->justReturn(new \stdClass);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function makeCustomer(int $id, int $orderCount, string $totalSpent): WC_Customer
    {
        return new WC_Customer($id, $orderCount, $totalSpent);
    }

    private function toolReturning(int $orderCount = 0, string $totalSpent = '0.00'): CustomerTool
    {
        return new CustomerTool(
            fn (int $id): WC_Customer => $this->makeCustomer($id, $orderCount, $totalSpent),
        );
    }

    public function test_name_is_wc_get_customer(): void
    {
        self::assertSame('wc_get_customer', (new CustomerTool)->name());
    }

    public function test_input_schema_declares_customer_id_and_is_closed(): void
    {
        $schema = (new CustomerTool)->inputSchema();

        self::assertArrayHasKey('customer_id', $schema['properties']);
        self::assertFalse($schema['additionalProperties']);
    }

    public function test_input_schema_does_not_require_customer_id_so_schema_mode_is_reachable(): void
    {
        $schema = (new CustomerTool)->inputSchema();

        self::assertSame([], $schema['required']);
    }

    public function test_execute_returns_customer_stats(): void
    {
        $result = json_decode($this->toolReturning(5, '499.95')->execute(['customer_id' => 42]), true);

        self::assertTrue($result['success']);
        self::assertSame('lookup', $result['meta']['mode']);
        self::assertSame(42, $result['data']['customers'][0]['customer_id']);
        self::assertSame(5, $result['data']['customers'][0]['order_count']);
        self::assertSame('499.95', $result['data']['customers'][0]['total_spent']);
    }

    public function test_execute_passes_customer_id_to_resolver(): void
    {
        $resolvedId = null;
        $tool = new CustomerTool(function (int $id) use (&$resolvedId): WC_Customer {
            $resolvedId = $id;

            return $this->makeCustomer($id, 0, '0.00');
        });

        $tool->execute(['customer_id' => 99]);

        self::assertSame(99, $resolvedId);
    }

    public function test_execute_returns_zero_stats_for_new_customer(): void
    {
        $result = json_decode($this->toolReturning()->execute(['customer_id' => 1]), true);

        self::assertSame(0, $result['data']['customers'][0]['order_count']);
        self::assertSame('0.00', $result['data']['customers'][0]['total_spent']);
    }

    public function test_execute_returns_invalid_customer_id_for_zero(): void
    {
        $result = json_decode($this->toolReturning()->execute(['customer_id' => 0]), true);

        self::assertFalse($result['success']);
        self::assertSame('INVALID_CUSTOMER_ID', $result['error']['code']);
    }

    public function test_execute_returns_invalid_customer_id_for_negative(): void
    {
        $result = json_decode($this->toolReturning()->execute(['customer_id' => -5]), true);

        self::assertFalse($result['success']);
        self::assertSame('INVALID_CUSTOMER_ID', $result['error']['code']);
    }

    public function test_execute_returns_invalid_argument_when_customer_id_missing(): void
    {
        $result = json_decode($this->toolReturning()->execute([]), true);

        self::assertFalse($result['success']);
        self::assertSame('INVALID_ARGUMENT', $result['error']['code']);
    }

    public function test_execute_throws_tool_exception_on_resolver_failure(): void
    {
        $tool = new CustomerTool(fn (int $id) => throw new \RuntimeException('WC auth error'));

        $this->expectException(ToolException::class);
        $tool->execute(['customer_id' => 1]);
    }

    public function test_it_returns_forbidden_without_the_required_capability(): void
    {
        $this->denyAllCapabilities();

        $this->assertForbiddenEnvelope($this->toolReturning()->execute(['customer_id' => 42]));
    }

    public function test_forbidden_resolves_no_customer(): void
    {
        $this->denyAllCapabilities();
        $resolved = false;

        $tool = new CustomerTool(function (int $id) use (&$resolved): WC_Customer {
            $resolved = true;

            return $this->makeCustomer($id, 0, '0.00');
        });

        $tool->execute(['customer_id' => 42]);

        self::assertFalse($resolved);
    }

    public function test_it_returns_unknown_argument_instead_of_throwing(): void
    {
        $result = json_decode($this->toolReturning()->execute(['customer_id' => 1, 'nope' => 2]), true);

        self::assertFalse($result['success']);
        self::assertSame('UNKNOWN_ARGUMENT', $result['error']['code']);
        self::assertContains('customer_id', $result['error']['accepted_arguments']);
    }

    public function test_unknown_customer_returns_not_found_rather_than_throwing(): void
    {
        Functions\when('get_userdata')->justReturn(false);

        $result = json_decode($this->toolReturning()->execute(['customer_id' => 999999]), true);

        self::assertFalse($result['success']);
        self::assertSame('CUSTOMER_NOT_FOUND', $result['error']['code']);
    }

    public function test_unknown_customer_resolves_no_customer(): void
    {
        Functions\when('get_userdata')->justReturn(false);
        $resolved = false;

        $tool = new CustomerTool(function (int $id) use (&$resolved): WC_Customer {
            $resolved = true;

            return $this->makeCustomer($id, 0, '0.00');
        });

        $tool->execute(['customer_id' => 999999]);

        self::assertFalse($resolved);
    }

    public function test_schema_mode_lists_returned_and_withheld_fields(): void
    {
        $result = json_decode($this->toolReturning()->execute(['schema' => true]), true);

        self::assertTrue($result['success']);
        self::assertSame('schema', $result['meta']['mode']);
        self::assertFalse($result['meta']['database_query_performed']);
        self::assertSame(
            ['customer_id', 'order_count', 'total_spent'],
            $result['data']['fields_returned'],
        );
        self::assertContains('billing_address', $result['data']['fields_never_returned']);
        self::assertSame('phpclaw_use_chat', $result['data']['woocommerce_capability']);
    }

    public function test_schema_mode_needs_no_customer_id(): void
    {
        $result = json_decode((new CustomerTool)->execute(['schema' => true]), true);

        self::assertTrue($result['success']);
    }

    public function test_response_carries_no_customer_pii(): void
    {
        $result = json_decode($this->toolReturning(3, '10.00')->execute(['customer_id' => 7]), true);

        $row = $result['data']['customers'][0];

        self::assertSame(['customer_id', 'order_count', 'total_spent'], array_keys($row));

        foreach (['email', 'phone', 'billing_address', 'shipping_address', 'payment_tokens'] as $field) {
            self::assertArrayNotHasKey($field, $row);
        }
    }
}

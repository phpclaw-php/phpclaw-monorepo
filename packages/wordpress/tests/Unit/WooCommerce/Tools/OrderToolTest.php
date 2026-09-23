<?php

declare(strict_types=1);

namespace PhpClaw\WooCommerce\Tests\Unit\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\WooCommerce\Tools\OrderTool;
use PhpClaw\WordPress\Tests\Concerns\StubsCapabilities;
use PHPUnit\Framework\TestCase;
use WC_Order;

final class OrderToolTest extends TestCase
{
    use StubsCapabilities;

    protected function setUp(): void
    {
        parent::setUp();
        \Brain\Monkey\setUp();

        $this->grantCapability('phpclaw_use_chat');
        \Brain\Monkey\Functions\when('get_option')->justReturn('no');
    }

    protected function tearDown(): void
    {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    private function makeOrder(int $id, string $status, string $total): WC_Order
    {
        return new WC_Order($id, $status, $total);
    }

    public function test_name_is_wc_get_orders(): void
    {
        self::assertSame('wc_get_orders', (new OrderTool)->name());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        self::assertStringContainsString(
            'Read WooCommerce orders',
            (new OrderTool)->description(),
        );
    }

    public function test_input_schema_defines_status_and_limit(): void
    {
        $schema = (new OrderTool)->inputSchema();
        self::assertArrayHasKey('status', $schema['properties']);
        self::assertArrayHasKey('limit', $schema['properties']);
    }

    public function test_execute_maps_order_fields(): void
    {
        $tool = new OrderTool(fn (array $args): array => [
            $this->makeOrder(42, 'on-hold', '149.50'),
        ]);

        $result = json_decode($tool->execute([]), true);

        self::assertSame(42, $result['data']['orders'][0]['id']);
        self::assertSame('on-hold', $result['data']['orders'][0]['status']);
        self::assertSame('149.50', $result['data']['orders'][0]['total']);
    }

    public function test_execute_returns_empty_when_no_orders(): void
    {
        $tool = new OrderTool(fn (array $args): array => []);

        $result = json_decode($tool->execute([]), true);

        self::assertSame(0, $result['meta']['count']);
        self::assertSame([], $result['data']['orders']);
    }

    public function test_execute_passes_status_filter(): void
    {
        $passedArgs = [];
        $tool = new OrderTool(function (array $args) use (&$passedArgs): array {
            $passedArgs = $args;

            return [];
        });

        $tool->execute(['status' => 'processing']);

        self::assertSame('processing', $passedArgs['status']);
    }

    public function test_execute_skips_status_filter_for_all(): void
    {
        $passedArgs = [];
        $tool = new OrderTool(function (array $args) use (&$passedArgs): array {
            $passedArgs = $args;

            return [];
        });

        $tool->execute(['status' => 'all']);

        self::assertArrayNotHasKey('status', $passedArgs);
    }

    public function test_limit_above_the_maximum_is_rejected_not_capped(): void
    {
        $r = json_decode((new OrderTool)->execute(['limit' => 999]), true);

        self::assertFalse($r['success']);
        self::assertSame('INVALID_LIMIT', $r['error']['code']);
    }

    public function test_execute_returns_multiple_orders(): void
    {
        $tool = new OrderTool(fn (array $args): array => [
            $this->makeOrder(1, 'processing', '10.00'),
            $this->makeOrder(2, 'completed', '20.00'),
            $this->makeOrder(3, 'on-hold', '30.00'),
        ]);

        $result = json_decode($tool->execute([]), true);

        self::assertSame(3, $result['meta']['count']);
    }

    public function test_execute_throws_tool_exception_on_fetcher_failure(): void
    {
        $tool = new OrderTool(fn (array $args): array => throw new \RuntimeException('DB down'));

        $this->expectException(ToolException::class);
        $tool->execute([]);
    }

    public function test_it_returns_forbidden_without_the_required_capability(): void
    {
        $this->denyAllCapabilities();

        $this->assertForbiddenEnvelope((new OrderTool)->execute([]));
    }

    public function test_forbidden_fetches_no_orders(): void
    {
        $this->denyAllCapabilities();
        $fetched = false;

        $tool = new OrderTool(function (array $args) use (&$fetched): array {
            $fetched = true;

            return [];
        });

        $tool->execute([]);

        self::assertFalse($fetched);
    }

    public function test_it_returns_unknown_argument_instead_of_throwing(): void
    {
        $r = json_decode((new OrderTool)->execute(['phpclaw_bogus' => 1]), true);

        self::assertFalse($r['success']);
        self::assertSame('UNKNOWN_ARGUMENT', $r['error']['code']);
    }

    public function test_payment_token_field_is_rejected_not_dropped(): void
    {
        $r = json_decode((new OrderTool)->execute(['columns' => ['id', 'transaction_id']]), true);

        self::assertFalse($r['success']);
        self::assertSame('BLOCKED_COLUMN', $r['error']['code']);
        self::assertContains('transaction_id', $r['error']['blocked_columns']);
    }

    public function test_gateway_meta_prefix_is_rejected(): void
    {
        $r = json_decode((new OrderTool)->execute(['columns' => ['_stripe_source_id']]), true);

        self::assertFalse($r['success']);
        self::assertSame('BLOCKED_COLUMN', $r['error']['code']);
    }

    public function test_sensitive_fields_require_explicit_request_and_warn(): void
    {
        $tool = new OrderTool(fn (array $args): array => [$this->makeOrder(7, 'completed', '10.00')]);

        $plain = json_decode($tool->execute([]), true);
        self::assertArrayNotHasKey('billing_email', $plain['data']['orders'][0]);
        self::assertSame([], $plain['warnings']);

        $withPii = json_decode($tool->execute(['columns' => ['id', 'billing_email']]), true);
        self::assertContains('SENSITIVE_DATA', array_column($withPii['warnings'], 'code'));
        self::assertContains('billing_email', $withPii['meta']['sensitive_fields_returned']);
    }

    public function test_checkout_typed_fields_are_marked_untrusted(): void
    {
        $tool = new OrderTool(fn (array $args): array => [$this->makeOrder(7, 'completed', '10.00')]);

        $result = json_decode($tool->execute(['columns' => ['id', 'billing_phone', 'billing_city']]), true);

        self::assertNotSame([], $result['data']['orders'], 'zero rows would prove nothing');
        self::assertContains('UNTRUSTED_CONTENT', array_column($result['warnings'], 'code'));
        self::assertSame(
            ['billing_phone', 'billing_city'],
            $result['meta']['untrusted_fields_returned'],
        );
    }

    public function test_billing_email_is_sensitive_but_not_untrusted(): void
    {
        $tool = new OrderTool(fn (array $args): array => [$this->makeOrder(7, 'completed', '10.00')]);

        $result = json_decode($tool->execute(['columns' => ['id', 'billing_email']]), true);
        $codes = array_column($result['warnings'], 'code');

        self::assertContains('SENSITIVE_DATA', $codes);
        self::assertNotContains(
            'UNTRUSTED_CONTENT',
            $codes,
            'WooCommerce validates and sanitises the email, so it is not a delivery path',
        );
    }

    public function test_no_untrusted_warning_on_the_default_field_set(): void
    {
        $tool = new OrderTool(fn (array $args): array => [$this->makeOrder(7, 'completed', '10.00')]);

        $result = json_decode($tool->execute([]), true);

        self::assertNotSame([], $result['data']['orders']);
        self::assertNotContains('UNTRUSTED_CONTENT', array_column($result['warnings'], 'code'));
    }

    public function test_wildcard_excludes_expensive_fields(): void
    {
        $tool = new OrderTool(fn (array $args): array => [$this->makeOrder(7, 'completed', '10.00')]);

        $r = json_decode($tool->execute(['columns' => ['*']]), true);

        self::assertNotContains('item_count', $r['meta']['columns_returned']);
        self::assertContains('id', $r['meta']['columns_returned']);
    }

    public function test_bad_status_is_rejected(): void
    {
        $r = json_decode((new OrderTool)->execute(['status' => 'not-a-status']), true);

        self::assertFalse($r['success']);
        self::assertSame('INVALID_STATUS', $r['error']['code']);
    }

    public function test_meta_reports_order_storage(): void
    {
        $tool = new OrderTool(fn (array $args): array => [$this->makeOrder(7, 'completed', '10.00')]);

        $r = json_decode($tool->execute([]), true);

        self::assertContains($r['meta']['order_storage'], ['hpos', 'legacy_posts']);
    }

    public function test_paginated_fetcher_shape_yields_a_real_total(): void
    {
        $tool = new OrderTool(fn (array $args): object => (object) [
            'orders' => [$this->makeOrder(1, 'completed', '5.00')],
            'total' => 9,
        ]);

        $r = json_decode($tool->execute(['limit' => 1]), true);

        self::assertSame(9, $r['meta']['total']);
        self::assertTrue($r['meta']['has_more']);
        self::assertSame(1, $r['meta']['next_offset']);
    }

    public function test_contract_accessors_report_the_declared_capability_risk_and_examples(): void
    {
        $tool = new OrderTool;

        self::assertSame('woocommerce.orders.read', $tool->capability());
        self::assertSame('phpclaw_use_chat', $tool->requiredCapability());
        self::assertSame('read', $tool->risk());
        self::assertTrue($tool->isIdempotent());
        self::assertNotSame([], OrderTool::examples());
    }

    public function test_schema_mode_describes_the_tool_without_running_a_query(): void
    {
        $result = json_decode((new OrderTool)->execute(['schema' => true]), true);

        self::assertTrue($result['success']);
        self::assertSame('schema', $result['meta']['mode']);
        self::assertSame('woocommerce.orders.read', $result['data']['phpclaw_capability']);
        self::assertSame('read', $result['data']['risk']);
        self::assertTrue($result['data']['idempotent']);
        self::assertContains('schema', $result['data']['modes']);
        self::assertNotSame([], $result['data']['examples']);
    }
}

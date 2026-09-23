<?php

declare(strict_types=1);

namespace PhpClaw\WooCommerce\Tests\Unit\Tools;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\WooCommerce\Tools\ReportTool;
use PhpClaw\WordPress\Tests\Concerns\StubsCapabilities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReportTool::class)]
final class ReportToolTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use StubsCapabilities;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->grantCapability('phpclaw_use_chat');
        Functions\when('get_woocommerce_currency')->justReturn('USD');
        Functions\when('current_time')->alias(static fn (string $f) => date($f));
        Functions\when('wp_date')->alias(static fn (string $f, ?int $t = null) => date($f, $t ?? time()));
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function toolOverOrders(array $orders): ReportTool
    {
        return new ReportTool(static fn (array $args): array => $orders);
    }

    private function makeOrder(string $total, array $items): object
    {
        $lineItems = [];

        foreach ($items as [$name, $qty, $productId]) {
            $item = \Mockery::mock('stdClass');
            $item->shouldReceive('get_name')->andReturn($name);
            $item->shouldReceive('get_quantity')->andReturn($qty);
            $item->shouldReceive('get_product_id')->andReturn($productId);
            $lineItems[] = $item;
        }

        $order = \Mockery::mock('WC_Order');
        $order->shouldReceive('get_total')->andReturn($total);
        $order->shouldReceive('get_items')->andReturn($lineItems);

        return $order;
    }

    public function test_name_is_wc_report(): void
    {
        self::assertSame('wc_report', (new ReportTool)->name());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        self::assertStringContainsString(
            'WooCommerce sales report',
            (new ReportTool)->description(),
        );
    }

    public function test_input_schema_has_period(): void
    {
        $schema = (new ReportTool)->inputSchema();
        self::assertArrayHasKey('period', $schema['properties']);
        self::assertArrayHasKey('top_products', $schema['properties']);
    }

    public function test_execute_returns_revenue_and_order_count(): void
    {
        $result = json_decode($this->toolOverOrders([
            $this->makeOrder('99.99', [['Widget', 2, 7]]),
        ])->execute(['period' => 'this_month']), true);

        self::assertTrue($result['success']);
        self::assertSame(1, $result['data']['order_count']);
        self::assertSame(99.99, $result['data']['total_revenue']);
        self::assertSame(99.99, $result['data']['avg_order_value']);
        self::assertSame('USD', $result['meta']['currency']);
        self::assertSame('Widget', $result['data']['top_products'][0]['name']);
        self::assertSame(2, $result['data']['top_products'][0]['quantity_sold']);
        self::assertSame('wc_orders_api', $result['meta']['source']);
    }

    public function test_execute_returns_zero_when_no_orders(): void
    {
        $result = json_decode($this->toolOverOrders([])->execute([]), true);

        self::assertSame(0, $result['data']['order_count']);
        self::assertEqualsWithDelta(0.0, $result['data']['total_revenue'], 0.001);
        self::assertEqualsWithDelta(0.0, $result['data']['avg_order_value'], 0.001);
        self::assertSame('this_month', $result['meta']['period']);
    }

    public function test_a_range_beyond_the_maximum_is_clamped_and_warned(): void
    {
        $result = json_decode($this->toolOverOrders([])->execute([
            'period' => 'custom',
            'date_from' => '2015-01-01',
            'date_to' => '2025-01-01',
        ]), true);

        self::assertTrue($result['meta']['range_clamped']);
        self::assertSame(366, $result['meta']['range_days']);
        self::assertSame('RANGE_CLAMPED', $result['warnings'][0]['code']);
        self::assertSame('2025-01-01', $result['meta']['date_to']);
        self::assertSame('2024-01-02', $result['meta']['date_from']);
    }

    public function test_meta_reports_the_range_actually_applied(): void
    {
        $result = json_decode($this->toolOverOrders([])->execute([
            'period' => 'custom',
            'date_from' => '2024-03-01',
            'date_to' => '2024-03-31',
        ]), true);

        self::assertSame('2024-03-01', $result['meta']['date_from']);
        self::assertSame('2024-03-31', $result['meta']['date_to']);
        self::assertSame(31, $result['meta']['range_days']);
        self::assertFalse($result['meta']['range_clamped']);
    }

    public function test_money_always_carries_a_currency(): void
    {
        $result = json_decode($this->toolOverOrders([
            $this->makeOrder('10.00', []),
        ])->execute([]), true);

        self::assertArrayHasKey('currency', $result['meta']);
        self::assertSame('USD', $result['meta']['currency']);
    }

    public function test_exceeding_the_order_cap_warns_rather_than_truncating_silently(): void
    {
        $orders = [];
        for ($i = 0; $i < 501; $i++) {
            $orders[] = $this->makeOrder('1.00', []);
        }

        $result = json_decode($this->toolOverOrders($orders)->execute([]), true);

        self::assertSame(500, $result['data']['order_count']);
        self::assertSame('RESULT_TRUNCATED', $result['warnings'][0]['code']);
        self::assertStringContainsString('lower bound', $result['warnings'][0]['message']);
    }

    public function test_analytics_unavailable_is_reported(): void
    {
        $result = json_decode($this->toolOverOrders([
            $this->makeOrder('5.00', []),
        ])->execute([]), true);

        self::assertSame('wc_orders_api', $result['meta']['source']);
        self::assertSame('ANALYTICS_UNAVAILABLE', $result['warnings'][0]['code']);
    }

    public function test_it_returns_forbidden_without_the_required_capability(): void
    {
        $this->denyAllCapabilities();

        $this->assertForbiddenEnvelope((new ReportTool)->execute([]));
    }

    public function test_forbidden_runs_no_query(): void
    {
        $this->denyAllCapabilities();
        $fetched = false;

        $tool = new ReportTool(function (array $args) use (&$fetched): array {
            $fetched = true;

            return [];
        });

        $tool->execute([]);

        self::assertFalse($fetched);
    }

    public function test_bad_period_is_rejected(): void
    {
        $r = json_decode((new ReportTool)->execute(['period' => 'last_decade']), true);

        self::assertFalse($r['success']);
        self::assertSame('INVALID_PERIOD', $r['error']['code']);
    }

    public function test_malformed_date_is_rejected(): void
    {
        $r = json_decode((new ReportTool)->execute(['period' => 'custom', 'date_from' => '01-01-2024']), true);

        self::assertFalse($r['success']);
        self::assertSame('INVALID_DATE', $r['error']['code']);
    }

    public function test_it_returns_unknown_argument_instead_of_throwing(): void
    {
        $r = json_decode((new ReportTool)->execute(['phpclaw_bogus' => 1]), true);

        self::assertFalse($r['success']);
        self::assertSame('UNKNOWN_ARGUMENT', $r['error']['code']);
    }

    public function test_fallback_requests_a_stable_secondary_sort(): void
    {
        $passed = [];
        $tool = new ReportTool(function (array $args) use (&$passed): array {
            $passed = $args;

            return [];
        });

        $tool->execute([]);

        self::assertSame(['date' => 'DESC', 'ID' => 'DESC'], $passed['orderby']);
    }

    public function test_contract_accessors_report_the_declared_capability_risk_and_examples(): void
    {
        $tool = new ReportTool;

        self::assertSame('woocommerce.reports.read', $tool->capability());
        self::assertSame('phpclaw_use_chat', $tool->requiredCapability());
        self::assertSame('read', $tool->risk());
        self::assertTrue($tool->isIdempotent());
        self::assertNotSame([], ReportTool::examples());
    }

    public function test_schema_mode_describes_the_tool_without_running_a_query(): void
    {
        $result = json_decode((new ReportTool)->execute(['schema' => true]), true);

        self::assertTrue($result['success']);
        self::assertSame('schema', $result['meta']['mode']);
        self::assertSame('woocommerce.reports.read', $result['data']['phpclaw_capability']);
        self::assertSame('read', $result['data']['risk']);
        self::assertTrue($result['data']['idempotent']);
        self::assertContains('schema', $result['data']['modes']);
        self::assertNotSame([], $result['data']['examples']);
    }

    public function test_analytics_tables_are_used_in_preference_to_the_order_api(): void
    {
        Functions\when('wc_get_product')->justReturn(null);

        global $wpdb;
        $wpdb = new class
        {
            public string $prefix = 'wp_';

            public function prepare(string $sql, mixed ...$args): string
            {
                return $sql.'||'.implode(',', array_map(static fn (mixed $a): string => (string) $a, $args));
            }

            public function get_var(string $sql): string|int|null
            {
                if (str_contains($sql, 'SHOW TABLES LIKE')) {
                    return explode('||', $sql)[1];
                }

                return 4;
            }

            public function get_row(string $sql, string $output = 'OBJECT'): ?object
            {
                $row = new \stdClass;
                $row->order_count = 12;
                $row->revenue = '3450.75';

                return $row;
            }

            public function get_results(string $sql, string $output = 'OBJECT'): ?array
            {
                $row = new \stdClass;
                $row->product_id = 5;
                $row->qty = 9;

                return [$row];
            }
        };

        $failIfCalled = static function (array $args): array {
            throw new \RuntimeException('the order API fallback must not run when Analytics answers');
        };

        $result = json_decode((new ReportTool($failIfCalled))->execute([]), true);

        $GLOBALS['wpdb'] = null;

        self::assertTrue($result['success']);
        self::assertSame('wc_order_stats', $result['meta']['source']);
        self::assertSame(12, $result['data']['order_count']);
        self::assertSame(3450.75, $result['data']['total_revenue']);
        self::assertSame(287.56, $result['data']['avg_order_value']);
        self::assertSame(4, $result['data']['unique_products']);
        self::assertSame(5, $result['data']['top_products'][0]['product_id']);
        self::assertSame(9, $result['data']['top_products'][0]['quantity_sold']);
        self::assertSame([], $result['warnings'], 'the Analytics path raises no fallback warning');
    }
}

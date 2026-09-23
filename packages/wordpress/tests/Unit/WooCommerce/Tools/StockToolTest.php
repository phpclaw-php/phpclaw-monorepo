<?php

declare(strict_types=1);

namespace PhpClaw\WooCommerce\Tests\Unit\Tools;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\WooCommerce\Tools\StockTool;
use PhpClaw\WordPress\Tests\Concerns\StubsCapabilities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StockTool::class)]
final class StockToolTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use StubsCapabilities;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->grantCapability('phpclaw_use_chat');
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function makeProduct(int $id, string $name, string $sku, int $qty, string $status, bool $manage = true): object
    {
        $p = \Mockery::mock('WC_Product');
        $p->shouldReceive('get_id')->andReturn($id);
        $p->shouldReceive('get_name')->andReturn($name);
        $p->shouldReceive('get_sku')->andReturn($sku);
        $p->shouldReceive('get_stock_quantity')->andReturn($qty);
        $p->shouldReceive('get_stock_status')->andReturn($status);
        $p->shouldReceive('managing_stock')->andReturn($manage);
        $p->shouldReceive('get_backorders')->andReturn('no');
        $p->shouldReceive('get_price')->andReturn('19.99');

        return $p;
    }

    private function pagingFetcher(array $all): callable
    {
        return static function (array $args) use ($all): object {
            $limit = (int) ($args['limit'] ?? 10);
            $offset = (int) ($args['offset'] ?? 0);

            return (object) [
                'products' => array_slice($all, $offset, $limit),
                'total' => count($all),
            ];
        };
    }

    public function test_name_is_wc_stock(): void
    {
        self::assertSame('wc_stock', (new StockTool)->name());
    }

    public function test_input_schema_has_filter(): void
    {
        $schema = (new StockTool)->inputSchema();
        self::assertArrayHasKey('filter', $schema['properties']);
    }

    public function test_execute_returns_products_with_stock_info(): void
    {
        Functions\stubs(['get_option' => fn () => 2]);

        $tool = new StockTool($this->pagingFetcher([
            $this->makeProduct(1, 'Widget', 'W-001', 5, 'instock'),
            $this->makeProduct(2, 'Gadget', 'G-001', 0, 'outofstock'),
        ]));

        $result = json_decode($tool->execute([]), true);

        self::assertCount(2, $result['data']['products']);
        self::assertSame('instock', $result['data']['products'][0]['stock_status']);
        self::assertSame(0, $result['data']['products'][1]['stock_qty']);
    }

    public function test_execute_detects_low_stock(): void
    {
        Functions\stubs(['get_option' => fn () => 2]);

        $tool = new StockTool($this->pagingFetcher([
            $this->makeProduct(1, 'Low Item', 'L-001', 1, 'instock'),
        ]));

        $result = json_decode($tool->execute([]), true);

        self::assertTrue($result['data']['products'][0]['low_stock']);
        self::assertSame(2, $result['meta']['low_stock_threshold']);
    }

    public function test_execute_returns_empty_when_no_products(): void
    {
        Functions\stubs(['get_option' => fn () => 2]);

        $result = json_decode((new StockTool($this->pagingFetcher([])))->execute([]), true);

        self::assertSame([], $result['data']['products']);
        self::assertSame(0, $result['meta']['count']);
    }

    public function test_it_returns_low_stock_skus_that_sort_after_the_first_page(): void
    {
        $healthy = [];
        for ($i = 1; $i <= 55; $i++) {
            $healthy[] = $this->makeProduct($i, sprintf('Product %02d', $i), "SKU-{$i}", 20, 'instock', true);
        }

        $lowStock = [
            $this->makeProduct(56, 'Zebra Widget', 'Z-001', 1, 'instock', true),
            $this->makeProduct(57, 'Zeta Gadget', 'Z-002', 2, 'instock', true),
        ];

        Functions\stubs(['get_option' => fn () => 2]);

        $tool = new StockTool($this->pagingFetcher(array_merge($healthy, $lowStock)));

        $result = json_decode($tool->execute(['filter' => 'lowstock', 'limit' => 20]), true);

        self::assertSame(2, $result['meta']['count']);
        $skus = array_column($result['data']['products'], 'sku');
        self::assertContains('Z-001', $skus);
        self::assertContains('Z-002', $skus);
        self::assertArrayNotHasKey('scan_truncated', $result['meta']);
    }

    public function test_lowstock_filter_slices_to_limit_after_finding_all_matches(): void
    {
        $lowStock = [];
        for ($i = 1; $i <= 10; $i++) {
            $lowStock[] = $this->makeProduct($i, "Low Item {$i}", "L-{$i}", 1, 'instock', true);
        }

        Functions\stubs(['get_option' => fn () => 2]);

        $tool = new StockTool($this->pagingFetcher($lowStock));

        $result = json_decode($tool->execute(['filter' => 'lowstock', 'limit' => 3]), true);

        self::assertSame(3, $result['meta']['count']);
        self::assertSame(10, $result['meta']['total']);
        self::assertTrue($result['meta']['has_more']);
    }

    public function test_lowstock_scan_is_bounded_and_reports_truncation(): void
    {
        $many = [];
        for ($i = 1; $i <= 1500; $i++) {
            $many[] = $this->makeProduct($i, "Item {$i}", "S-{$i}", 20, 'instock', true);
        }

        Functions\stubs(['get_option' => fn () => 2]);

        $tool = new StockTool($this->pagingFetcher($many));

        $result = json_decode($tool->execute(['filter' => 'lowstock']), true);

        self::assertTrue($result['meta']['scan_truncated']);
        self::assertSame(1000, $result['meta']['scanned']);
        self::assertSame('SCAN_TRUNCATED', $result['warnings'][0]['code']);
    }

    public function test_query_requests_a_stable_secondary_sort(): void
    {
        Functions\stubs(['get_option' => fn () => 2]);

        $passed = [];
        $tool = new StockTool(function (array $args) use (&$passed): object {
            $passed = $args;

            return (object) ['products' => [], 'total' => 0];
        });

        $tool->execute([]);

        self::assertSame(['title' => 'ASC', 'ID' => 'ASC'], $passed['orderby']);
    }

    public function test_it_returns_forbidden_without_the_required_capability(): void
    {
        $this->denyAllCapabilities();

        $this->assertForbiddenEnvelope((new StockTool)->execute([]));
    }

    public function test_forbidden_reads_no_stock(): void
    {
        $this->denyAllCapabilities();
        $fetched = false;

        $tool = new StockTool(function (array $args) use (&$fetched): object {
            $fetched = true;

            return (object) ['products' => [], 'total' => 0];
        });

        $tool->execute([]);

        self::assertFalse($fetched);
    }

    public function test_download_urls_are_rejected_not_dropped(): void
    {
        $r = json_decode((new StockTool)->execute(['columns' => ['id', 'download_urls']]), true);

        self::assertFalse($r['success']);
        self::assertSame('BLOCKED_COLUMN', $r['error']['code']);
    }

    public function test_cost_of_goods_requires_explicit_request_and_warns(): void
    {
        Functions\stubs(['get_option' => fn () => 2]);

        $product = $this->makeProduct(1, 'Widget', 'W-1', 5, 'instock');
        $product->shouldReceive('get_meta')->andReturn('4.20');

        $tool = new StockTool($this->pagingFetcher([$product]));

        $plain = json_decode($tool->execute([]), true);
        self::assertArrayNotHasKey('cost_of_goods', $plain['data']['products'][0]);

        $withCogs = json_decode($tool->execute(['columns' => ['id', 'cost_of_goods']]), true);
        self::assertSame('4.20', $withCogs['data']['products'][0]['cost_of_goods']);
        self::assertSame('SENSITIVE_DATA', $withCogs['warnings'][0]['code']);
    }

    public function test_bad_filter_is_rejected(): void
    {
        $r = json_decode((new StockTool)->execute(['filter' => 'nope']), true);

        self::assertFalse($r['success']);
        self::assertSame('INVALID_FILTER', $r['error']['code']);
    }

    public function test_it_returns_unknown_argument_instead_of_throwing(): void
    {
        $r = json_decode((new StockTool)->execute(['phpclaw_bogus' => 1]), true);

        self::assertFalse($r['success']);
        self::assertSame('UNKNOWN_ARGUMENT', $r['error']['code']);
    }

    public function test_contract_accessors_report_the_declared_capability_risk_and_examples(): void
    {
        $tool = new StockTool;

        self::assertSame('woocommerce.stock.read', $tool->capability());
        self::assertSame('phpclaw_use_chat', $tool->requiredCapability());
        self::assertSame('read', $tool->risk());
        self::assertTrue($tool->isIdempotent());
        self::assertNotSame([], StockTool::examples());
    }

    public function test_schema_mode_describes_the_tool_without_running_a_query(): void
    {
        Functions\when('get_option')->justReturn('3');

        $result = json_decode((new StockTool)->execute(['schema' => true]), true);

        self::assertTrue($result['success']);
        self::assertSame('schema', $result['meta']['mode']);
        self::assertSame('woocommerce.stock.read', $result['data']['phpclaw_capability']);
        self::assertSame('read', $result['data']['risk']);
        self::assertTrue($result['data']['idempotent']);
        self::assertContains('schema', $result['data']['modes']);
        self::assertNotSame([], $result['data']['examples']);
    }

    public function test_variation_stock_is_summed_through_one_batched_child_lookup(): void
    {
        Functions\stubs(['get_option' => fn () => 2]);

        $parent = \Mockery::mock('WC_Product');
        $parent->shouldReceive('get_id')->andReturn(1);
        $parent->shouldReceive('get_name')->andReturn('Tee');
        $parent->shouldReceive('get_stock_quantity')->andReturn(null);
        $parent->shouldReceive('get_stock_status')->andReturn('instock');
        $parent->shouldReceive('managing_stock')->andReturn(false);
        $parent->shouldReceive('get_children')->andReturn([11, 12]);

        $small = \Mockery::mock('WC_Product');
        $small->shouldReceive('get_stock_quantity')->andReturn(4);

        $large = \Mockery::mock('WC_Product');
        $large->shouldReceive('get_stock_quantity')->andReturn(6);

        $variationCalls = [];

        $fetcher = static function (array $args) use (&$variationCalls, $parent, $small, $large): mixed {
            if (($args['type'] ?? '') === 'variation') {
                $variationCalls[] = $args;

                return [$small, $large];
            }

            return (object) ['products' => [$parent], 'total' => 1];
        };

        $result = json_decode(
            (new StockTool($fetcher))->execute(['columns' => ['id', 'total_variation_stock']]),
            true,
        );

        self::assertSame(10, $result['data']['products'][0]['total_variation_stock']);
        self::assertCount(1, $variationCalls, 'children are read in one query, not one per variation');
        self::assertSame([11, 12], $variationCalls[0]['include']);
    }
}

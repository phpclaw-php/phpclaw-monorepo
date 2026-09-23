<?php

declare(strict_types=1);

namespace PhpClaw\WooCommerce\Tests\Unit\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\WooCommerce\Tools\ProductTool;
use PhpClaw\WordPress\Tests\Concerns\StubsCapabilities;
use PHPUnit\Framework\TestCase;
use WC_Product;

final class ProductToolTest extends TestCase
{
    use StubsCapabilities;

    protected function setUp(): void
    {
        parent::setUp();
        \Brain\Monkey\setUp();

        $this->grantCapability('phpclaw_use_chat');
    }

    protected function tearDown(): void
    {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    private function makeProduct(int $id, string $name, string $stockStatus, ?int $qty): WC_Product
    {
        return new WC_Product($id, $name, $stockStatus, $qty);
    }

    public function test_name_is_wc_get_products(): void
    {
        self::assertSame('wc_get_products', (new ProductTool)->name());
    }

    public function test_input_schema_has_stock_status_and_limit(): void
    {
        $schema = (new ProductTool)->inputSchema();
        self::assertArrayHasKey('stock_status', $schema['properties']);
        self::assertArrayHasKey('limit', $schema['properties']);
    }

    public function test_execute_maps_product_fields(): void
    {
        $tool = new ProductTool(fn (array $args): array => [
            $this->makeProduct(7, 'Blue Mug', 'outofstock', 0),
        ]);

        $result = json_decode($tool->execute([]), true);
        $row = $result['data']['products'][0];

        self::assertSame(7, $row['id']);
        self::assertSame('Blue Mug', $row['name']);
        self::assertSame('outofstock', $row['stock_status']);
        self::assertSame(0, $row['stock_qty']);
    }

    public function test_execute_handles_null_stock_quantity(): void
    {
        $tool = new ProductTool(fn (array $args): array => [
            $this->makeProduct(9, 'Widget', 'instock', null),
        ]);

        $result = json_decode($tool->execute([]), true);

        self::assertNull($result['data']['products'][0]['stock_qty']);
    }

    public function test_execute_passes_stock_status_filter(): void
    {
        $passedArgs = [];
        $tool = new ProductTool(function (array $args) use (&$passedArgs): array {
            $passedArgs = $args;

            return [];
        });

        $tool->execute(['stock_status' => 'outofstock']);

        self::assertSame('outofstock', $passedArgs['stock_status']);
    }

    public function test_execute_skips_stock_filter_for_all(): void
    {
        $passedArgs = [];
        $tool = new ProductTool(function (array $args) use (&$passedArgs): array {
            $passedArgs = $args;

            return [];
        });

        $tool->execute(['stock_status' => 'all']);

        self::assertArrayNotHasKey('stock_status', $passedArgs);
    }

    public function test_limit_above_the_maximum_is_rejected_not_capped(): void
    {
        $r = json_decode((new ProductTool)->execute(['limit' => 999]), true);

        self::assertFalse($r['success']);
        self::assertSame('INVALID_LIMIT', $r['error']['code']);
    }

    public function test_execute_throws_tool_exception_on_fetcher_failure(): void
    {
        $tool = new ProductTool(fn (array $args): array => throw new \RuntimeException('WC error'));

        $this->expectException(ToolException::class);
        $tool->execute([]);
    }

    public function test_it_returns_forbidden_without_the_required_capability(): void
    {
        $this->denyAllCapabilities();

        $this->assertForbiddenEnvelope((new ProductTool)->execute([]));
    }

    public function test_forbidden_fetches_no_products(): void
    {
        $this->denyAllCapabilities();
        $fetched = false;

        $tool = new ProductTool(function (array $args) use (&$fetched): array {
            $fetched = true;

            return [];
        });

        $tool->execute([]);

        self::assertFalse($fetched);
    }

    public function test_it_returns_unknown_argument_instead_of_throwing(): void
    {
        $r = json_decode((new ProductTool)->execute(['phpclaw_bogus' => 1]), true);

        self::assertFalse($r['success']);
        self::assertSame('UNKNOWN_ARGUMENT', $r['error']['code']);
    }

    public function test_download_urls_are_rejected_not_dropped(): void
    {
        $r = json_decode((new ProductTool)->execute(['columns' => ['id', 'download_urls']]), true);

        self::assertFalse($r['success']);
        self::assertSame('BLOCKED_COLUMN', $r['error']['code']);
        self::assertContains('download_urls', $r['error']['blocked_columns']);
    }

    public function test_licence_meta_prefix_is_rejected(): void
    {
        $r = json_decode((new ProductTool)->execute(['columns' => ['_wc_licence_key']]), true);

        self::assertFalse($r['success']);
        self::assertSame('BLOCKED_COLUMN', $r['error']['code']);
    }

    public function test_cost_of_goods_requires_explicit_request_and_warns(): void
    {
        $tool = new ProductTool(fn (array $args): array => [$this->makeProduct(9, 'Widget', 'instock', 4)]);

        $plain = json_decode($tool->execute([]), true);
        self::assertArrayNotHasKey('cost_of_goods', $plain['data']['products'][0]);
        self::assertSame([], $plain['warnings']);

        $withCogs = json_decode($tool->execute(['columns' => ['id', 'cost_of_goods']]), true);
        self::assertSame('SENSITIVE_DATA', $withCogs['warnings'][0]['code']);
        self::assertContains('cost_of_goods', $withCogs['meta']['sensitive_fields_returned']);
    }

    public function test_wildcard_excludes_expensive_and_sensitive_fields(): void
    {
        $tool = new ProductTool(fn (array $args): array => [$this->makeProduct(9, 'Widget', 'instock', 4)]);

        $r = json_decode($tool->execute(['columns' => ['*']]), true);
        $cols = $r['meta']['columns_returned'];

        self::assertNotContains('variation_count', $cols);
        self::assertNotContains('review_count', $cols);
        self::assertNotContains('cost_of_goods', $cols);
        self::assertContains('id', $cols);
    }

    public function test_bad_stock_status_is_rejected(): void
    {
        $r = json_decode((new ProductTool)->execute(['stock_status' => 'nope']), true);

        self::assertFalse($r['success']);
        self::assertSame('INVALID_STOCK_STATUS', $r['error']['code']);
    }

    public function test_paginated_fetcher_shape_yields_a_real_total(): void
    {
        $tool = new ProductTool(fn (array $args): object => (object) [
            'products' => [$this->makeProduct(1, 'A', 'instock', 1)],
            'total' => 11,
        ]);

        $r = json_decode($tool->execute(['limit' => 1]), true);

        self::assertSame(11, $r['meta']['total']);
        self::assertTrue($r['meta']['has_more']);
        self::assertSame(1, $r['meta']['next_offset']);
    }

    public function test_query_requests_a_stable_secondary_sort(): void
    {
        $passed = [];
        $tool = new ProductTool(function (array $args) use (&$passed): array {
            $passed = $args;

            return [];
        });

        $tool->execute([]);

        self::assertSame(['date' => 'DESC', 'ID' => 'DESC'], $passed['orderby']);
    }

    public function test_contract_accessors_report_the_declared_capability_risk_and_examples(): void
    {
        $tool = new ProductTool;

        self::assertSame('woocommerce.products.read', $tool->capability());
        self::assertSame('phpclaw_use_chat', $tool->requiredCapability());
        self::assertSame('read', $tool->risk());
        self::assertTrue($tool->isIdempotent());
        self::assertNotSame([], ProductTool::examples());
    }

    public function test_schema_mode_describes_the_tool_without_running_a_query(): void
    {
        $result = json_decode((new ProductTool)->execute(['schema' => true]), true);

        self::assertTrue($result['success']);
        self::assertSame('schema', $result['meta']['mode']);
        self::assertSame('woocommerce.products.read', $result['data']['phpclaw_capability']);
        self::assertSame('read', $result['data']['risk']);
        self::assertTrue($result['data']['idempotent']);
        self::assertContains('schema', $result['data']['modes']);
        self::assertNotSame([], $result['data']['examples']);
    }
}

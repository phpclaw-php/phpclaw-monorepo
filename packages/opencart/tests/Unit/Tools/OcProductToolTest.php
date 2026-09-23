<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tests\Unit\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\OpenCart\Tests\Helpers\OcDbTestCase;
use PhpClaw\OpenCart\Tools\OcProductTool;

final class OcProductToolTest extends OcDbTestCase
{
    private OcProductTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $productTable = $this->prefix.'product';
        $descTable = $this->prefix.'product_description';
        $mfgTable = $this->prefix.'manufacturer';
        $catLinkTable = $this->prefix.'product_to_category';
        $catDescTable = $this->prefix.'category_description';

        $this->resetTables([
            "CREATE TABLE IF NOT EXISTS `{$productTable}` (
                product_id      INT          NOT NULL AUTO_INCREMENT,
                model           VARCHAR(64)  NOT NULL DEFAULT '',
                sku             VARCHAR(64)  NOT NULL DEFAULT '',
                quantity        INT          NOT NULL DEFAULT 0,
                price           DECIMAL(15,4) NOT NULL DEFAULT 0,
                status          TINYINT      NOT NULL DEFAULT 1,
                manufacturer_id INT          NOT NULL DEFAULT 0,
                weight          DECIMAL(15,8) NOT NULL DEFAULT 0,
                image           VARCHAR(255) NOT NULL DEFAULT '',
                viewed          INT          NOT NULL DEFAULT 0,
                sort_order      INT          NOT NULL DEFAULT 0,
                date_added      DATETIME     NOT NULL DEFAULT '2024-01-01 00:00:00',
                date_modified   DATETIME     NOT NULL DEFAULT '2024-01-01 00:00:00',
                PRIMARY KEY (product_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$descTable}` (
                product_id  INT NOT NULL DEFAULT 0,
                language_id INT NOT NULL DEFAULT 1,
                name        VARCHAR(255) NOT NULL DEFAULT ''
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$mfgTable}` (
                manufacturer_id INT NOT NULL AUTO_INCREMENT,
                name            VARCHAR(64) NOT NULL DEFAULT '',
                PRIMARY KEY (manufacturer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$catLinkTable}` (
                product_id  INT NOT NULL DEFAULT 0,
                category_id INT NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$catDescTable}` (
                category_id  INT NOT NULL DEFAULT 0,
                language_id  INT NOT NULL DEFAULT 1,
                name         VARCHAR(255) NOT NULL DEFAULT ''
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ]);

        $this->seed("INSERT INTO `{$productTable}` (product_id,model,sku,quantity,price,status,manufacturer_id,weight,image,viewed,sort_order,date_added,date_modified) VALUES (1,'WGT-001','SKU-001',10,29.99,1,1,0.5,'img1.jpg',100,1,'2024-01-01','2024-01-01')");
        $this->seed("INSERT INTO `{$productTable}` (product_id,model,sku,quantity,price,status,manufacturer_id,weight,image,viewed,sort_order,date_added,date_modified) VALUES (2,'WGT-002','SKU-002',0,49.99,1,2,1.0,'img2.jpg',50,2,'2024-02-01','2024-02-01')");
        $this->seed("INSERT INTO `{$productTable}` (product_id,model,sku,quantity,price,status,manufacturer_id,weight,image,viewed,sort_order,date_added,date_modified) VALUES (3,'WGT-003','SKU-003',5,9.99,0,1,0.3,'img3.jpg',10,3,'2024-03-01','2024-03-01')");
        $this->seed("INSERT INTO `{$descTable}` (product_id,language_id,name) VALUES (1,1,'Widget A')");
        $this->seed("INSERT INTO `{$descTable}` (product_id,language_id,name) VALUES (2,1,'Widget B')");
        $this->seed("INSERT INTO `{$descTable}` (product_id,language_id,name) VALUES (3,1,'Widget C')");
        $this->seed("INSERT INTO `{$mfgTable}` (manufacturer_id,name) VALUES (1,'Apple')");
        $this->seed("INSERT INTO `{$mfgTable}` (manufacturer_id,name) VALUES (2,'Samsung')");
        $this->seed("INSERT INTO `{$catLinkTable}` (product_id,category_id) VALUES (1,10)");
        $this->seed("INSERT INTO `{$catLinkTable}` (product_id,category_id) VALUES (2,10)");
        $this->seed("INSERT INTO `{$catLinkTable}` (product_id,category_id) VALUES (3,20)");
        $this->seed("INSERT INTO `{$catDescTable}` (category_id,language_id,name) VALUES (10,1,'Electronics')");
        $this->seed("INSERT INTO `{$catDescTable}` (category_id,language_id,name) VALUES (20,1,'Clothing')");

        $this->tool = new OcProductTool($this->db, $this->prefix, true);
    }

    private function payload(string $json): array
    {
        $d = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($d['success']);

        return $d['data'];
    }

    private function meta(string $json): array
    {
        $d = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $d['meta'] ?? [];
    }

    public function test_name(): void
    {
        self::assertSame('oc_product', $this->tool->name());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        self::assertStringContainsString(
            'OpenCart products with stock and pricing data',
            $this->tool->description(),
        );
    }

    public function test_required_capability_returns_access(): void
    {
        self::assertSame('access', $this->tool->requiredCapability());
    }

    public function test_forbidden_when_caller_lacks_module_grant(): void
    {
        $tool = new OcProductTool($this->db, $this->prefix, false);
        $d = json_decode($tool->execute([]), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($d['success']);
    }

    public function test_returns_all_products(): void
    {
        self::assertCount(3, $this->payload($this->tool->execute([]))['products']);
    }

    public function test_throws_when_pdo_is_null(): void
    {
        $tool = new OcProductTool(null, $this->prefix, true);
        $this->expectException(ToolException::class);
        $tool->execute([]);
    }

    public function test_schema_mode_works_without_pdo(): void
    {
        $tool = new OcProductTool(null, $this->prefix, true);
        $d = json_decode($tool->execute(['schema' => true]), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($d['success']);
    }

    public function test_schema_mode_returns_columns(): void
    {
        $data = $this->payload($this->tool->execute(['schema' => true]));
        self::assertArrayHasKey('available_columns', $data);
        self::assertArrayHasKey('default_columns', $data);
    }

    public function test_aggregate_returns_totals(): void
    {
        $data = $this->payload($this->tool->execute(['aggregate' => true]));
        self::assertSame(3, $data['stats']['total']);
    }

    public function test_aggregate_counts_active_inactive(): void
    {
        $data = $this->payload($this->tool->execute(['aggregate' => true]));
        self::assertSame(2, $data['stats']['active_count']);
        self::assertSame(1, $data['stats']['inactive_count']);
    }

    public function test_aggregate_counts_out_of_stock(): void
    {
        $data = $this->payload($this->tool->execute(['aggregate' => true]));
        self::assertSame(1, $data['stats']['out_of_stock_count']);
    }

    public function test_filter_by_status_enabled(): void
    {
        self::assertCount(2, $this->payload($this->tool->execute(['status' => 1]))['products']);
    }

    public function test_filter_by_status_disabled(): void
    {
        self::assertCount(1, $this->payload($this->tool->execute(['status' => 0]))['products']);
    }

    public function test_filter_by_manufacturer_id(): void
    {
        self::assertCount(2, $this->payload($this->tool->execute(['manufacturer_id' => 1]))['products']);
    }

    public function test_filter_by_category_id(): void
    {
        self::assertCount(2, $this->payload($this->tool->execute(['category_id' => 10]))['products']);
    }

    public function test_filter_out_of_stock(): void
    {
        self::assertCount(1, $this->payload($this->tool->execute(['out_of_stock' => true]))['products']);
    }

    public function test_search_by_name(): void
    {
        self::assertCount(1, $this->payload($this->tool->execute(['search' => 'Widget A']))['products']);
    }

    public function test_search_by_model(): void
    {
        self::assertCount(1, $this->payload($this->tool->execute(['search' => 'WGT-002']))['products']);
    }

    public function test_filter_by_min_price(): void
    {
        self::assertCount(1, $this->payload($this->tool->execute(['min_price' => 30.0]))['products']);
    }

    public function test_filter_by_max_price(): void
    {
        self::assertCount(1, $this->payload($this->tool->execute(['max_price' => 15.0]))['products']);
    }

    public function test_columns_wildcard(): void
    {
        $returned = $this->meta($this->tool->execute(['columns' => ['*']]))['columns_returned'] ?? [];
        self::assertContains('sku', $returned);
        self::assertContains('weight', $returned);
        self::assertNotContains('description', $returned);
    }

    public function test_columns_empty_returns_defaults(): void
    {
        $returned = $this->meta($this->tool->execute(['columns' => []]))['columns_returned'] ?? [];
        self::assertContains('price', $returned);
        self::assertContains('quantity', $returned);
    }

    public function test_limit_caps_results(): void
    {
        self::assertCount(1, $this->payload($this->tool->execute(['limit' => 1]))['products']);
    }

    public function test_price_range_combined(): void
    {
        self::assertCount(1, $this->payload($this->tool->execute(['min_price' => 10.0, 'max_price' => 40.0]))['products']);
    }
}

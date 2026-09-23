<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tests\Unit\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\OpenCart\Tests\Helpers\OcDbTestCase;
use PhpClaw\OpenCart\Tools\OcManufacturerTool;

final class OcManufacturerToolTest extends OcDbTestCase
{
    private OcManufacturerTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $mfgTable = $this->prefix.'manufacturer';
        $productTable = $this->prefix.'product';

        $this->resetTables([
            "CREATE TABLE IF NOT EXISTS `{$mfgTable}` (
                manufacturer_id INT         NOT NULL AUTO_INCREMENT,
                name            VARCHAR(64) NOT NULL DEFAULT '',
                image           VARCHAR(255) NOT NULL DEFAULT '',
                sort_order      INT         NOT NULL DEFAULT 0,
                PRIMARY KEY (manufacturer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$productTable}` (
                product_id      INT     NOT NULL AUTO_INCREMENT,
                manufacturer_id INT     NOT NULL DEFAULT 0,
                status          TINYINT NOT NULL DEFAULT 1,
                PRIMARY KEY (product_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ]);

        $this->seed("INSERT INTO `{$mfgTable}` (manufacturer_id,name,image,sort_order) VALUES (1,'Apple','apple.png',1)");
        $this->seed("INSERT INTO `{$mfgTable}` (manufacturer_id,name,image,sort_order) VALUES (2,'Samsung','samsung.png',2)");
        $this->seed("INSERT INTO `{$mfgTable}` (manufacturer_id,name,image,sort_order) VALUES (3,'Generic','',3)");
        $this->seed("INSERT INTO `{$productTable}` (product_id,manufacturer_id,status) VALUES (101,1,1)");
        $this->seed("INSERT INTO `{$productTable}` (product_id,manufacturer_id,status) VALUES (102,1,1)");
        $this->seed("INSERT INTO `{$productTable}` (product_id,manufacturer_id,status) VALUES (103,2,1)");

        $this->tool = new OcManufacturerTool($this->db, $this->prefix, true);
    }

    private function payload(string $json): array
    {
        $d = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($d['success']);

        return $d['data'];
    }

    public function test_name(): void
    {
        self::assertSame('oc_manufacturer', $this->tool->name());
    }

    public function test_required_capability_returns_access(): void
    {
        self::assertSame('access', $this->tool->requiredCapability());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        self::assertStringContainsString(
            'QUERY OpenCart manufacturers (brands)',
            $this->tool->description(),
        );
    }

    public function test_forbidden_when_caller_may_not_use_module(): void
    {
        $tool = new OcManufacturerTool($this->db, $this->prefix, false);
        $d = json_decode($tool->execute([]), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($d['success']);
        self::assertSame('FORBIDDEN', $d['error']['code']);
    }

    public function test_list_returns_all_manufacturers(): void
    {
        $data = $this->payload($this->tool->execute(['mode' => 'list']));
        self::assertCount(3, $data['manufacturers'] ?? []);
    }

    public function test_default_mode_is_list(): void
    {
        $data = $this->payload($this->tool->execute([]));
        self::assertArrayHasKey('manufacturers', $data);
    }

    public function test_schema_mode_returns_metadata(): void
    {
        $data = $this->payload($this->tool->execute(['mode' => 'schema']));
        self::assertArrayHasKey('available_columns', $data);
        self::assertArrayHasKey('filters', $data);
    }

    public function test_schema_mode_works_without_pdo(): void
    {
        $tool = new OcManufacturerTool(null, $this->prefix, true);
        $data = $this->payload($tool->execute(['mode' => 'schema']));
        self::assertArrayHasKey('available_columns', $data);
    }

    public function test_throws_when_pdo_null_on_list(): void
    {
        $tool = new OcManufacturerTool(null, $this->prefix, true);
        $this->expectException(ToolException::class);
        $tool->execute(['mode' => 'list']);
    }

    public function test_aggregate_mode_returns_totals(): void
    {
        $data = $this->payload($this->tool->execute(['mode' => 'aggregate']));
        self::assertSame(3, $data['stats']['total_manufacturers']);
        self::assertSame(3, $data['stats']['total_products']);
    }

    public function test_aggregate_avg_products(): void
    {
        $data = $this->payload($this->tool->execute(['mode' => 'aggregate']));
        self::assertEqualsWithDelta(1.0, $data['stats']['avg_products_per_manufacturer'], 0.01);
    }

    public function test_search_by_name(): void
    {
        $data = $this->payload($this->tool->execute(['search' => 'apple']));
        self::assertCount(1, $data['manufacturers']);
        self::assertSame('Apple', $data['manufacturers'][0]['name']);
    }

    public function test_hide_empty_excludes_no_product_manufacturers(): void
    {
        $data = $this->payload($this->tool->execute(['hide_empty' => true]));
        self::assertCount(2, $data['manufacturers']);
    }

    public function test_columns_wildcard_includes_image(): void
    {
        $data = $this->payload($this->tool->execute(['columns' => ['*']]));
        $returned = $data['columns_returned'] ?? [];
        self::assertContains('image', $returned);
        self::assertContains('sort_order', $returned);
    }

    public function test_columns_empty_returns_defaults(): void
    {
        $data = $this->payload($this->tool->execute(['columns' => []]));
        $returned = $data['columns_returned'] ?? [];
        self::assertContains('name', $returned);
        self::assertContains('product_count', $returned);
    }

    public function test_limit_caps_results(): void
    {
        $data = $this->payload($this->tool->execute(['limit' => 1]));
        self::assertCount(1, $data['manufacturers']);
    }

    public function test_product_count_column_returns_count(): void
    {
        $data = $this->payload($this->tool->execute(['columns' => ['id', 'name', 'product_count']]));
        $apple = array_filter($data['manufacturers'], fn ($r) => $r['name'] === 'Apple');
        $apple = array_values($apple);
        self::assertSame(2, (int) $apple[0]['product_count']);
    }

    public function test_invalid_columns_filtered_out(): void
    {
        $data = $this->payload($this->tool->execute(['columns' => ['id', 'nonexistent']]));
        $returned = $data['columns_returned'] ?? [];
        self::assertContains('id', $returned);
        self::assertNotContains('nonexistent', $returned);
    }
}

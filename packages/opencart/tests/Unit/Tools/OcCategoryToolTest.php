<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tests\Unit\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\OpenCart\Tests\Helpers\OcDbTestCase;
use PhpClaw\OpenCart\Tools\OcCategoryTool;

final class OcCategoryToolTest extends OcDbTestCase
{
    private OcCategoryTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $catTable = $this->prefix.'category';
        $catDescTable = $this->prefix.'category_description';
        $catLinkTable = $this->prefix.'product_to_category';

        $this->resetTables([
            "CREATE TABLE IF NOT EXISTS `{$catTable}` (
                category_id   INT      NOT NULL AUTO_INCREMENT,
                parent_id     INT      NOT NULL DEFAULT 0,
                status        TINYINT  NOT NULL DEFAULT 1,
                sort_order    INT      NOT NULL DEFAULT 0,
                date_added    DATETIME NOT NULL DEFAULT '2024-01-01 00:00:00',
                date_modified DATETIME NOT NULL DEFAULT '2024-01-01 00:00:00',
                PRIMARY KEY (category_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$catDescTable}` (
                category_id  INT          NOT NULL DEFAULT 0,
                language_id  INT          NOT NULL DEFAULT 1,
                name         VARCHAR(255) NOT NULL DEFAULT '',
                description  TEXT         NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$catLinkTable}` (
                product_id  INT NOT NULL DEFAULT 0,
                category_id INT NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ]);

        $this->seed("INSERT INTO `{$catTable}` (category_id,parent_id,status,sort_order,date_added,date_modified) VALUES (1,0,1,1,'2024-01-01','2024-01-01')");
        $this->seed("INSERT INTO `{$catTable}` (category_id,parent_id,status,sort_order,date_added,date_modified) VALUES (2,1,1,2,'2024-01-01','2024-01-01')");
        $this->seed("INSERT INTO `{$catTable}` (category_id,parent_id,status,sort_order,date_added,date_modified) VALUES (3,0,0,3,'2024-01-01','2024-01-01')");
        $this->seed("INSERT INTO `{$catDescTable}` (category_id,language_id,name,description) VALUES (1,1,'Electronics','All electronics')");
        $this->seed("INSERT INTO `{$catDescTable}` (category_id,language_id,name,description) VALUES (2,1,'Phones','Mobile phones')");
        $this->seed("INSERT INTO `{$catDescTable}` (category_id,language_id,name,description) VALUES (3,1,'Archived','Old stuff')");
        $this->seed("INSERT INTO `{$catLinkTable}` (product_id,category_id) VALUES (101,1)");
        $this->seed("INSERT INTO `{$catLinkTable}` (product_id,category_id) VALUES (102,1)");
        $this->seed("INSERT INTO `{$catLinkTable}` (product_id,category_id) VALUES (103,2)");

        $this->tool = new OcCategoryTool($this->db, $this->prefix, true);
    }

    private function payload(string $json): array
    {
        $d = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($d['success']);

        return $d['data'];
    }

    public function test_name(): void
    {
        self::assertSame('oc_category', $this->tool->name());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        self::assertStringContainsString(
            'QUERY OpenCart product categories',
            $this->tool->description(),
        );
    }

    public function test_list_returns_all_categories(): void
    {
        $payload = $this->payload($this->tool->execute(['mode' => 'list']));
        self::assertCount(3, $payload['categories']);
    }

    public function test_list_defaults_to_list_mode(): void
    {
        $payload = $this->payload($this->tool->execute([]));
        self::assertArrayHasKey('categories', $payload);
    }

    public function test_schema_mode_returns_columns(): void
    {
        $d = json_decode($this->tool->execute(['mode' => 'schema']), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($d['success']);
        self::assertSame('schema', $d['meta']['mode']);
        self::assertArrayHasKey('available_columns', $d['data']);
        self::assertArrayHasKey('default_columns', $d['data']);
    }

    public function test_schema_mode_works_without_pdo(): void
    {
        $tool = new OcCategoryTool(null, $this->prefix, true);
        $d = json_decode($tool->execute(['mode' => 'schema']), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($d['success']);
        self::assertSame('schema', $d['meta']['mode']);
        self::assertArrayHasKey('available_columns', $d['data']);
    }

    public function test_throws_when_pdo_null_on_list(): void
    {
        $tool = new OcCategoryTool(null, $this->prefix, true);
        $this->expectException(ToolException::class);
        $tool->execute(['mode' => 'list']);
    }

    public function test_aggregate_mode_returns_stats(): void
    {
        $d = json_decode($this->tool->execute(['mode' => 'aggregate']), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($d['success']);
        self::assertSame('aggregate', $d['meta']['mode']);
        self::assertSame(3, $d['data']['stats']['total_categories']);
        self::assertArrayHasKey('active_count', $d['data']['stats']);
        self::assertArrayHasKey('inactive_count', $d['data']['stats']);
    }

    public function test_aggregate_active_inactive_split(): void
    {
        $d = json_decode($this->tool->execute(['mode' => 'aggregate']), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(2, $d['data']['stats']['active_count']);
        self::assertSame(1, $d['data']['stats']['inactive_count']);
    }

    public function test_filter_by_status_enabled(): void
    {
        $payload = $this->payload($this->tool->execute(['status' => 1]));
        self::assertCount(2, $payload['categories']);
    }

    public function test_filter_by_status_disabled(): void
    {
        $payload = $this->payload($this->tool->execute(['status' => 0]));
        self::assertCount(1, $payload['categories']);
    }

    public function test_filter_by_parent_id_top_level(): void
    {
        $payload = $this->payload($this->tool->execute(['parent_id' => 0]));
        self::assertCount(2, $payload['categories']);
    }

    public function test_filter_by_parent_id_subcategory(): void
    {
        $payload = $this->payload($this->tool->execute(['parent_id' => 1]));
        self::assertCount(1, $payload['categories']);
    }

    public function test_search_by_name(): void
    {
        $payload = $this->payload($this->tool->execute(['search' => 'elec']));
        self::assertCount(1, $payload['categories']);
    }

    public function test_columns_wildcard(): void
    {
        $d = json_decode($this->tool->execute(['columns' => ['*']]), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($d['success']);
        $returned = $d['meta']['columns_returned'] ?? [];
        self::assertContains('description', $returned);
        self::assertContains('sort_order', $returned);
    }

    public function test_columns_empty_returns_defaults(): void
    {
        $d = json_decode($this->tool->execute(['columns' => []]), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($d['success']);
        $returned = $d['meta']['columns_returned'] ?? [];
        self::assertContains('name', $returned);
        self::assertContains('status', $returned);
    }

    public function test_hide_empty_excludes_zero_product_categories(): void
    {
        $payload = $this->payload($this->tool->execute(['hide_empty' => true]));
        self::assertCount(2, $payload['categories']);
    }

    public function test_limit_caps_results(): void
    {
        $payload = $this->payload($this->tool->execute(['limit' => 1]));
        self::assertCount(1, $payload['categories']);
    }

    public function test_aggregate_with_status_filter(): void
    {
        $d = json_decode($this->tool->execute(['mode' => 'aggregate', 'status' => 1]), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(2, $d['data']['stats']['active_count']);
    }

    public function test_product_count_in_selected_columns(): void
    {
        $payload = $this->payload($this->tool->execute(['columns' => ['id', 'name', 'product_count']]));
        $row = $payload['categories'][0] ?? [];
        self::assertArrayHasKey('product_count', $row);
    }

    public function test_parent_name_column_in_results(): void
    {
        $d = json_decode($this->tool->execute(['columns' => ['id', 'name', 'parent_name']]), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($d['success']);
        $returned = $d['meta']['columns_returned'] ?? [];
        self::assertContains('parent_name', $returned);
    }

    public function test_forbidden_when_caller_may_not_use_module(): void
    {
        $tool = new OcCategoryTool($this->db, $this->prefix, false);
        $d = json_decode($tool->execute([]), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($d['success']);
        self::assertSame('FORBIDDEN', $d['error']['code']);
    }
}

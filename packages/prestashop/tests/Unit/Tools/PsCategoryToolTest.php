<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\PrestaShop\Tests\Helpers\ActsAsChatTierEmployee;
use PhpClaw\PrestaShop\Tests\Helpers\AssertsToolEnvelope;
use PhpClaw\PrestaShop\Tests\Helpers\PsDbTestCase;
use PhpClaw\PrestaShop\Tools\PsCategoryTool;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(PsCategoryTool::class)]
final class PsCategoryToolTest extends PsDbTestCase
{
    use ActsAsChatTierEmployee;
    use AssertsToolEnvelope;

    private PsCategoryTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actAsChatTierEmployee();

        $categoryTable = $this->prefix.'category';
        $categoryLangTable = $this->prefix.'category_lang';
        $catProductTable = $this->prefix.'category_product';

        $this->resetTables([
            "CREATE TABLE IF NOT EXISTS `{$categoryTable}` (
                id_category  INT      NOT NULL AUTO_INCREMENT,
                id_parent    INT      NOT NULL DEFAULT 0,
                active       TINYINT  NOT NULL DEFAULT 1,
                level_depth  INT      NOT NULL DEFAULT 1,
                position     INT      NOT NULL DEFAULT 0,
                date_add     DATETIME NOT NULL DEFAULT '2024-01-01 00:00:00',
                date_upd     DATETIME NOT NULL DEFAULT '2024-01-01 00:00:00',
                PRIMARY KEY (id_category)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$categoryLangTable}` (
                id_category  INT          NOT NULL DEFAULT 0,
                id_lang      INT          NOT NULL DEFAULT 1,
                name         VARCHAR(128) NOT NULL DEFAULT '',
                description  TEXT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$catProductTable}` (
                id_category INT NOT NULL DEFAULT 0,
                id_product  INT NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ]);

        $this->seed("INSERT INTO `{$categoryTable}` (id_category,id_parent,active,level_depth,position,date_add,date_upd) VALUES (2,1,1,1,1,'2024-01-01 00:00:00','2024-01-01 00:00:00')");
        $this->seed("INSERT INTO `{$categoryTable}` (id_category,id_parent,active,level_depth,position,date_add,date_upd) VALUES (3,2,1,2,2,'2024-01-02 00:00:00','2024-01-02 00:00:00')");
        $this->seed("INSERT INTO `{$categoryTable}` (id_category,id_parent,active,level_depth,position,date_add,date_upd) VALUES (4,1,0,1,3,'2024-01-03 00:00:00','2024-01-03 00:00:00')");
        $this->seed("INSERT INTO `{$categoryLangTable}` (id_category,id_lang,name,description) VALUES (2,1,'Electronics','Elec desc')");
        $this->seed("INSERT INTO `{$categoryLangTable}` (id_category,id_lang,name,description) VALUES (3,1,'Phones','Phone desc')");
        $this->seed("INSERT INTO `{$categoryLangTable}` (id_category,id_lang,name,description) VALUES (4,1,'Inactive Cat','')");
        $this->seed("INSERT INTO `{$catProductTable}` (id_category,id_product) VALUES (2,1)");
        $this->seed("INSERT INTO `{$catProductTable}` (id_category,id_product) VALUES (2,2)");
        $this->seed("INSERT INTO `{$catProductTable}` (id_category,id_product) VALUES (3,1)");

        $this->tool = new PsCategoryTool($this->db, $this->prefix);
    }

    protected function tearDown(): void
    {
        $this->stopActingAsEmployee();

        parent::tearDown();
    }

    public function test_name_returns_ps_category(): void
    {
        self::assertSame('ps_category', $this->tool->name());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        self::assertStringContainsString(
            'QUERY PrestaShop product categories',
            $this->tool->description(),
        );
    }

    public function test_input_schema_has_properties(): void
    {
        self::assertArrayHasKey('properties', $this->tool->inputSchema());
    }

    public function test_execute_returns_categories(): void
    {
        $result = $this->tool->execute([]);
        $data = $this->flat($result);

        self::assertArrayHasKey('categories', $data);
        self::assertCount(3, $data['categories']);
    }

    public function test_execute_filters_by_parent(): void
    {
        $result = $this->tool->execute(['parent_id' => 2]);
        $data = $this->flat($result);

        self::assertCount(1, $data['categories']);
    }

    public function test_execute_throws_on_null_db(): void
    {
        $tool = new PsCategoryTool(null, $this->prefix);

        $this->expectException(ToolException::class);
        $tool->execute([]);
    }

    public function test_schema_mode_does_not_require_db(): void
    {
        $tool = new PsCategoryTool(null, $this->prefix);

        $data = $this->flat($tool->execute(['mode' => 'schema']));

        self::assertSame('schema', $data['mode']);
        self::assertSame(
            (new \ReflectionClassConstant(PsCategoryTool::class, 'AVAILABLE_COLUMNS'))->getValue(),
            $data['available_columns'],
        );
        self::assertContains('schema', $data['modes']);
    }

    public function test_aggregate_mode_returns_stats(): void
    {
        $result = $this->tool->execute(['mode' => 'aggregate']);
        $data = $this->flat($result);

        self::assertSame('aggregate', $data['mode']);
        self::assertSame(3, $data['total_categories']);
        self::assertSame(2, $data['active_count']);
        self::assertSame(1, $data['inactive_count']);
        self::assertArrayHasKey('empty_categories', $data);
        self::assertArrayHasKey('max_depth', $data);
    }

    public function test_filter_active_one(): void
    {
        $result = $this->tool->execute(['active' => 1]);
        $data = $this->flat($result);

        self::assertCount(2, $data['categories']);
    }

    public function test_filter_active_zero(): void
    {
        $result = $this->tool->execute(['active' => 0]);
        $data = $this->flat($result);

        self::assertCount(1, $data['categories']);
    }

    public function test_search_by_name(): void
    {
        $result = $this->tool->execute(['search' => 'Elec']);
        $data = $this->flat($result);

        self::assertCount(1, $data['categories']);
    }

    public function test_columns_wildcard_returns_all(): void
    {
        $result = $this->tool->execute(['columns' => ['*']]);
        $data = $this->flat($result);

        self::assertContains('description', $data['columns_returned']);
        self::assertContains('depth', $data['columns_returned']);
        self::assertContains('product_count', $data['columns_returned']);
    }

    public function test_columns_specific_subset(): void
    {
        $result = $this->tool->execute(['columns' => ['name', 'active']]);
        $data = $this->flat($result);

        self::assertContains('name', $data['columns_returned']);
        self::assertContains('active', $data['columns_returned']);
    }

    public function test_columns_invalid_falls_back_to_defaults(): void
    {
        $result = $this->tool->execute(['columns' => ['zzz_invalid']]);
        $data = $this->flat($result);

        self::assertContains('id', $data['columns_returned']);
    }

    public function test_columns_empty_array_returns_defaults(): void
    {
        $result = $this->tool->execute(['columns' => []]);
        $data = $this->flat($result);

        self::assertContains('id', $data['columns_returned']);
    }

    public function test_product_count_in_results(): void
    {
        $result = $this->tool->execute(['columns' => ['product_count']]);
        $data = $this->flat($result);

        self::assertContains('product_count', $data['columns_returned']);
    }

    public function test_parent_name_triggers_parent_join(): void
    {
        $result = $this->tool->execute(['columns' => ['parent_name']]);
        $data = $this->flat($result);

        self::assertContains('parent_name', $data['columns_returned']);
    }

    public function test_hide_empty_excludes_empty_categories(): void
    {
        $result = $this->tool->execute(['hide_empty' => true]);
        $data = $this->flat($result);

        self::assertGreaterThanOrEqual(1, count($data['categories']));
    }

    public function test_limit_applied(): void
    {
        $result = $this->tool->execute(['limit' => 1]);
        $data = $this->flat($result);

        self::assertCount(1, $data['categories']);
    }

    public function test_empty_result_when_no_match(): void
    {
        $result = $this->tool->execute(['search' => 'ZZZNOMATCH99999XYZ']);
        $data = $this->flat($result);

        self::assertSame([], $data['categories']);
    }

    public function test_total_and_columns_returned_metadata_present(): void
    {
        $result = $this->tool->execute([]);
        $data = $this->flat($result);

        self::assertArrayHasKey('total', $data);
        self::assertArrayHasKey('columns_returned', $data);
    }

    public function test_aggregate_with_search_filter(): void
    {
        $result = $this->tool->execute(['mode' => 'aggregate', 'search' => 'Elec']);
        $data = $this->flat($result);

        self::assertSame(1, $data['total_categories']);
    }

    public function test_aggregate_with_active_filter(): void
    {
        $result = $this->tool->execute(['mode' => 'aggregate', 'active' => 1]);
        $data = $this->flat($result);

        self::assertSame(2, $data['total_categories']);
    }

    public function test_list_mode_explicit(): void
    {
        $result = $this->tool->execute(['mode' => 'list']);
        $data = $this->flat($result);

        self::assertArrayHasKey('categories', $data);
    }

    public function test_description_column_returned(): void
    {
        $result = $this->tool->execute(['columns' => ['description']]);
        $data = $this->flat($result);

        self::assertContains('description', $data['columns_returned']);
    }

    public function test_date_add_and_date_upd_columns(): void
    {
        $result = $this->tool->execute(['columns' => ['date_add', 'date_upd']]);
        $data = $this->flat($result);

        self::assertContains('date_add', $data['columns_returned']);
        self::assertContains('date_upd', $data['columns_returned']);
    }

    public function test_total_counts_every_matching_row_not_just_the_page(): void
    {
        $all = $this->meta($this->tool->execute([]));
        $page = $this->meta($this->tool->execute(['limit' => 1]));

        self::assertSame($all['total'], $page['total']);
        self::assertSame(1, $page['count']);
    }

    public function test_the_second_page_returns_rows_the_first_page_left(): void
    {
        $first = $this->rows($this->tool->execute(['limit' => 1]), 'categories');
        $second = $this->rows($this->tool->execute(['limit' => 1, 'offset' => 1]), 'categories');

        self::assertNotSame($first[0]['id'], $second[0]['id']);
    }

    public function test_an_employee_below_the_chat_tier_is_refused(): void
    {
        $this->actAsEmployeeBelowChatTier();

        self::assertSame('FORBIDDEN', $this->errorCode($this->tool->execute([])));
    }

    public function test_the_required_capability_is_the_chat_tab(): void
    {
        self::assertSame('AdminPhpClawDebug', $this->tool->requiredCapability());
    }

    public function test_an_unknown_argument_is_refused(): void
    {
        self::assertSame('UNKNOWN_ARGUMENT', $this->errorCode($this->tool->execute(['nope' => 1])));
    }

    public function test_an_out_of_range_limit_is_refused(): void
    {
        self::assertSame('INVALID_LIMIT', $this->errorCode($this->tool->execute(['limit' => 9999])));
    }
}

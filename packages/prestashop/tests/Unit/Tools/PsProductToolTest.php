<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\PrestaShop\Tests\Helpers\ActsAsChatTierEmployee;
use PhpClaw\PrestaShop\Tests\Helpers\AssertsDocumentedColumnsAreEmitted;
use PhpClaw\PrestaShop\Tests\Helpers\AssertsToolEnvelope;
use PhpClaw\PrestaShop\Tests\Helpers\PsDbTestCase;
use PhpClaw\PrestaShop\Tools\PsProductTool;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(PsProductTool::class)]
final class PsProductToolTest extends PsDbTestCase
{
    use ActsAsChatTierEmployee;
    use AssertsDocumentedColumnsAreEmitted;
    use AssertsToolEnvelope;

    private PsProductTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actAsChatTierEmployee();

        $productTable = $this->prefix.'product';
        $langTable = $this->prefix.'product_lang';
        $mfgTable = $this->prefix.'manufacturer';
        $catLangTable = $this->prefix.'category_lang';
        $stockTable = $this->prefix.'stock_available';

        $this->resetTables([
            "CREATE TABLE IF NOT EXISTS `{$productTable}` (
                id_product          INT          NOT NULL AUTO_INCREMENT,
                reference           VARCHAR(64)  NOT NULL DEFAULT '',
                price               DECIMAL(20,6) NOT NULL DEFAULT 0,
                wholesale_price     DECIMAL(20,6) NOT NULL DEFAULT 0,
                quantity            INT          NOT NULL DEFAULT 0,
                active              TINYINT      NOT NULL DEFAULT 1,
                id_manufacturer     INT          NOT NULL DEFAULT 0,
                id_category_default INT          NOT NULL DEFAULT 1,
                weight              DECIMAL(20,6) NOT NULL DEFAULT 0,
                ean13               VARCHAR(13)  NOT NULL DEFAULT '',
                upc                 VARCHAR(12)  NOT NULL DEFAULT '',
                date_add            DATETIME     NOT NULL DEFAULT '2024-01-01 00:00:00',
                date_upd            DATETIME     NOT NULL DEFAULT '2024-01-01 00:00:00',
                PRIMARY KEY (id_product)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$langTable}` (
                id_product        INT          NOT NULL DEFAULT 0,
                id_lang           INT          NOT NULL DEFAULT 1,
                name              VARCHAR(128) NOT NULL DEFAULT '',
                description_short TEXT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$mfgTable}` (
                id_manufacturer INT         NOT NULL AUTO_INCREMENT,
                name            VARCHAR(64) NOT NULL DEFAULT '',
                PRIMARY KEY (id_manufacturer)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$catLangTable}` (
                id_category INT         NOT NULL DEFAULT 0,
                id_lang     INT         NOT NULL DEFAULT 1,
                name        VARCHAR(128) NOT NULL DEFAULT ''
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$stockTable}` (
                id_product           INT NOT NULL DEFAULT 0,
                id_product_attribute INT NOT NULL DEFAULT 0,
                quantity             INT NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ]);

        $this->seed("INSERT INTO `{$productTable}` (id_product,reference,price,wholesale_price,quantity,active,id_manufacturer,id_category_default,weight,ean13,upc,date_add,date_upd) VALUES (1,'SKU-001',19.99,10.00,999,1,1,1,0.5,'','','2024-01-01 00:00:00','2024-01-01 00:00:00')");
        $this->seed("INSERT INTO `{$productTable}` (id_product,reference,price,wholesale_price,quantity,active,id_manufacturer,id_category_default,weight,ean13,upc,date_add,date_upd) VALUES (2,'SKU-002',9.99,5.00,999,0,2,2,0.2,'','','2024-01-02 00:00:00','2024-01-02 00:00:00')");
        $this->seed("INSERT INTO `{$productTable}` (id_product,reference,price,wholesale_price,quantity,active,id_manufacturer,id_category_default,weight,ean13,upc,date_add,date_upd) VALUES (3,'SKU-003',49.99,25.00,999,1,1,1,1.0,'','','2024-01-03 00:00:00','2024-01-03 00:00:00')");

        $this->seed("INSERT INTO `{$stockTable}` (id_product,id_product_attribute,quantity) VALUES (1,0,10)");
        $this->seed("INSERT INTO `{$stockTable}` (id_product,id_product_attribute,quantity) VALUES (2,0,0)");
        $this->seed("INSERT INTO `{$stockTable}` (id_product,id_product_attribute,quantity) VALUES (3,0,5)");

        $this->seed("INSERT INTO `{$langTable}` (id_product,id_lang,name,description_short) VALUES (1,1,'Blue Widget','A nice widget')");
        $this->seed("INSERT INTO `{$langTable}` (id_product,id_lang,name,description_short) VALUES (2,1,'Red Gadget','A gadget')");
        $this->seed("INSERT INTO `{$langTable}` (id_product,id_lang,name,description_short) VALUES (3,1,'Green Widget','Another widget')");
        $this->seed("INSERT INTO `{$mfgTable}` (id_manufacturer,name) VALUES (1,'Acme')");
        $this->seed("INSERT INTO `{$mfgTable}` (id_manufacturer,name) VALUES (2,'Globex')");
        $this->seed("INSERT INTO `{$catLangTable}` (id_category,id_lang,name) VALUES (1,1,'Electronics')");
        $this->seed("INSERT INTO `{$catLangTable}` (id_category,id_lang,name) VALUES (2,1,'Gadgets')");

        $this->tool = new PsProductTool($this->db, $this->prefix);
    }

    protected function tearDown(): void
    {
        $this->stopActingAsEmployee();

        parent::tearDown();
    }

    public function test_name_returns_ps_product(): void
    {
        self::assertSame('ps_product', $this->tool->name());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        self::assertStringContainsString(
            'PrestaShop products with stock and pr',
            $this->tool->description(),
        );
    }

    public function test_input_schema_is_object_type(): void
    {
        self::assertSame('object', $this->tool->inputSchema()['type']);
    }

    public function test_execute_returns_products(): void
    {
        $result = $this->tool->execute([]);
        $data = $this->flat($result);

        self::assertArrayHasKey('products', $data);
        self::assertCount(3, $data['products']);
    }

    public function test_execute_filters_by_active(): void
    {
        $result = $this->tool->execute(['active' => 1]);
        $data = $this->flat($result);

        self::assertArrayHasKey('products', $data);
        self::assertCount(2, $data['products']);
        self::assertSame(1, (int) $data['products'][0]['active']);
    }

    public function test_execute_filters_inactive(): void
    {
        $result = $this->tool->execute(['active' => 0]);
        $data = $this->flat($result);

        self::assertCount(1, $data['products']);
        self::assertSame(0, (int) $data['products'][0]['active']);
    }

    public function test_execute_filters_by_search_name(): void
    {
        $result = $this->tool->execute(['search' => 'Blue']);
        $data = $this->flat($result);

        self::assertCount(1, $data['products']);
        self::assertStringContainsString('Blue', $data['products'][0]['name'] ?? '');
    }

    public function test_execute_filters_by_search_reference(): void
    {
        $result = $this->tool->execute(['search' => 'SKU-002']);
        $data = $this->flat($result);

        self::assertCount(1, $data['products']);
    }

    public function test_execute_respects_limit(): void
    {
        $result = $this->tool->execute(['limit' => 1]);
        $data = $this->flat($result);

        self::assertCount(1, $data['products']);
    }

    public function test_execute_aggregate_returns_stats(): void
    {
        $result = $this->tool->execute(['aggregate' => true]);
        $data = $this->flat($result);

        self::assertSame('aggregate', $data['mode']);
        self::assertSame('aggregate', $data['mode']);
        self::assertArrayHasKey('stats', $data);
        self::assertSame(3, $data['stats']['total']);
        self::assertSame(2, $data['stats']['active_count']);
        self::assertSame(1, $data['stats']['inactive_count']);
        self::assertSame(1, $data['stats']['out_of_stock_count']);
    }

    public function test_aggregate_avg_price(): void
    {
        $result = $this->tool->execute(['aggregate' => true]);
        $data = $this->flat($result);

        self::assertIsNumeric($data['stats']['avg_price']);
        self::assertGreaterThan(0, $data['stats']['avg_price']);
    }

    public function test_execute_throws_on_null_db(): void
    {
        $tool = new PsProductTool(null, $this->prefix);

        $this->expectException(ToolException::class);
        $tool->execute([]);
    }

    public function test_schema_mode_does_not_require_db(): void
    {
        $tool = new PsProductTool(null, $this->prefix);

        $data = $this->flat($tool->execute(['schema' => true]));

        self::assertSame('schema', $data['mode']);
        self::assertSame(
            (new \ReflectionClassConstant(PsProductTool::class, 'AVAILABLE_COLUMNS'))->getValue(),
            $data['available_columns'],
        );
        self::assertContains('out_of_stock', $data['filter_capabilities']);
    }

    public function test_filter_by_manufacturer_id(): void
    {
        $result = $this->tool->execute(['manufacturer_id' => 1]);
        $data = $this->flat($result);

        self::assertCount(2, $data['products']);
    }

    public function test_filter_by_category_id(): void
    {
        $result = $this->tool->execute(['category_id' => 2]);
        $data = $this->flat($result);

        self::assertCount(1, $data['products']);
    }

    public function test_filter_min_price(): void
    {
        $result = $this->tool->execute(['min_price' => 15]);
        $data = $this->flat($result);

        self::assertCount(2, $data['products']);
    }

    public function test_filter_max_price(): void
    {
        $result = $this->tool->execute(['max_price' => 15]);
        $data = $this->flat($result);

        self::assertCount(1, $data['products']);
    }

    public function test_filter_out_of_stock(): void
    {
        $result = $this->tool->execute(['out_of_stock' => true]);
        $data = $this->flat($result);

        self::assertCount(1, $data['products']);
    }

    public function test_columns_wildcard_returns_all(): void
    {
        $result = $this->tool->execute(['columns' => ['*']]);
        $data = $this->flat($result);

        self::assertContains('manufacturer_name', $data['columns_returned']);
        self::assertContains('weight', $data['columns_returned']);
        self::assertContains('description_short', $data['columns_returned']);
    }

    public function test_columns_specific_subset(): void
    {
        $result = $this->tool->execute(['columns' => ['name', 'price']]);
        $data = $this->flat($result);

        self::assertContains('id', $data['columns_returned']);
        self::assertContains('name', $data['columns_returned']);
        self::assertContains('price', $data['columns_returned']);
    }

    public function test_columns_blocked_column_is_refused_not_silently_excluded(): void
    {
        $result = $this->tool->execute(['columns' => ['name', 'description']]);

        self::assertSame('REFUSED_COLUMN', $this->errorCode($result));
        self::assertContains('description', $this->envelope($result)['error']['refused_columns']);
        self::assertNotContains('name', $this->envelope($result)['error']['refused_columns']);
    }

    public function test_columns_empty_array_returns_defaults(): void
    {
        $result = $this->tool->execute(['columns' => []]);
        $data = $this->flat($result);

        self::assertContains('id', $data['columns_returned']);
        self::assertContains('price', $data['columns_returned']);
    }

    public function test_columns_non_array_falls_back_to_defaults(): void
    {
        $result = $this->tool->execute(['columns' => 'name']);
        $data = $this->flat($result);

        self::assertContains('id', $data['columns_returned']);
    }

    public function test_columns_all_invalid_falls_back_to_defaults(): void
    {
        $result = $this->tool->execute(['columns' => ['zzz_invalid', 'abc_none']]);
        $data = $this->flat($result);

        self::assertContains('id', $data['columns_returned']);
    }

    public function test_empty_result_when_no_match(): void
    {
        $result = $this->tool->execute(['search' => 'ZZZNOMATCH99999XYZ']);
        $data = $this->flat($result);

        self::assertSame([], $data['products']);
    }

    public function test_columns_returned_metadata_present(): void
    {
        $result = $this->tool->execute([]);
        $data = $this->flat($result);

        self::assertArrayHasKey('columns_returned', $data);
    }

    public function test_total_metadata_present(): void
    {
        $result = $this->tool->execute([]);
        $data = $this->flat($result);

        self::assertArrayHasKey('total', $data);
    }

    public function test_aggregate_with_active_filter(): void
    {
        $result = $this->tool->execute(['aggregate' => true, 'active' => 1]);
        $data = $this->flat($result);

        self::assertSame(2, $data['stats']['total']);
    }

    public function test_manufacturer_name_in_wildcard_results(): void
    {
        $result = $this->tool->execute(['columns' => ['*']]);
        $data = $this->flat($result);

        $first = $data['products'][0] ?? [];
        self::assertArrayHasKey('manufacturer_name', $first);
    }

    public function test_description_short_stripped_of_html(): void
    {
        $langTable = $this->prefix.'product_lang';
        $this->seed("UPDATE `{$langTable}` SET description_short = '<b>Bold</b> text' WHERE id_product = 1");

        $result = $this->tool->execute(['columns' => ['description_short'], 'search' => 'Blue']);
        $data = $this->flat($result);

        $desc = $data['products'][0]['description_short'] ?? '';
        self::assertStringNotContainsString('<b>', $desc);
        self::assertStringContainsString('Bold', $desc);
    }

    public function test_quantity_reads_from_stock_available_not_product_column(): void
    {
        $result = $this->tool->execute(['columns' => ['quantity'], 'search' => 'Blue']);
        $data = $this->flat($result);

        self::assertCount(1, $data['products']);
        self::assertSame(10, $data['products'][0]['quantity']);
    }

    public function test_out_of_stock_filter_reads_from_stock_available(): void
    {
        $result = $this->tool->execute(['out_of_stock' => true]);
        $data = $this->flat($result);

        self::assertCount(1, $data['products']);
        self::assertSame(0, $data['products'][0]['quantity']);
    }

    public function test_aggregate_out_of_stock_count_reads_from_stock_available(): void
    {
        $result = $this->tool->execute(['aggregate' => true]);
        $data = $this->flat($result);

        self::assertSame(1, $data['stats']['out_of_stock_count']);
    }

    public function test_total_counts_every_matching_row_not_just_the_page(): void
    {
        $all = $this->meta($this->tool->execute([]));
        $page = $this->meta($this->tool->execute(['limit' => 1]));

        self::assertSame($all['total'], $page['total']);
        self::assertSame(1, $page['count']);
        self::assertGreaterThanOrEqual(1, $all['total']);
    }

    public function test_the_second_page_returns_rows_the_first_page_left(): void
    {
        $first = $this->rows($this->tool->execute(['limit' => 1]), 'products');
        $second = $this->rows($this->tool->execute(['limit' => 1, 'offset' => 1]), 'products');

        if ($second === []) {
            self::assertFalse($this->meta($this->tool->execute(['limit' => 1, 'offset' => 1]))['has_more']);

            return;
        }

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

    public function test_default_column_set_does_not_carry_product_cost(): void
    {
        $data = $this->flat($this->tool->execute([]));

        self::assertNotContains('wholesale_price', $data['columns_returned']);
        self::assertArrayNotHasKey('wholesale_price', $data['products'][0]);
    }

    public function test_requesting_product_cost_by_name_is_refused(): void
    {
        $result = $this->tool->execute(['columns' => ['wholesale_price']]);
        $envelope = $this->envelope($result);

        self::assertFalse($envelope['success']);
        self::assertSame('REFUSED_COLUMN', $this->errorCode($result));
        self::assertContains('wholesale_price', $envelope['error']['refused_columns']);
        self::assertStringContainsString('wholesale_price', $envelope['error']['message']);
    }

    public function test_requesting_the_advertised_blocked_description_column_is_refused(): void
    {
        $result = $this->tool->execute(['columns' => ['description']]);

        self::assertSame('REFUSED_COLUMN', $this->errorCode($result));
        self::assertContains('description', $this->envelope($result)['error']['refused_columns']);
    }

    public function test_a_blocked_column_beside_an_allowed_one_is_still_refused(): void
    {
        $result = $this->tool->execute(['columns' => ['name', 'wholesale_price']]);

        self::assertSame('REFUSED_COLUMN', $this->errorCode($result));
    }

    public function test_wildcard_succeeds_and_omits_product_cost(): void
    {
        $result = $this->tool->execute(['columns' => ['*']]);
        $data = $this->flat($result);

        self::assertTrue($this->envelope($result)['success']);
        self::assertNotContains('wholesale_price', $data['columns_returned']);
        self::assertArrayNotHasKey('wholesale_price', $data['products'][0]);
    }

    public function test_schema_lists_product_cost_as_blocked_and_not_available(): void
    {
        $data = $this->flat($this->tool->execute(['schema' => true]));

        self::assertContains('wholesale_price', $data['blocked_columns']);
        self::assertNotContains('wholesale_price', $data['available_columns']);
    }

    public function test_aggregate_mode_reports_no_cost_figure(): void
    {
        $data = $this->flat($this->tool->execute(['aggregate' => true]));

        self::assertArrayNotHasKey('wholesale_price', $data['stats']);
        self::assertArrayNotHasKey('avg_wholesale_price', $data['stats']);
    }

    public function test_every_column_named_in_the_documentation_is_one_the_tool_returns(): void
    {
        $this->assertEveryDocumentedColumnIsEmitted($this->tool);
    }
}

<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\PrestaShop\Tests\Helpers\ActsAsChatTierEmployee;
use PhpClaw\PrestaShop\Tests\Helpers\AssertsToolEnvelope;
use PhpClaw\PrestaShop\Tests\Helpers\PsDbTestCase;
use PhpClaw\PrestaShop\Tools\PsStockTool;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(PsStockTool::class)]
final class PsStockToolTest extends PsDbTestCase
{
    use ActsAsChatTierEmployee;
    use AssertsToolEnvelope;

    private PsStockTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actAsChatTierEmployee();

        $stockTable = $this->prefix.'stock_available';
        $productTable = $this->prefix.'product';
        $langTable = $this->prefix.'product_lang';

        $this->resetTables([
            "CREATE TABLE IF NOT EXISTS `{$stockTable}` (
                id_stock_available   INT NOT NULL AUTO_INCREMENT,
                id_product           INT NOT NULL DEFAULT 0,
                id_product_attribute INT NOT NULL DEFAULT 0,
                quantity             INT NOT NULL DEFAULT 0,
                physical_quantity    INT NOT NULL DEFAULT 0,
                reserved_quantity    INT NOT NULL DEFAULT 0,
                location             VARCHAR(64) NOT NULL DEFAULT '',
                date_add             DATETIME NOT NULL DEFAULT '2024-01-01 00:00:00',
                PRIMARY KEY (id_stock_available)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$productTable}` (
                id_product  INT          NOT NULL AUTO_INCREMENT,
                reference   VARCHAR(64)  NOT NULL DEFAULT '',
                PRIMARY KEY (id_product)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$langTable}` (
                id_product INT          NOT NULL DEFAULT 0,
                id_lang    INT          NOT NULL DEFAULT 1,
                name       VARCHAR(255) NOT NULL DEFAULT ''
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ]);

        $this->seed("INSERT INTO `{$stockTable}` (id_product,id_product_attribute,quantity,physical_quantity,reserved_quantity,location,date_add) VALUES (1,0,10,12,2,'Shelf A','2024-01-01 00:00:00')");
        $this->seed("INSERT INTO `{$stockTable}` (id_product,id_product_attribute,quantity,physical_quantity,reserved_quantity,location,date_add) VALUES (2,0,0,0,0,'','2024-01-01 00:00:00')");
        $this->seed("INSERT INTO `{$stockTable}` (id_product,id_product_attribute,quantity,physical_quantity,reserved_quantity,location,date_add) VALUES (3,0,3,3,0,'Shelf B','2024-01-01 00:00:00')");
        $this->seed("INSERT INTO `{$stockTable}` (id_product,id_product_attribute,quantity,physical_quantity,reserved_quantity,location,date_add) VALUES (1,5,2,2,0,'','2024-01-01 00:00:00')");
        $this->seed("INSERT INTO `{$productTable}` (id_product,reference) VALUES (1,'SKU-001')");
        $this->seed("INSERT INTO `{$productTable}` (id_product,reference) VALUES (2,'SKU-002')");
        $this->seed("INSERT INTO `{$productTable}` (id_product,reference) VALUES (3,'SKU-003')");
        $this->seed("INSERT INTO `{$langTable}` (id_product,id_lang,name) VALUES (1,1,'Widget A')");
        $this->seed("INSERT INTO `{$langTable}` (id_product,id_lang,name) VALUES (2,1,'Widget B')");
        $this->seed("INSERT INTO `{$langTable}` (id_product,id_lang,name) VALUES (3,1,'Widget C')");

        $this->tool = new PsStockTool($this->db, $this->prefix);
    }

    protected function tearDown(): void
    {
        $this->stopActingAsEmployee();

        parent::tearDown();
    }

    public function test_name_returns_ps_stock(): void
    {
        self::assertSame('ps_stock', $this->tool->name());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        self::assertStringContainsString(
            'QUERY PrestaShop stock availability',
            $this->tool->description(),
        );
    }

    public function test_execute_returns_three_stock_rows(): void
    {
        $data = $this->flat($this->tool->execute([]));

        self::assertArrayHasKey('stock', $data);
        self::assertCount(3, $data['stock']);
    }

    public function test_execute_throws_on_null_db(): void
    {
        $tool = new PsStockTool(null, $this->prefix);

        $this->expectException(ToolException::class);
        $tool->execute([]);
    }

    public function test_schema_mode_does_not_require_db(): void
    {
        $tool = new PsStockTool(null, $this->prefix);

        $data = $this->flat($tool->execute(['mode' => 'schema']));

        self::assertSame('schema', $data['mode']);
        self::assertSame(
            (new \ReflectionClassConstant(PsStockTool::class, 'AVAILABLE_COLUMNS'))->getValue(),
            $data['available_columns'],
        );
        self::assertContains('schema', $data['modes']);
    }

    public function test_aggregate_mode_returns_stats(): void
    {
        $result = $this->tool->execute(['mode' => 'aggregate']);
        $data = $this->flat($result);

        self::assertSame('aggregate', $data['mode']);
        self::assertArrayHasKey('total_skus', $data);
        self::assertSame(3, $data['total_skus']);
        self::assertSame(1, $data['out_of_stock_count']);
    }

    public function test_aggregate_low_stock_threshold(): void
    {
        $result = $this->tool->execute(['mode' => 'aggregate', 'low_stock_threshold' => 5]);
        $data = $this->flat($result);

        self::assertSame(1, $data['low_stock_count']);
    }

    public function test_out_of_stock_filter(): void
    {
        $result = $this->tool->execute(['out_of_stock' => true]);
        $data = $this->flat($result);

        self::assertCount(1, $data['stock']);
    }

    public function test_low_stock_filter(): void
    {
        $result = $this->tool->execute(['low_stock' => true, 'low_stock_threshold' => 5]);
        $data = $this->flat($result);

        self::assertCount(1, $data['stock']);
    }

    public function test_product_id_filter(): void
    {
        $result = $this->tool->execute(['product_id' => 1]);
        $data = $this->flat($result);

        self::assertCount(1, $data['stock']);
    }

    public function test_search_by_name(): void
    {
        $result = $this->tool->execute(['search' => 'Widget A']);
        $data = $this->flat($result);

        self::assertCount(1, $data['stock']);
    }

    public function test_search_by_reference(): void
    {
        $result = $this->tool->execute(['search' => 'SKU-002']);
        $data = $this->flat($result);

        self::assertCount(1, $data['stock']);
    }

    public function test_columns_wildcard_returns_all(): void
    {
        $result = $this->tool->execute(['columns' => ['*']]);
        $data = $this->flat($result);

        self::assertContains('physical_quantity', $data['columns_returned']);
        self::assertContains('location', $data['columns_returned']);
    }

    public function test_columns_specific_subset(): void
    {
        $result = $this->tool->execute(['columns' => ['quantity', 'location']]);
        $data = $this->flat($result);

        self::assertArrayHasKey('quantity', $data['stock'][0]);
        self::assertArrayHasKey('location', $data['stock'][0]);
    }

    public function test_columns_invalid_falls_back_to_default(): void
    {
        $result = $this->tool->execute(['columns' => ['nonexistent']]);
        $data = $this->flat($result);

        self::assertContains('id', $data['columns_returned']);
    }

    public function test_limit_applied(): void
    {
        $result = $this->tool->execute(['limit' => 2]);
        $data = $this->flat($result);

        self::assertCount(2, $data['stock']);
    }

    public function test_default_columns_returned(): void
    {
        $result = $this->tool->execute([]);
        $data = $this->flat($result);

        self::assertContains('id', $data['columns_returned']);
        self::assertContains('quantity', $data['columns_returned']);
    }

    public function test_search_adds_joins_when_not_in_columns(): void
    {
        $result = $this->tool->execute(['columns' => ['quantity'], 'search' => 'Widget']);
        $data = $this->flat($result);

        self::assertArrayHasKey('stock', $data);
    }

    public function test_empty_columns_array_returns_defaults(): void
    {
        $result = $this->tool->execute(['columns' => []]);
        $data = $this->flat($result);

        self::assertContains('id', $data['columns_returned']);
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
        $first = $this->rows($this->tool->execute(['limit' => 1]), 'stock');
        $second = $this->rows($this->tool->execute(['limit' => 1, 'offset' => 1]), 'stock');

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
}

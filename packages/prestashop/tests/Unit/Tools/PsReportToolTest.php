<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\PrestaShop\Tests\Helpers\ActsAsChatTierEmployee;
use PhpClaw\PrestaShop\Tests\Helpers\AssertsToolEnvelope;
use PhpClaw\PrestaShop\Tests\Helpers\PsDbTestCase;
use PhpClaw\PrestaShop\Tools\PsReportTool;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(PsReportTool::class)]
final class PsReportToolTest extends PsDbTestCase
{
    use ActsAsChatTierEmployee;
    use AssertsToolEnvelope;

    private PsReportTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actAsChatTierEmployee();

        $ordersTable = $this->prefix.'orders';
        $orderDetailTable = $this->prefix.'order_detail';

        $this->resetTables([
            "CREATE TABLE IF NOT EXISTS `{$ordersTable}` (
                id_order            INT           NOT NULL AUTO_INCREMENT,
                id_customer         INT           NOT NULL DEFAULT 0,
                total_paid_tax_incl DECIMAL(20,6) NOT NULL DEFAULT 0,
                valid               TINYINT       NOT NULL DEFAULT 1,
                date_add            DATETIME      NOT NULL DEFAULT '2024-01-01 00:00:00',
                PRIMARY KEY (id_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$orderDetailTable}` (
                id_order_detail       INT           NOT NULL AUTO_INCREMENT,
                id_order              INT           NOT NULL DEFAULT 0,
                product_id            INT           NOT NULL DEFAULT 0,
                product_name          VARCHAR(255)  NOT NULL DEFAULT '',
                product_quantity      INT           NOT NULL DEFAULT 1,
                total_price_tax_incl  DECIMAL(20,6) NOT NULL DEFAULT 0,
                PRIMARY KEY (id_order_detail)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ]);

        $this->seed("INSERT INTO `{$ordersTable}` (id_order,id_customer,total_paid_tax_incl,valid,date_add) VALUES (1,1,120.00,1,'2024-01-15 10:00:00')");
        $this->seed("INSERT INTO `{$ordersTable}` (id_order,id_customer,total_paid_tax_incl,valid,date_add) VALUES (2,2,85.00,1,'2024-01-16 11:00:00')");
        $this->seed("INSERT INTO `{$ordersTable}` (id_order,id_customer,total_paid_tax_incl,valid,date_add) VALUES (3,3,50.00,0,'2024-01-17 12:00:00')");
        $this->seed("INSERT INTO `{$orderDetailTable}` (id_order,product_id,product_name,product_quantity,total_price_tax_incl) VALUES (1,10,'Widget A',2,120.00)");
        $this->seed("INSERT INTO `{$orderDetailTable}` (id_order,product_id,product_name,product_quantity,total_price_tax_incl) VALUES (2,10,'Widget A',1,85.00)");

        $this->tool = new PsReportTool($this->db, $this->prefix);
    }

    protected function tearDown(): void
    {
        $this->stopActingAsEmployee();

        parent::tearDown();
    }

    public function test_name_returns_ps_report(): void
    {
        self::assertSame('ps_report', $this->tool->name());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        self::assertStringContainsString(
            'PrestaShop revenue and order reports',
            $this->tool->description(),
        );
    }

    public function test_input_schema_has_group_by_property(): void
    {
        self::assertArrayHasKey('group_by', $this->tool->inputSchema()['properties']);
    }

    public function test_execute_only_counts_valid_orders(): void
    {
        $result = $this->tool->execute(['group_by' => 'day']);
        $data = $this->flat($result);

        $totalRevenue = array_sum(array_column($data['periods'], 'revenue'));

        self::assertEqualsWithDelta(205.00, $totalRevenue, 0.01);
    }

    public function test_execute_groups_by_day_returns_period_key(): void
    {
        $result = $this->tool->execute(['group_by' => 'day']);
        $data = $this->flat($result);

        self::assertGreaterThanOrEqual(1, count($data['periods']));

        if (isset($data['periods'][0])) {
            self::assertArrayHasKey('period', $data['periods'][0]);
            self::assertArrayHasKey('revenue', $data['periods'][0]);
        }
    }

    public function test_execute_default_group_by_is_day(): void
    {
        $result = $this->tool->execute([]);

        self::assertJson($result);
        $data = $this->flat($result);
        self::assertSame('day', $data['group_by']);
    }

    public function test_execute_throws_on_null_db(): void
    {
        $tool = new PsReportTool(null, $this->prefix);

        $this->expectException(ToolException::class);
        $tool->execute([]);
    }

    public function test_schema_mode_does_not_require_db(): void
    {
        $tool = new PsReportTool(null, $this->prefix);

        $data = $this->flat($tool->execute(['schema' => true]));

        self::assertSame('schema', $data['mode']);
        self::assertSame(
            (new \ReflectionClassConstant(PsReportTool::class, 'AVAILABLE_COLUMNS'))->getValue(),
            $data['available_columns'],
        );
        self::assertArrayHasKey('revenue', $data['column_descriptions']);
    }

    public function test_group_by_month(): void
    {
        $result = $this->tool->execute(['group_by' => 'month']);
        $data = $this->flat($result);

        self::assertSame('month', $data['group_by']);
        self::assertArrayHasKey('periods', $data);
    }

    public function test_group_by_week(): void
    {
        $result = $this->tool->execute(['group_by' => 'week']);
        $data = $this->flat($result);

        self::assertSame('week', $data['group_by']);
        self::assertArrayHasKey('periods', $data);
    }

    public function test_group_by_invalid_falls_back_to_day(): void
    {
        $result = $this->tool->execute(['group_by' => 'year']);
        $data = $this->flat($result);

        self::assertSame('day', $data['group_by']);
    }

    public function test_aggregate_mode_returns_stats(): void
    {
        $result = $this->tool->execute(['aggregate' => true]);
        $data = $this->flat($result);

        self::assertSame('aggregate', $data['mode']);
        self::assertArrayHasKey('total_revenue', $data);
        self::assertArrayHasKey('total_orders', $data);
        self::assertArrayHasKey('best_selling_products', $data);
        self::assertArrayHasKey('revenue_by_month', $data);
        self::assertSame(2, $data['total_orders']);
        self::assertEqualsWithDelta(205.00, $data['total_revenue'], 0.01);
    }

    public function test_aggregate_best_sellers_populated(): void
    {
        $result = $this->tool->execute(['aggregate' => true]);
        $data = $this->flat($result);

        self::assertNotEmpty($data['best_selling_products']);
        self::assertSame('Widget A', $data['best_selling_products'][0]['product_name']);
    }

    public function test_filter_by_date_after(): void
    {
        $result = $this->tool->execute(['group_by' => 'day', 'date_after' => '2024-01-16']);
        $data = $this->flat($result);

        $totalRevenue = array_sum(array_column($data['periods'], 'revenue'));
        self::assertEqualsWithDelta(85.00, $totalRevenue, 0.01);
    }

    public function test_filter_by_date_before(): void
    {
        $result = $this->tool->execute(['group_by' => 'day', 'date_before' => '2024-01-15']);
        $data = $this->flat($result);

        $totalRevenue = array_sum(array_column($data['periods'], 'revenue'));
        self::assertEqualsWithDelta(120.00, $totalRevenue, 0.01);
    }

    public function test_columns_wildcard_includes_all(): void
    {
        $result = $this->tool->execute(['columns' => ['*']]);
        $data = $this->flat($result);

        self::assertContains('new_customers', $data['columns_returned']);
    }

    public function test_columns_specific_subset(): void
    {
        $result = $this->tool->execute(['columns' => ['revenue', 'orders']]);
        $data = $this->flat($result);

        self::assertContains('period', $data['columns_returned']);
        self::assertContains('revenue', $data['columns_returned']);
    }

    public function test_columns_invalid_falls_back_to_default(): void
    {
        $result = $this->tool->execute(['columns' => ['nonexistent']]);
        $data = $this->flat($result);

        self::assertContains('period', $data['columns_returned']);
    }

    public function test_products_sold_column_uses_join_path(): void
    {
        $result = $this->tool->execute(['columns' => ['products_sold']]);
        $data = $this->flat($result);

        self::assertContains('products_sold', $data['columns_returned']);
    }

    public function test_order_dir_asc(): void
    {
        $result = $this->tool->execute(['order_dir' => 'ASC']);
        $data = $this->flat($result);

        self::assertArrayHasKey('periods', $data);
    }

    public function test_limit_applied(): void
    {
        $result = $this->tool->execute(['limit' => 1]);
        $data = $this->flat($result);

        self::assertLessThanOrEqual(1, count($data['periods']));
    }

    public function test_aggregate_with_date_filter(): void
    {
        $result = $this->tool->execute(['aggregate' => true, 'date_after' => '2024-01-16']);
        $data = $this->flat($result);

        self::assertSame(1, $data['total_orders']);
    }

    public function test_count_metadata_present(): void
    {
        $result = $this->tool->execute([]);
        $data = $this->flat($result);

        self::assertArrayHasKey('count', $data);
        self::assertArrayHasKey('columns_returned', $data);
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
        $first = $this->rows($this->tool->execute(['limit' => 1]), 'periods');
        $second = $this->rows($this->tool->execute(['limit' => 1, 'offset' => 1]), 'periods');

        if ($second === []) {
            self::assertFalse($this->meta($this->tool->execute(['limit' => 1, 'offset' => 1]))['has_more']);

            return;
        }

        self::assertNotSame($first[0], $second[0]);
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

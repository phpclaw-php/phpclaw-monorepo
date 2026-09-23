<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tests\Unit\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\OpenCart\Tests\Helpers\OcDbTestCase;
use PhpClaw\OpenCart\Tools\OcOrderTool;

final class OcOrderToolTest extends OcDbTestCase
{
    private OcOrderTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $orderTable = $this->prefix.'order';
        $statusTable = $this->prefix.'order_status';

        $this->resetTables([
            "CREATE TABLE IF NOT EXISTS `{$orderTable}` (
                order_id        INT          NOT NULL AUTO_INCREMENT,
                firstname       VARCHAR(32)  NOT NULL DEFAULT '',
                lastname        VARCHAR(32)  NOT NULL DEFAULT '',
                email           VARCHAR(96)  NOT NULL DEFAULT '',
                telephone       VARCHAR(32)  NOT NULL DEFAULT '',
                total           DECIMAL(15,4) NOT NULL DEFAULT 0,
                currency_code   VARCHAR(3)   NOT NULL DEFAULT 'USD',
                order_status_id INT          NOT NULL DEFAULT 0,
                customer_id     INT          NOT NULL DEFAULT 0,
                payment_method  VARCHAR(128) NOT NULL DEFAULT '',
                shipping_method VARCHAR(128) NOT NULL DEFAULT '',
                date_added      DATETIME     NOT NULL DEFAULT '2024-01-01 00:00:00',
                date_modified   DATETIME     NOT NULL DEFAULT '2024-01-01 00:00:00',
                store_name      VARCHAR(64)  NOT NULL DEFAULT '',
                comment         TEXT         NOT NULL,
                PRIMARY KEY (order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$statusTable}` (
                order_status_id INT         NOT NULL DEFAULT 0,
                language_id     INT         NOT NULL DEFAULT 1,
                name            VARCHAR(32) NOT NULL DEFAULT ''
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ]);

        $this->seed("INSERT INTO `{$orderTable}` (order_id,firstname,lastname,email,telephone,total,currency_code,order_status_id,customer_id,payment_method,shipping_method,date_added,date_modified,store_name,comment) VALUES (1,'Alice','Smith','alice@example.com','555',99.99,'USD',1,10,'PayPal','Flat Rate','2024-06-01','2024-06-01','Store','')");
        $this->seed("INSERT INTO `{$orderTable}` (order_id,firstname,lastname,email,telephone,total,currency_code,order_status_id,customer_id,payment_method,shipping_method,date_added,date_modified,store_name,comment) VALUES (2,'Bob','Jones','bob@example.com','556',14.50,'USD',2,11,'Stripe','Free','2024-07-01','2024-07-01','Store','')");
        $this->seed("INSERT INTO `{$orderTable}` (order_id,firstname,lastname,email,telephone,total,currency_code,order_status_id,customer_id,payment_method,shipping_method,date_added,date_modified,store_name,comment) VALUES (3,'Carol','Doe','carol@example.com','557',200.00,'EUR',1,12,'PayPal','Express','2024-08-01','2024-08-01','Store','')");
        $this->seed("INSERT INTO `{$statusTable}` (order_status_id,language_id,name) VALUES (1,1,'Complete')");
        $this->seed("INSERT INTO `{$statusTable}` (order_status_id,language_id,name) VALUES (2,1,'Pending')");

        $this->tool = new OcOrderTool($this->db, $this->prefix, true);
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
        self::assertTrue($d['success']);

        return $d['meta'];
    }

    public function test_name(): void
    {
        self::assertSame('oc_order', $this->tool->name());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        self::assertStringContainsString(
            'OpenCart orders with revenue and status data',
            $this->tool->description(),
        );
    }

    public function test_input_schema_has_required_keys(): void
    {
        $schema = $this->tool->inputSchema();
        self::assertArrayHasKey('type', $schema);
        self::assertArrayHasKey('properties', $schema);
    }

    public function test_required_capability(): void
    {
        self::assertSame('access', $this->tool->requiredCapability());
    }

    public function test_forbidden_when_caller_may_not_use_module(): void
    {
        $tool = new OcOrderTool($this->db, $this->prefix, false);
        $d = json_decode($tool->execute([]), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($d['success']);
        self::assertSame('FORBIDDEN', $d['error']['code']);
    }

    public function test_returns_orders(): void
    {
        $data = $this->payload($this->tool->execute([]));
        self::assertCount(3, $data['orders']);
    }

    public function test_throws_when_pdo_is_null(): void
    {
        $tool = new OcOrderTool(null, $this->prefix, true);
        $this->expectException(ToolException::class);
        $tool->execute([]);
    }

    public function test_schema_mode_returns_schema_without_query(): void
    {
        $data = $this->payload($this->tool->execute(['schema' => true]));
        self::assertArrayHasKey('available_columns', $data);
        self::assertArrayHasKey('default_columns', $data);
    }

    public function test_aggregate_returns_stats(): void
    {
        $data = $this->payload($this->tool->execute(['aggregate' => true]));
        self::assertSame(3, $data['stats']['total']);
        self::assertArrayHasKey('total_revenue', $data['stats']);
        self::assertArrayHasKey('avg_order_value', $data['stats']);
        self::assertArrayHasKey('by_status', $data['stats']);
    }

    public function test_filter_by_order_status_id(): void
    {
        $data = $this->payload($this->tool->execute(['order_status_id' => 1]));
        self::assertCount(2, $data['orders']);
    }

    public function test_filter_by_customer_id(): void
    {
        $data = $this->payload($this->tool->execute(['customer_id' => 10]));
        self::assertCount(1, $data['orders']);
    }

    public function test_search_by_firstname(): void
    {
        $data = $this->payload($this->tool->execute(['search' => 'Alice']));
        self::assertCount(1, $data['orders']);
    }

    public function test_search_by_email(): void
    {
        $data = $this->payload($this->tool->execute(['search' => 'bob@example']));
        self::assertCount(1, $data['orders']);
    }

    public function test_filter_by_min_total(): void
    {
        $data = $this->payload($this->tool->execute(['min_total' => 100.0]));
        self::assertCount(1, $data['orders']);
    }

    public function test_filter_by_max_total(): void
    {
        $data = $this->payload($this->tool->execute(['max_total' => 50.0]));
        self::assertCount(1, $data['orders']);
    }

    public function test_filter_by_date_after(): void
    {
        $data = $this->payload($this->tool->execute(['date_after' => '2024-07-01']));
        self::assertCount(2, $data['orders']);
    }

    public function test_filter_by_date_before(): void
    {
        $data = $this->payload($this->tool->execute(['date_before' => '2024-06-30']));
        self::assertCount(1, $data['orders']);
    }

    public function test_date_range_combined(): void
    {
        $data = $this->payload($this->tool->execute(['date_after' => '2024-07-01', 'date_before' => '2024-07-31']));
        self::assertCount(1, $data['orders']);
    }

    public function test_columns_wildcard_returns_all_columns(): void
    {
        $data = $this->payload($this->tool->execute(['columns' => ['*']]));
        $row = $data['orders'][0] ?? [];
        self::assertArrayHasKey('email', $row);
        self::assertArrayHasKey('telephone', $row);
    }

    public function test_columns_empty_returns_defaults(): void
    {
        $result = $this->tool->execute(['columns' => []]);
        $meta = $this->meta($result);
        self::assertContains('id', $meta['columns_returned']);
        self::assertContains('total', $meta['columns_returned']);
        self::assertNotContains('email', $meta['columns_returned']);
    }

    public function test_limit_caps_results(): void
    {
        $data = $this->payload($this->tool->execute(['limit' => 2]));
        self::assertLessThanOrEqual(2, count($data['orders']));
    }

    public function test_aggregate_with_status_filter(): void
    {
        $data = $this->payload($this->tool->execute(['aggregate' => true, 'order_status_id' => 2]));
        self::assertSame(1, $data['stats']['total']);
    }

    public function test_schema_mode_works_without_pdo(): void
    {
        $tool = new OcOrderTool(null, $this->prefix, true);
        $data = $this->payload($tool->execute(['schema' => true]));
        self::assertArrayHasKey('available_columns', $data);
    }

    public function test_invalid_column_names_filtered_out(): void
    {
        $data = $this->payload($this->tool->execute(['columns' => ['id', 'nonexistent_col']]));
        $row = $data['orders'][0] ?? [];
        self::assertArrayHasKey('id', $row);
        self::assertArrayNotHasKey('nonexistent_col', $row);
    }
}

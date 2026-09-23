<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\PrestaShop\Tests\Helpers\ActsAsChatTierEmployee;
use PhpClaw\PrestaShop\Tests\Helpers\AssertsToolEnvelope;
use PhpClaw\PrestaShop\Tests\Helpers\PsDbTestCase;
use PhpClaw\PrestaShop\Tools\PsCartTool;
use PhpClaw\PrestaShop\Tools\ToolOutputEncoder;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(PsCartTool::class)]
final class PsCartToolTest extends PsDbTestCase
{
    use ActsAsChatTierEmployee;
    use AssertsToolEnvelope;

    private PsCartTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actAsChatTierEmployee();

        $cartTable = $this->prefix.'cart';
        $customerTable = $this->prefix.'customer';
        $carrierTable = $this->prefix.'carrier';
        $currencyTable = $this->prefix.'currency';
        $ordersTable = $this->prefix.'orders';
        $cartProductTable = $this->prefix.'cart_product';
        $productShopTable = $this->prefix.'product_shop';

        $this->resetTables([
            "CREATE TABLE IF NOT EXISTS `{$cartTable}` (
                id_cart          INT           NOT NULL AUTO_INCREMENT,
                id_customer      INT           NOT NULL DEFAULT 0,
                id_currency      INT           NOT NULL DEFAULT 1,
                id_carrier       INT           NOT NULL DEFAULT 0,
                id_shop          INT           NOT NULL DEFAULT 1,
                date_add         DATETIME      NOT NULL DEFAULT '2024-01-01 00:00:00',
                date_upd         DATETIME      NOT NULL DEFAULT '2024-01-01 00:00:00',
                PRIMARY KEY (id_cart)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$customerTable}` (
                id_customer INT          NOT NULL AUTO_INCREMENT,
                firstname   VARCHAR(255) NOT NULL DEFAULT '',
                lastname    VARCHAR(255) NOT NULL DEFAULT '',
                email       VARCHAR(255) NOT NULL DEFAULT '',
                PRIMARY KEY (id_customer)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$carrierTable}` (
                id_carrier INT         NOT NULL AUTO_INCREMENT,
                name       VARCHAR(64) NOT NULL DEFAULT '',
                PRIMARY KEY (id_carrier)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$currencyTable}` (
                id_currency INT        NOT NULL AUTO_INCREMENT,
                iso_code    VARCHAR(3) NOT NULL DEFAULT '',
                PRIMARY KEY (id_currency)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$cartProductTable}` (
                id_cart    INT NOT NULL DEFAULT 0,
                id_product INT NOT NULL DEFAULT 0,
                id_shop    INT NOT NULL DEFAULT 1,
                quantity   INT NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$productShopTable}` (
                id_product INT           NOT NULL DEFAULT 0,
                id_shop    INT           NOT NULL DEFAULT 1,
                price      DECIMAL(20,6) NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$ordersTable}` (
                id_order INT NOT NULL AUTO_INCREMENT,
                id_cart  INT NOT NULL DEFAULT 0,
                PRIMARY KEY (id_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ]);

        $this->seed("INSERT INTO `{$cartTable}` (id_cart,id_customer,id_currency,id_carrier,id_shop,date_add,date_upd) VALUES (1,10,1,1,1,'2024-01-15 10:00:00','2024-01-15 10:00:00')");
        $this->seed("INSERT INTO `{$cartTable}` (id_cart,id_customer,id_currency,id_carrier,id_shop,date_add,date_upd) VALUES (2,11,1,0,1,'2024-01-16 11:00:00','2024-01-16 11:00:00')");
        $this->seed("INSERT INTO `{$productShopTable}` (id_product,id_shop,price) VALUES (100,1,15.00)");
        $this->seed("INSERT INTO `{$productShopTable}` (id_product,id_shop,price) VALUES (101,1,11.00)");
        $this->seed("INSERT INTO `{$cartProductTable}` (id_cart,id_product,id_shop,quantity) VALUES (1,100,1,3)");
        $this->seed("INSERT INTO `{$cartProductTable}` (id_cart,id_product,id_shop,quantity) VALUES (2,101,1,2)");
        $this->seed("INSERT INTO `{$customerTable}` (id_customer,firstname,lastname,email) VALUES (10,'Alice','Smith','alice@example.com')");
        $this->seed("INSERT INTO `{$customerTable}` (id_customer,firstname,lastname,email) VALUES (11,'Bob','Jones','bob@example.com')");
        $this->seed("INSERT INTO `{$carrierTable}` (id_carrier,name) VALUES (1,'DHL')");
        $this->seed("INSERT INTO `{$currencyTable}` (id_currency,iso_code) VALUES (1,'EUR')");
        $this->seed("INSERT INTO `{$ordersTable}` (id_order,id_cart) VALUES (1,1)");

        $this->tool = new PsCartTool($this->db, $this->prefix);
    }

    protected function tearDown(): void
    {
        $this->stopActingAsEmployee();

        parent::tearDown();
    }

    public function test_name_returns_ps_cart(): void
    {
        self::assertSame('ps_cart', $this->tool->name());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        self::assertStringContainsString(
            'QUERY PrestaShop shopping carts',
            $this->tool->description(),
        );
    }

    public function test_execute_returns_carts(): void
    {
        $data = $this->flat($this->tool->execute([]));

        self::assertArrayHasKey('carts', $data);
        self::assertGreaterThanOrEqual(1, count($data['carts']));
    }

    public function test_execute_abandoned_only_returns_carts_without_orders(): void
    {
        $result = $this->tool->execute(['abandoned_only' => true]);
        $data = $this->flat($result);

        self::assertArrayHasKey('carts', $data);
        self::assertCount(1, $data['carts']);
    }

    public function test_execute_throws_on_null_db(): void
    {
        $tool = new PsCartTool(null, $this->prefix);

        $this->expectException(ToolException::class);
        $tool->execute([]);
    }

    public function test_schema_mode_does_not_require_db(): void
    {
        $tool = new PsCartTool(null, $this->prefix);

        $data = $this->flat($tool->execute(['mode' => 'schema']));

        self::assertSame('schema', $data['mode']);
        self::assertSame(
            (new \ReflectionClassConstant(PsCartTool::class, 'AVAILABLE_COLUMNS'))->getValue(),
            $data['available_columns'],
        );
        self::assertContains('schema', $data['modes']);
    }

    public function test_aggregate_mode_returns_stats(): void
    {
        $result = $this->tool->execute(['mode' => 'aggregate']);
        $data = $this->flat($result);

        self::assertSame('aggregate', $data['mode']);
        self::assertArrayHasKey('total_carts', $data);
        self::assertArrayHasKey('abandoned_count', $data);
        self::assertArrayHasKey('avg_cart_value', $data);
        self::assertArrayHasKey('total_value', $data);
        self::assertSame(2, $data['total_carts']);
        self::assertSame(1, $data['abandoned_count']);
    }

    public function test_aggregate_with_date_filter(): void
    {
        $result = $this->tool->execute(['mode' => 'aggregate', 'date_after' => '2024-01-16']);
        $data = $this->flat($result);

        self::assertSame(1, $data['total_carts']);
    }

    public function test_filter_by_search(): void
    {
        $result = $this->tool->execute(['search' => 'Alice']);
        $data = $this->flat($result);

        self::assertCount(1, $data['carts']);
    }

    public function test_filter_by_date_after(): void
    {
        $result = $this->tool->execute(['date_after' => '2024-01-16']);
        $data = $this->flat($result);

        self::assertCount(1, $data['carts']);
    }

    public function test_filter_by_date_before(): void
    {
        $result = $this->tool->execute(['date_before' => '2024-01-15']);
        $data = $this->flat($result);

        self::assertCount(1, $data['carts']);
    }

    public function test_filter_by_min_total(): void
    {
        $result = $this->tool->execute(['min_total' => 40]);
        $data = $this->flat($result);

        self::assertCount(1, $data['carts']);
    }

    public function test_columns_wildcard_returns_all(): void
    {
        $result = $this->tool->execute(['columns' => ['*']]);
        $data = $this->flat($result);

        self::assertContains('customer_email', $data['columns_returned']);
        self::assertContains('carrier_name', $data['columns_returned']);
        self::assertContains('currency', $data['columns_returned']);
    }

    public function test_customer_email_column_explicit(): void
    {
        $result = $this->tool->execute(['columns' => ['id', 'customer_email']]);
        $data = $this->flat($result);

        self::assertContains('customer_email', $data['columns_returned']);
    }

    public function test_columns_invalid_falls_back_to_default(): void
    {
        $result = $this->tool->execute(['columns' => ['nonexistent']]);
        $data = $this->flat($result);

        self::assertContains('id', $data['columns_returned']);
    }

    public function test_columns_empty_array_returns_default(): void
    {
        $result = $this->tool->execute(['columns' => []]);
        $data = $this->flat($result);

        self::assertContains('id', $data['columns_returned']);
    }

    public function test_limit_applied(): void
    {
        $result = $this->tool->execute(['limit' => 1]);
        $data = $this->flat($result);

        self::assertCount(1, $data['carts']);
    }

    public function test_carrier_name_column_triggers_join(): void
    {
        $result = $this->tool->execute(['columns' => ['carrier_name']]);
        $data = $this->flat($result);

        self::assertContains('carrier_name', $data['columns_returned']);
    }

    public function test_currency_column_triggers_join(): void
    {
        $result = $this->tool->execute(['columns' => ['currency']]);
        $data = $this->flat($result);

        self::assertContains('currency', $data['columns_returned']);
    }

    public function test_empty_result_when_no_match(): void
    {
        $result = $this->tool->execute(['search' => 'ZZZNoSuchCustomer99999']);
        $data = $this->flat($result);

        self::assertSame([], $data['carts']);
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

    public function test_total_counts_every_matching_cart_not_just_the_page(): void
    {
        $meta = $this->meta($this->tool->execute(['limit' => 1]));

        self::assertSame(1, $meta['count']);
        self::assertSame(2, $meta['total']);
        self::assertTrue($meta['has_more']);
        self::assertSame(1, $meta['next_offset']);
    }

    public function test_the_second_page_returns_the_row_the_first_page_left(): void
    {
        $first = $this->rows($this->tool->execute(['limit' => 1]), 'carts');
        $second = $this->rows($this->tool->execute(['limit' => 1, 'offset' => 1]), 'carts');

        self::assertNotSame($first[0]['id'], $second[0]['id']);
        self::assertFalse($this->meta($this->tool->execute(['limit' => 1, 'offset' => 1]))['has_more']);
    }

    public function test_the_count_uses_the_same_filters_as_the_page(): void
    {
        $meta = $this->meta($this->tool->execute(['search' => 'Alice', 'limit' => 1]));

        self::assertSame(1, $meta['total']);
        self::assertFalse($meta['has_more']);
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

    public function test_an_unknown_argument_is_refused_with_the_accepted_list(): void
    {
        self::assertSame('UNKNOWN_ARGUMENT', $this->errorCode($this->tool->execute(['nope' => 1])));
    }

    public function test_an_out_of_range_limit_is_refused(): void
    {
        self::assertSame('INVALID_LIMIT', $this->errorCode($this->tool->execute(['limit' => 9999])));
    }

    public function test_a_capped_response_stays_decodable_and_inside_the_byte_budget(): void
    {
        $cartTable = $this->prefix.'cart';
        $customerTable = $this->prefix.'customer';

        for ($i = 100; $i < 200; $i++) {
            $this->seed("INSERT INTO `{$customerTable}` (id_customer,firstname,lastname,email) VALUES ({$i},'".str_repeat('N', 60)."','".str_repeat('S', 60)."','x{$i}@example.test')");
            $this->seed("INSERT INTO `{$cartTable}` (id_cart,id_customer,id_currency,id_carrier,id_shop,date_add,date_upd) VALUES ({$i},{$i},1,1,1,'2024-02-01 00:00:00','2024-02-01 00:00:00')");
        }

        $result = $this->tool->execute(['limit' => 100, 'columns' => ['*']]);

        self::assertJson($result, 'A capped response must still decode as one JSON document.');
        self::assertLessThanOrEqual(
            ToolOutputEncoder::MAX_OUTPUT_BYTES,
            strlen($result),
            'A capped response must fit the output budget, envelope included.',
        );

        $meta = $this->meta($result);

        self::assertTrue($meta['truncated']);
        self::assertContains('OUTPUT_TRUNCATED', $this->warningCodes($result));
        self::assertLessThan($meta['total'], $meta['count']);
    }
}

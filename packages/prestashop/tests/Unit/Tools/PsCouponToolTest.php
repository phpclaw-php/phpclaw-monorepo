<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\PrestaShop\Tests\Helpers\ActsAsChatTierEmployee;
use PhpClaw\PrestaShop\Tests\Helpers\AssertsToolEnvelope;
use PhpClaw\PrestaShop\Tests\Helpers\PsDbTestCase;
use PhpClaw\PrestaShop\Tools\PsCouponTool;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(PsCouponTool::class)]
final class PsCouponToolTest extends PsDbTestCase
{
    use ActsAsChatTierEmployee;
    use AssertsToolEnvelope;

    private PsCouponTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actAsChatTierEmployee();

        $cartRuleTable = $this->prefix.'cart_rule';
        $cartRuleLangTable = $this->prefix.'cart_rule_lang';

        $this->resetTables([
            "CREATE TABLE IF NOT EXISTS `{$cartRuleTable}` (
                id_cart_rule       INT           NOT NULL AUTO_INCREMENT,
                code               VARCHAR(254)  NOT NULL DEFAULT '',
                active             TINYINT       NOT NULL DEFAULT 1,
                quantity           INT           NOT NULL DEFAULT 0,
                quantity_per_user  INT           NOT NULL DEFAULT 0,
                reduction_percent  DECIMAL(5,2)  NOT NULL DEFAULT 0,
                reduction_amount   DECIMAL(20,6) NOT NULL DEFAULT 0,
                minimum_amount     DECIMAL(20,6) NOT NULL DEFAULT 0,
                free_shipping      TINYINT       NOT NULL DEFAULT 0,
                date_from          DATETIME      NOT NULL DEFAULT '2024-01-01 00:00:00',
                date_to            DATETIME      NOT NULL DEFAULT '2099-12-31 00:00:00',
                date_add           DATETIME      NOT NULL DEFAULT '2024-01-01 00:00:00',
                PRIMARY KEY (id_cart_rule)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$cartRuleLangTable}` (
                id_cart_rule INT         NOT NULL DEFAULT 0,
                id_lang      INT         NOT NULL DEFAULT 1,
                name         VARCHAR(254) NOT NULL DEFAULT ''
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ]);

        $this->seed("INSERT INTO `{$cartRuleTable}` (id_cart_rule,code,active,quantity,reduction_percent,reduction_amount,free_shipping,date_from,date_to,date_add) VALUES (1,'SUMMER10',1,100,10.00,0,'0','2024-01-01 00:00:00','2099-12-31 00:00:00','2024-01-01 00:00:00')");
        $this->seed("INSERT INTO `{$cartRuleTable}` (id_cart_rule,code,active,quantity,reduction_percent,reduction_amount,free_shipping,date_from,date_to,date_add) VALUES (2,'FREESHIP',1,50,0,0,'1','2024-01-01 00:00:00','2099-12-31 00:00:00','2024-01-02 00:00:00')");
        $this->seed("INSERT INTO `{$cartRuleTable}` (id_cart_rule,code,active,quantity,reduction_percent,reduction_amount,free_shipping,date_from,date_to,date_add) VALUES (3,'EXPIRED5',0,0,5.00,0,'0','2020-01-01 00:00:00','2020-12-31 00:00:00','2020-01-01 00:00:00')");
        $this->seed("INSERT INTO `{$cartRuleLangTable}` (id_cart_rule,id_lang,name) VALUES (1,1,'Summer Discount')");
        $this->seed("INSERT INTO `{$cartRuleLangTable}` (id_cart_rule,id_lang,name) VALUES (2,1,'Free Shipping Promo')");
        $this->seed("INSERT INTO `{$cartRuleLangTable}` (id_cart_rule,id_lang,name) VALUES (3,1,'Expired Coupon')");

        $this->tool = new PsCouponTool($this->db, $this->prefix);
    }

    protected function tearDown(): void
    {
        $this->stopActingAsEmployee();

        parent::tearDown();
    }

    public function test_name_returns_ps_coupon(): void
    {
        self::assertSame('ps_coupon', $this->tool->name());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        self::assertStringContainsString(
            'QUERY PrestaShop cart rules / vouchers',
            $this->tool->description(),
        );
    }

    public function test_execute_returns_three_coupons(): void
    {
        $data = $this->flat($this->tool->execute([]));

        self::assertArrayHasKey('coupons', $data);
        self::assertCount(3, $data['coupons']);
    }

    public function test_execute_throws_on_null_db(): void
    {
        $tool = new PsCouponTool(null, $this->prefix);

        $this->expectException(ToolException::class);
        $tool->execute([]);
    }

    public function test_schema_mode_does_not_require_db(): void
    {
        $tool = new PsCouponTool(null, $this->prefix);

        $data = $this->flat($tool->execute(['mode' => 'schema']));

        self::assertSame('schema', $data['mode']);
        self::assertSame(
            (new \ReflectionClassConstant(PsCouponTool::class, 'AVAILABLE_COLUMNS'))->getValue(),
            $data['available_columns'],
        );
        self::assertContains('schema', $data['modes']);
    }

    public function test_aggregate_mode_returns_stats(): void
    {
        $result = $this->tool->execute(['mode' => 'aggregate']);
        $data = $this->flat($result);

        self::assertSame('aggregate', $data['mode']);
        self::assertArrayHasKey('total_coupons', $data);
        self::assertSame(3, $data['total_coupons']);
        self::assertArrayHasKey('free_shipping_count', $data);
        self::assertSame(1, $data['free_shipping_count']);
    }

    public function test_filter_active_one(): void
    {
        $result = $this->tool->execute(['active' => 1]);
        $data = $this->flat($result);

        self::assertCount(2, $data['coupons']);
    }

    public function test_filter_active_zero(): void
    {
        $result = $this->tool->execute(['active' => 0]);
        $data = $this->flat($result);

        self::assertCount(1, $data['coupons']);
    }

    public function test_filter_expired(): void
    {
        $result = $this->tool->execute(['expired' => true]);
        $data = $this->flat($result);

        self::assertCount(1, $data['coupons']);
    }

    public function test_filter_free_shipping(): void
    {
        $result = $this->tool->execute(['free_shipping' => true]);
        $data = $this->flat($result);

        self::assertCount(1, $data['coupons']);
    }

    public function test_search_by_code(): void
    {
        $result = $this->tool->execute(['search' => 'SUMMER']);
        $data = $this->flat($result);

        self::assertCount(1, $data['coupons']);
    }

    public function test_search_by_name(): void
    {
        $result = $this->tool->execute(['search' => 'Free Shipping']);
        $data = $this->flat($result);

        self::assertCount(1, $data['coupons']);
    }

    public function test_columns_wildcard_returns_all(): void
    {
        $result = $this->tool->execute(['columns' => ['*']]);
        $data = $this->flat($result);

        self::assertContains('quantity', $data['columns_returned']);
        self::assertContains('minimum_amount', $data['columns_returned']);
        self::assertContains('description', $data['columns_returned']);
    }

    public function test_columns_specific_subset(): void
    {
        $result = $this->tool->execute(['columns' => ['code', 'active']]);
        $data = $this->flat($result);

        self::assertContains('code', $data['columns_returned']);
        self::assertContains('active', $data['columns_returned']);
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

        self::assertCount(2, $data['coupons']);
    }

    public function test_columns_empty_array_returns_default(): void
    {
        $result = $this->tool->execute(['columns' => []]);
        $data = $this->flat($result);

        self::assertContains('id', $data['columns_returned']);
    }

    public function test_name_column_triggers_lang_join(): void
    {
        $result = $this->tool->execute(['columns' => ['name']]);
        $data = $this->flat($result);

        self::assertContains('name', $data['columns_returned']);
    }

    public function test_empty_result_when_no_match(): void
    {
        $result = $this->tool->execute(['search' => 'ZZZNOMATCH99999']);
        $data = $this->flat($result);

        self::assertSame([], $data['coupons']);
    }

    public function test_columns_and_total_metadata_present(): void
    {
        $result = $this->tool->execute([]);
        $data = $this->flat($result);

        self::assertArrayHasKey('columns_returned', $data);
        self::assertArrayHasKey('total', $data);
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
        $first = $this->rows($this->tool->execute(['limit' => 1]), 'coupons');
        $second = $this->rows($this->tool->execute(['limit' => 1, 'offset' => 1]), 'coupons');

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

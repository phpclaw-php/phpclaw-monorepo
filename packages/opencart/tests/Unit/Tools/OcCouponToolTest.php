<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tests\Unit\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\OpenCart\Tests\Helpers\OcDbTestCase;
use PhpClaw\OpenCart\Tools\OcCouponTool;

final class OcCouponToolTest extends OcDbTestCase
{
    private OcCouponTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $couponTable = $this->prefix.'coupon';
        $historyTable = $this->prefix.'coupon_history';

        $this->resetTables([
            "CREATE TABLE IF NOT EXISTS `{$couponTable}` (
                coupon_id     INT          NOT NULL AUTO_INCREMENT,
                name          VARCHAR(128) NOT NULL DEFAULT '',
                code          VARCHAR(20)  NOT NULL DEFAULT '',
                discount      DECIMAL(15,4) NOT NULL DEFAULT 0,
                type          CHAR(1)      NOT NULL DEFAULT 'F',
                total         DECIMAL(15,4) NOT NULL DEFAULT 0,
                date_start    DATE         NOT NULL DEFAULT '0000-00-00',
                date_end      DATE         NOT NULL DEFAULT '0000-00-00',
                uses_total    INT          NOT NULL DEFAULT 0,
                uses_customer INT          NOT NULL DEFAULT 0,
                status        TINYINT      NOT NULL DEFAULT 1,
                PRIMARY KEY (coupon_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$historyTable}` (
                coupon_history_id INT NOT NULL AUTO_INCREMENT,
                coupon_id         INT NOT NULL DEFAULT 0,
                order_id          INT NOT NULL DEFAULT 0,
                PRIMARY KEY (coupon_history_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ]);

        $this->seed("INSERT INTO `{$couponTable}` (coupon_id,name,code,discount,type,total,date_start,date_end,uses_total,uses_customer,status) VALUES (1,'Summer Sale','SUMMER20',20.00,'P',0,'2024-06-01','2024-08-31',100,1,1)");
        $this->seed("INSERT INTO `{$couponTable}` (coupon_id,name,code,discount,type,total,date_start,date_end,uses_total,uses_customer,status) VALUES (2,'Free Shipping','FREESHIP',0.00,'F',50,'2024-01-01','0000-00-00',0,0,1)");
        $this->seed("INSERT INTO `{$couponTable}` (coupon_id,name,code,discount,type,total,date_start,date_end,uses_total,uses_customer,status) VALUES (3,'Old Promo','OLD10',10.00,'P',0,'2023-01-01','2023-12-31',50,1,0)");

        $this->tool = new OcCouponTool($this->db, $this->prefix, true);
    }

    private function payload(string $json): array
    {
        $d = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($d['success']);

        return $d['data'];
    }

    public function test_name(): void
    {
        self::assertSame('oc_coupon', $this->tool->name());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        self::assertStringContainsString(
            'QUERY OpenCart coupons',
            $this->tool->description(),
        );
    }

    public function test_description_mentions_sensitive(): void
    {
        self::assertStringContainsString('SENSITIVE', $this->tool->description());
    }

    public function test_required_capability_is_access(): void
    {
        self::assertSame('access', $this->tool->requiredCapability());
    }

    public function test_forbidden_when_caller_may_not_use_module(): void
    {
        $tool = new OcCouponTool($this->db, $this->prefix, false);
        $d = json_decode($tool->execute([]), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($d['success']);
        self::assertSame('FORBIDDEN', $d['error']['code']);
    }

    public function test_schema_mode_returns_metadata(): void
    {
        $d = json_decode($this->tool->execute(['mode' => 'schema']), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($d['success']);
        self::assertSame('schema', $d['meta']['mode']);
        self::assertArrayHasKey('available_columns', $d['data']);
        self::assertArrayHasKey('filters', $d['data']);
        self::assertArrayHasKey('sensitive_columns', $d['data']);
        self::assertContains('code', $d['data']['sensitive_columns']);
    }

    public function test_schema_mode_works_without_pdo(): void
    {
        $tool = new OcCouponTool(null, $this->prefix, true);
        $d = json_decode($tool->execute(['mode' => 'schema']), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($d['success']);
        self::assertSame('schema', $d['meta']['mode']);
    }

    public function test_throws_when_pdo_null_on_list(): void
    {
        $tool = new OcCouponTool(null, $this->prefix, true);
        $this->expectException(ToolException::class);
        $tool->execute(['mode' => 'list']);
    }

    public function test_list_returns_all_coupons(): void
    {
        $payload = $this->payload($this->tool->execute(['mode' => 'list']));
        self::assertCount(3, $payload['coupons']);
    }

    public function test_default_mode_is_list(): void
    {
        $payload = $this->payload($this->tool->execute([]));
        self::assertArrayHasKey('coupons', $payload);
    }

    public function test_filter_by_status_active(): void
    {
        $payload = $this->payload($this->tool->execute(['status' => 1]));
        self::assertCount(2, $payload['coupons']);
    }

    public function test_filter_by_status_inactive(): void
    {
        $payload = $this->payload($this->tool->execute(['status' => 0]));
        self::assertCount(1, $payload['coupons']);
    }

    public function test_search_by_name(): void
    {
        $payload = $this->payload($this->tool->execute(['search' => 'Summer']));
        self::assertCount(1, $payload['coupons']);
    }

    public function test_search_by_code(): void
    {
        $payload = $this->payload($this->tool->execute(['search' => 'FREESHIP']));
        self::assertCount(1, $payload['coupons']);
    }

    public function test_columns_wildcard(): void
    {
        $d = json_decode($this->tool->execute(['columns' => ['*']]), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($d['success']);
        $returned = $d['meta']['columns_returned'] ?? [];
        self::assertContains('uses_total', $returned);
        self::assertContains('total', $returned);
    }

    public function test_columns_empty_returns_defaults(): void
    {
        $d = json_decode($this->tool->execute(['columns' => []]), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($d['success']);
        $returned = $d['meta']['columns_returned'] ?? [];
        self::assertNotContains('code', $returned);
        self::assertContains('discount', $returned);
    }

    public function test_code_is_returned_only_when_requested_by_name(): void
    {
        $d = json_decode($this->tool->execute(['columns' => ['id', 'code']]), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($d['success']);
        self::assertContains('code', $d['meta']['columns_returned'] ?? []);
    }

    public function test_limit_caps_results(): void
    {
        $payload = $this->payload($this->tool->execute(['limit' => 1]));
        self::assertCount(1, $payload['coupons']);
    }

    public function test_invalid_columns_filtered(): void
    {
        $d = json_decode($this->tool->execute(['columns' => ['id', 'bogus']]), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($d['success']);
        $returned = $d['meta']['columns_returned'] ?? [];
        self::assertContains('id', $returned);
        self::assertNotContains('bogus', $returned);
    }

    public function test_filter_expired_true_returns_only_expired(): void
    {
        $payload = $this->payload($this->tool->execute(['expired' => true]));
        self::assertCount(2, $payload['coupons']);
    }

    public function test_filter_expired_false_does_not_execute_date_clause(): void
    {
        $filtered = $this->payload($this->tool->execute(['expired' => false]));
        $unfiltered = $this->payload($this->tool->execute([]));

        self::assertSame(
            count($unfiltered['coupons'] ?? []),
            count($filtered['coupons'] ?? []),
            'expired=false must not narrow the result set',
        );
    }

    public function test_type_column_maps_p_to_percentage(): void
    {
        $d = json_decode($this->tool->execute(['columns' => ['id', 'type'], 'search' => 'Summer']), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($d['success']);
        $returned = $d['meta']['columns_returned'] ?? [];
        self::assertContains('type', $returned);
        $coupon = $d['data']['coupons'][0] ?? [];
        self::assertSame('percentage', $coupon['type'] ?? null);
    }

    public function test_type_column_maps_f_to_fixed(): void
    {
        $d = json_decode($this->tool->execute(['columns' => ['id', 'type'], 'search' => 'FREESHIP']), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($d['success']);
        $coupon = $d['data']['coupons'][0] ?? [];
        self::assertSame('fixed', $coupon['type'] ?? null);
    }

    public function test_aggregate_mode_executes_on_mysql(): void
    {
        $d = json_decode($this->tool->execute(['mode' => 'aggregate']), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($d['success']);
        self::assertSame('aggregate', $d['meta']['mode']);
        self::assertArrayHasKey('total_coupons', $d['data']['stats']);
        self::assertArrayHasKey('active_count', $d['data']['stats']);
        self::assertArrayHasKey('expired_count', $d['data']['stats']);
        self::assertSame(3, $d['data']['stats']['total_coupons']);
    }

    public function test_aggregate_throws_when_pdo_null(): void
    {
        $tool = new OcCouponTool(null, $this->prefix, true);
        $this->expectException(ToolException::class);
        $tool->execute(['mode' => 'aggregate']);
    }

    public function test_sort_order_accepted(): void
    {
        $extra = $this->payload($this->tool->execute(['sort_order' => 'asc']));
        $plain = $this->payload($this->tool->execute([]));

        self::assertSame(
            count($plain['coupons'] ?? []),
            count($extra['coupons'] ?? []),
            'unknown input key must not drop rows',
        );
    }

    public function test_output_truncation_when_rows_exceed_limit(): void
    {
        $longName = str_repeat('A', 300);
        $couponTable = $this->prefix.'coupon';

        for ($i = 10; $i <= 50; $i++) {
            $escaped = self::$sharedMysqli->real_escape_string($longName);
            $this->seed("INSERT INTO `{$couponTable}` (coupon_id,name,code,discount,type,total,date_start,date_end,uses_total,uses_customer,status) VALUES ($i,'$escaped','CODE$i',5.00,'P',0,'2025-01-01','2025-12-31',0,0,1)");
        }

        $d = json_decode($this->tool->execute(['columns' => ['*'], 'limit' => 100]), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($d['success']);
        self::assertTrue($d['meta']['truncated']);
    }

    public function test_meta_total_matches_fetched_row_count(): void
    {
        $d = json_decode($this->tool->execute([]), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($d['meta']['total'], count($d['data']['coupons']));
    }
}

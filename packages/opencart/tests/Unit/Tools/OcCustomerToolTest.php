<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tests\Unit\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\OpenCart\Tests\Helpers\OcDbTestCase;
use PhpClaw\OpenCart\Tools\OcCustomerTool;

final class OcCustomerToolTest extends OcDbTestCase
{
    private OcCustomerTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $customerTable = $this->prefix.'customer';
        $groupDescTable = $this->prefix.'customer_group_description';
        $orderTable = $this->prefix.'order';

        $this->resetTables([
            "CREATE TABLE IF NOT EXISTS `{$customerTable}` (
                customer_id       INT          NOT NULL AUTO_INCREMENT,
                firstname         VARCHAR(32)  NOT NULL DEFAULT '',
                lastname          VARCHAR(32)  NOT NULL DEFAULT '',
                email             VARCHAR(96)  NOT NULL DEFAULT '',
                telephone         VARCHAR(32)  NOT NULL DEFAULT '',
                customer_group_id INT          NOT NULL DEFAULT 1,
                status            TINYINT      NOT NULL DEFAULT 1,
                approved          TINYINT      NOT NULL DEFAULT 1,
                date_added        DATETIME     NOT NULL DEFAULT '2024-01-01 00:00:00',
                password          VARCHAR(255) NOT NULL DEFAULT 'secret',
                salt              VARCHAR(9)   NOT NULL DEFAULT 'xxxx',
                token             VARCHAR(255) NOT NULL DEFAULT '',
                code              VARCHAR(40)  NOT NULL DEFAULT '',
                custom_field      TEXT         NOT NULL,
                newsletter        TINYINT      NOT NULL DEFAULT 0,
                ip                VARCHAR(40)  NOT NULL DEFAULT '',
                PRIMARY KEY (customer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$groupDescTable}` (
                customer_group_id INT         NOT NULL DEFAULT 0,
                language_id       INT         NOT NULL DEFAULT 1,
                name              VARCHAR(32) NOT NULL DEFAULT ''
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$orderTable}` (
                order_id        INT           NOT NULL AUTO_INCREMENT,
                customer_id     INT           NOT NULL DEFAULT 0,
                order_status_id INT           NOT NULL DEFAULT 0,
                total           DECIMAL(15,4) NOT NULL DEFAULT 0,
                PRIMARY KEY (order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ]);

        $this->seed("INSERT INTO `{$customerTable}` (customer_id,firstname,lastname,email,telephone,customer_group_id,status,approved,date_added,password,salt,token,code,custom_field,newsletter,ip) VALUES (1,'Alice','Smith','alice@example.com','555-0001',1,1,1,'2024-01-01','h','s','','','{}',1,'127.0.0.1')");
        $this->seed("INSERT INTO `{$customerTable}` (customer_id,firstname,lastname,email,telephone,customer_group_id,status,approved,date_added,password,salt,token,code,custom_field,newsletter,ip) VALUES (2,'Bob','Jones','bob@example.com','555-0002',2,0,1,'2024-02-01','h','s','','','{}',0,'127.0.0.2')");
        $this->seed("INSERT INTO `{$customerTable}` (customer_id,firstname,lastname,email,telephone,customer_group_id,status,approved,date_added,password,salt,token,code,custom_field,newsletter,ip) VALUES (3,'Carol','Doe','carol@example.com','555-0003',1,1,1,'2024-03-01','h','s','','','{}',1,'127.0.0.3')");
        $this->seed("INSERT INTO `{$groupDescTable}` (customer_group_id,language_id,name) VALUES (1,1,'Default')");
        $this->seed("INSERT INTO `{$groupDescTable}` (customer_group_id,language_id,name) VALUES (2,1,'VIP')");
        $this->seed("INSERT INTO `{$orderTable}` (order_id,customer_id,order_status_id,total) VALUES (1,1,1,49.99)");
        $this->seed("INSERT INTO `{$orderTable}` (order_id,customer_id,order_status_id,total) VALUES (2,1,1,29.99)");

        $this->tool = new OcCustomerTool($this->db, $this->prefix, true);
    }

    private function payload(string $json): array
    {
        $d = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($d['success']);

        return $d['data'];
    }

    public function test_name(): void
    {
        self::assertSame('oc_customer', $this->tool->name());
    }

    public function test_never_returns_password(): void
    {
        $payload = $this->payload($this->tool->execute([]));
        self::assertCount(3, $payload['customers']);

        foreach ($payload['customers'] as $row) {
            self::assertArrayNotHasKey('password', $row);
            self::assertArrayNotHasKey('salt', $row);
            self::assertArrayNotHasKey('token', $row);
            self::assertArrayNotHasKey('code', $row);
            self::assertArrayNotHasKey('custom_field', $row);
        }
    }

    public function test_returns_all_customers_with_no_filters(): void
    {
        $payload = $this->payload($this->tool->execute([]));
        self::assertCount(3, $payload['customers']);
    }

    public function test_search_by_firstname(): void
    {
        $payload = $this->payload($this->tool->execute(['search' => 'Alice']));
        self::assertCount(1, $payload['customers']);
        self::assertSame('Alice', $payload['customers'][0]['firstname']);
    }

    public function test_search_by_email(): void
    {
        $payload = $this->payload($this->tool->execute(['search' => 'bob@example']));
        self::assertCount(1, $payload['customers']);
        self::assertSame('Bob', $payload['customers'][0]['firstname']);
    }

    public function test_filter_by_status_active(): void
    {
        $payload = $this->payload($this->tool->execute(['status' => 1]));
        self::assertCount(2, $payload['customers']);
    }

    public function test_filter_by_status_inactive(): void
    {
        $payload = $this->payload($this->tool->execute(['status' => 0]));
        self::assertCount(1, $payload['customers']);
        self::assertSame('Bob', $payload['customers'][0]['firstname']);
    }

    public function test_filter_by_customer_group_id(): void
    {
        $payload = $this->payload($this->tool->execute(['customer_group_id' => 2]));
        self::assertCount(1, $payload['customers']);
        self::assertSame(2, (int) $payload['customers'][0]['id']);
    }

    public function test_filter_by_newsletter_subscribed(): void
    {
        $payload = $this->payload($this->tool->execute(['newsletter' => 1]));
        self::assertCount(2, $payload['customers']);
    }

    public function test_filter_by_newsletter_unsubscribed(): void
    {
        $payload = $this->payload($this->tool->execute(['newsletter' => 0]));
        self::assertCount(1, $payload['customers']);
        self::assertSame('Bob', $payload['customers'][0]['firstname']);
    }

    public function test_filter_by_date_after(): void
    {
        $payload = $this->payload($this->tool->execute(['date_after' => '2024-02-01']));
        self::assertCount(2, $payload['customers']);
    }

    public function test_filter_by_date_before(): void
    {
        $payload = $this->payload($this->tool->execute(['date_before' => '2024-01-31']));
        self::assertCount(1, $payload['customers']);
        self::assertSame('Alice', $payload['customers'][0]['firstname']);
    }

    public function test_schema_mode_returns_schema(): void
    {
        $d = json_decode($this->tool->execute(['schema' => true]), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($d['success']);
        self::assertSame('schema', $d['meta']['mode']);
        self::assertArrayHasKey('available_columns', $d['data']);
        self::assertArrayHasKey('blocked_columns', $d['data']);
    }

    public function test_schema_mode_works_without_pdo(): void
    {
        $tool = new OcCustomerTool(null, $this->prefix, true);
        $d = json_decode($tool->execute(['schema' => true]), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($d['success']);
        self::assertArrayHasKey('available_columns', $d['data']);
    }

    public function test_aggregate_returns_totals(): void
    {
        $d = json_decode($this->tool->execute(['aggregate' => true]), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($d['success']);
        self::assertSame('aggregate', $d['meta']['mode']);
        self::assertSame(3, $d['data']['stats']['total']);
        self::assertArrayHasKey('active_count', $d['data']['stats']);
        self::assertArrayHasKey('inactive_count', $d['data']['stats']);
        self::assertArrayHasKey('newsletter_subscribers', $d['data']['stats']);
        self::assertArrayHasKey('by_group', $d['data']['stats']);
    }

    public function test_aggregate_active_inactive_counts(): void
    {
        $d = json_decode($this->tool->execute(['aggregate' => true]), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(2, $d['data']['stats']['active_count']);
        self::assertSame(1, $d['data']['stats']['inactive_count']);
    }

    public function test_aggregate_newsletter_count(): void
    {
        $d = json_decode($this->tool->execute(['aggregate' => true]), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(2, $d['data']['stats']['newsletter_subscribers']);
    }

    public function test_columns_wildcard_includes_sensitive(): void
    {
        $payload = $this->payload($this->tool->execute(['columns' => ['*']]));
        $row = $payload['customers'][0] ?? [];
        self::assertArrayHasKey('email', $row);
    }

    public function test_columns_empty_returns_defaults(): void
    {
        $payload = $this->payload($this->tool->execute(['columns' => []]));
        $returned = $payload['columns_returned'] ?? [];
        self::assertContains('id', $returned);
        self::assertNotContains('email', $returned);
    }

    public function test_throws_when_pdo_is_null(): void
    {
        $tool = new OcCustomerTool(null, $this->prefix, true);
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('no database connection');
        $tool->execute([]);
    }

    public function test_limit_caps_rows(): void
    {
        $payload = $this->payload($this->tool->execute(['limit' => 1]));
        self::assertCount(1, $payload['customers']);
    }

    public function test_forbidden_when_caller_may_not_use_module(): void
    {
        $tool = new OcCustomerTool($this->db, $this->prefix, false);
        $d = json_decode($tool->execute([]), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($d['success']);
        self::assertSame('FORBIDDEN', $d['error']['code']);
    }

    public function test_meta_total_matches_customer_count(): void
    {
        $d = json_decode($this->tool->execute([]), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($d['meta']['total'], count($d['data']['customers']));
    }
}

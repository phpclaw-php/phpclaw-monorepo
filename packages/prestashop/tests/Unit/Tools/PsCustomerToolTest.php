<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\PrestaShop\Tests\Helpers\ActsAsChatTierEmployee;
use PhpClaw\PrestaShop\Tests\Helpers\AssertsToolEnvelope;
use PhpClaw\PrestaShop\Tests\Helpers\PsDbTestCase;
use PhpClaw\PrestaShop\Tools\PsCustomerTool;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(PsCustomerTool::class)]
final class PsCustomerToolTest extends PsDbTestCase
{
    use ActsAsChatTierEmployee;
    use AssertsToolEnvelope;

    private PsCustomerTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actAsChatTierEmployee();

        $customerTable = $this->prefix.'customer';
        $groupLangTable = $this->prefix.'group_lang';
        $ordersTable = $this->prefix.'orders';

        $this->resetTables([
            "CREATE TABLE IF NOT EXISTS `{$customerTable}` (
                id_customer       INT          NOT NULL AUTO_INCREMENT,
                email             VARCHAR(255) NOT NULL DEFAULT '',
                firstname         VARCHAR(255) NOT NULL DEFAULT '',
                lastname          VARCHAR(255) NOT NULL DEFAULT '',
                id_default_group  INT          NOT NULL DEFAULT 3,
                active            TINYINT      NOT NULL DEFAULT 1,
                newsletter        TINYINT      NOT NULL DEFAULT 0,
                deleted           TINYINT      NOT NULL DEFAULT 0,
                company           VARCHAR(255) NOT NULL DEFAULT '',
                date_add          DATETIME     NOT NULL DEFAULT '2024-01-01 00:00:00',
                PRIMARY KEY (id_customer)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$groupLangTable}` (
                id_group INT         NOT NULL DEFAULT 0,
                id_lang  INT         NOT NULL DEFAULT 1,
                name     VARCHAR(32) NOT NULL DEFAULT ''
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$ordersTable}` (
                id_order            INT           NOT NULL AUTO_INCREMENT,
                id_customer         INT           NOT NULL DEFAULT 0,
                total_paid_tax_incl DECIMAL(20,6) NOT NULL DEFAULT 0,
                PRIMARY KEY (id_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ]);

        $this->seed("INSERT INTO `{$customerTable}` (id_customer,email,firstname,lastname,id_default_group,active,newsletter,deleted,company,date_add) VALUES (1,'alice@example.com','Alice','Smith',3,1,1,0,'','2024-01-01 00:00:00')");
        $this->seed("INSERT INTO `{$customerTable}` (id_customer,email,firstname,lastname,id_default_group,active,newsletter,deleted,company,date_add) VALUES (2,'bob@example.com','Bob','Jones',3,0,0,0,'','2024-01-02 00:00:00')");
        $this->seed("INSERT INTO `{$groupLangTable}` (id_group,id_lang,name) VALUES (3,1,'Customer')");
        $this->seed("INSERT INTO `{$ordersTable}` (id_order,id_customer,total_paid_tax_incl) VALUES (1,1,99.99)");

        $this->tool = new PsCustomerTool($this->db, $this->prefix);
    }

    protected function tearDown(): void
    {
        $this->stopActingAsEmployee();

        parent::tearDown();
    }

    public function test_name_returns_ps_customer(): void
    {
        self::assertSame('ps_customer', $this->tool->name());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        self::assertStringContainsString(
            'PrestaShop customers with order histo',
            $this->tool->description(),
        );
    }

    public function test_execute_returns_two_customers(): void
    {
        $result = $this->tool->execute([]);
        $data = $this->flat($result);

        self::assertArrayHasKey('customers', $data);
        self::assertCount(2, $data['customers']);
    }

    public function test_execute_filters_active(): void
    {
        $result = $this->tool->execute(['active' => 1]);
        $data = $this->flat($result);

        self::assertArrayHasKey('customers', $data);
        self::assertCount(1, $data['customers']);
        self::assertEquals(1, $data['customers'][0]['active']);
    }

    public function test_execute_filters_inactive(): void
    {
        $result = $this->tool->execute(['active' => 0]);
        $data = $this->flat($result);

        self::assertCount(1, $data['customers']);
    }

    public function test_execute_filters_by_search(): void
    {
        $result = $this->tool->execute(['search' => 'Alice']);
        $data = $this->flat($result);

        self::assertArrayHasKey('customers', $data);
        self::assertCount(1, $data['customers']);
    }

    public function test_execute_filters_by_newsletter(): void
    {
        $result = $this->tool->execute(['newsletter' => 1]);
        $data = $this->flat($result);

        self::assertCount(1, $data['customers']);
    }

    public function test_execute_filters_by_group_id(): void
    {
        $result = $this->tool->execute(['group_id' => 3]);
        $data = $this->flat($result);

        self::assertCount(2, $data['customers']);
    }

    public function test_execute_filters_by_date_after(): void
    {
        $result = $this->tool->execute(['date_after' => '2024-01-02']);
        $data = $this->flat($result);

        self::assertCount(1, $data['customers']);
    }

    public function test_execute_filters_by_date_before(): void
    {
        $result = $this->tool->execute(['date_before' => '2024-01-01']);
        $data = $this->flat($result);

        self::assertCount(1, $data['customers']);
    }

    public function test_execute_throws_on_null_db(): void
    {
        $tool = new PsCustomerTool(null, $this->prefix);

        $this->expectException(ToolException::class);
        $tool->execute([]);
    }

    public function test_schema_mode_does_not_require_db(): void
    {
        $tool = new PsCustomerTool(null, $this->prefix);

        $data = $this->flat($tool->execute(['schema' => true]));

        self::assertSame('schema', $data['mode']);
        self::assertSame(
            (new \ReflectionClassConstant(PsCustomerTool::class, 'AVAILABLE_COLUMNS'))->getValue(),
            $data['available_columns'],
        );
        self::assertContains('newsletter', $data['filter_capabilities']);
    }

    public function test_aggregate_mode_returns_stats(): void
    {
        $result = $this->tool->execute(['aggregate' => true]);
        $data = $this->flat($result);

        self::assertSame('aggregate', $data['mode']);
        self::assertArrayHasKey('stats', $data);
        self::assertArrayHasKey('total', $data['stats']);
        self::assertArrayHasKey('active_count', $data['stats']);
        self::assertArrayHasKey('inactive_count', $data['stats']);
        self::assertArrayHasKey('newsletter_subscribers', $data['stats']);
        self::assertSame(2, $data['stats']['total']);
        self::assertSame(1, $data['stats']['active_count']);
        self::assertSame(1, $data['stats']['newsletter_subscribers']);
    }

    public function test_aggregate_with_active_filter(): void
    {
        $result = $this->tool->execute(['aggregate' => true, 'active' => 1]);
        $data = $this->flat($result);

        self::assertSame(1, $data['stats']['total']);
    }

    public function test_columns_wildcard_returns_all_columns(): void
    {
        $result = $this->tool->execute(['columns' => ['*']]);
        $data = $this->flat($result);

        self::assertContains('email', $data['columns_returned']);
        self::assertContains('total_spent', $data['columns_returned']);
    }

    public function test_columns_specific_subset(): void
    {
        $result = $this->tool->execute(['columns' => ['firstname', 'email']]);
        $data = $this->flat($result);

        self::assertArrayHasKey('firstname', $data['customers'][0]);
        self::assertArrayHasKey('email', $data['customers'][0]);
        self::assertArrayNotHasKey('newsletter', $data['customers'][0]);
    }

    public function test_columns_blocked_filtered_out(): void
    {
        $result = $this->tool->execute(['columns' => ['passwd', 'firstname']]);
        $data = $this->flat($result);

        self::assertArrayHasKey('firstname', $data['customers'][0]);
        self::assertArrayNotHasKey('passwd', $data['customers'][0]);
    }

    public function test_columns_non_array_falls_back_to_defaults(): void
    {
        $result = $this->tool->execute(['columns' => 'firstname']);
        $data = $this->flat($result);

        self::assertContains('id', $data['columns_returned']);
    }

    public function test_empty_result_returns_empty_customers_array(): void
    {
        $result = $this->tool->execute(['search' => 'NoSuchCustomer99999']);
        $data = $this->flat($result);

        self::assertSame([], $data['customers']);
    }

    public function test_limit_parameter_applied(): void
    {
        $result = $this->tool->execute(['limit' => 1]);
        $data = $this->flat($result);

        self::assertCount(1, $data['customers']);
    }

    public function test_customers_have_id_field(): void
    {
        $data = $this->flat($this->tool->execute([]));

        self::assertSame([2, 1], array_column($data['customers'], 'id'));
    }

    public function test_columns_returned_metadata_present(): void
    {
        $result = $this->tool->execute([]);
        $data = $this->flat($result);

        self::assertArrayHasKey('columns_returned', $data);
        self::assertIsArray($data['columns_returned']);
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
        $first = $this->rows($this->tool->execute(['limit' => 1]), 'customers');
        $second = $this->rows($this->tool->execute(['limit' => 1, 'offset' => 1]), 'customers');

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

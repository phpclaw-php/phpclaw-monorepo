<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\PrestaShop\Tests\Helpers\ActsAsChatTierEmployee;
use PhpClaw\PrestaShop\Tests\Helpers\AssertsToolEnvelope;
use PhpClaw\PrestaShop\Tests\Helpers\PsDbTestCase;
use PhpClaw\PrestaShop\Tools\PsEmployeeTool;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(PsEmployeeTool::class)]
final class PsEmployeeToolTest extends PsDbTestCase
{
    use ActsAsChatTierEmployee;
    use AssertsToolEnvelope;

    private PsEmployeeTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actAsChatTierEmployee();

        $employeeTable = $this->prefix.'employee';
        $profileLangTable = $this->prefix.'profile_lang';

        $this->resetTables([
            "CREATE TABLE IF NOT EXISTS `{$employeeTable}` (
                id_employee          INT          NOT NULL AUTO_INCREMENT,
                id_profile           INT          NOT NULL DEFAULT 1,
                firstname            VARCHAR(255) NOT NULL DEFAULT '',
                lastname             VARCHAR(255) NOT NULL DEFAULT '',
                email                VARCHAR(255) NOT NULL DEFAULT '',
                active               TINYINT      NOT NULL DEFAULT 1,
                last_connection_date DATETIME     NOT NULL DEFAULT '2024-01-01 00:00:00',
                date_add             DATETIME     NOT NULL DEFAULT '2024-01-01 00:00:00',
                PRIMARY KEY (id_employee)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$profileLangTable}` (
                id_profile INT         NOT NULL DEFAULT 0,
                id_lang    INT         NOT NULL DEFAULT 1,
                name       VARCHAR(64) NOT NULL DEFAULT ''
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ]);

        $this->seed("INSERT INTO `{$employeeTable}` (id_employee,id_profile,firstname,lastname,email,active,last_connection_date,date_add) VALUES (1,1,'Admin','User','admin@shop.com',1,'2024-01-15 10:00:00','2024-01-01 00:00:00')");
        $this->seed("INSERT INTO `{$employeeTable}` (id_employee,id_profile,firstname,lastname,email,active,last_connection_date,date_add) VALUES (2,2,'Logistics','Manager','logistics@shop.com',0,'2024-01-10 09:00:00','2024-01-02 00:00:00')");
        $this->seed("INSERT INTO `{$profileLangTable}` (id_profile,id_lang,name) VALUES (1,1,'SuperAdmin')");
        $this->seed("INSERT INTO `{$profileLangTable}` (id_profile,id_lang,name) VALUES (2,1,'Logistician')");

        $this->tool = new PsEmployeeTool($this->db, $this->prefix);
    }

    protected function tearDown(): void
    {
        $this->stopActingAsEmployee();

        parent::tearDown();
    }

    public function test_name_returns_ps_employee(): void
    {
        self::assertSame('ps_employee', $this->tool->name());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        self::assertStringContainsString(
            'PrestaShop back-office employees',
            $this->tool->description(),
        );
    }

    public function test_execute_returns_two_employees(): void
    {
        $data = $this->flat($this->tool->execute([]));

        self::assertArrayHasKey('employees', $data);
        self::assertCount(2, $data['employees']);
    }

    public function test_execute_throws_on_null_db(): void
    {
        $tool = new PsEmployeeTool(null, $this->prefix);

        $this->expectException(ToolException::class);
        $tool->execute([]);
    }

    public function test_schema_mode_returns_schema(): void
    {
        $result = $this->tool->execute(['schema' => true]);
        $data = $this->flat($result);

        self::assertSame('schema', $data['mode']);
        self::assertArrayHasKey('available_columns', $data);
        self::assertArrayHasKey('blocked_columns', $data);
    }

    public function test_schema_mode_does_not_require_db(): void
    {
        $tool = new PsEmployeeTool(null, $this->prefix);

        $data = $this->flat($tool->execute(['schema' => true]));

        self::assertSame('schema', $data['mode']);
        self::assertSame(
            (new \ReflectionClassConstant(PsEmployeeTool::class, 'AVAILABLE_COLUMNS'))->getValue(),
            $data['available_columns'],
        );
    }

    public function test_aggregate_mode_returns_stats(): void
    {
        $result = $this->tool->execute(['aggregate' => true]);
        $data = $this->flat($result);

        self::assertSame('aggregate', $data['mode']);
        self::assertArrayHasKey('stats', $data);
        self::assertArrayHasKey('by_profile', $data);
        self::assertSame(2, (int) $data['stats']['total']);
    }

    public function test_aggregate_with_active_filter(): void
    {
        $result = $this->tool->execute(['aggregate' => true, 'active' => true]);
        $data = $this->flat($result);

        self::assertSame(1, (int) $data['stats']['total']);
    }

    public function test_filter_active_true(): void
    {
        $result = $this->tool->execute(['active' => true]);
        $data = $this->flat($result);

        self::assertCount(1, $data['employees']);
        self::assertTrue($data['employees'][0]['active']);
    }

    public function test_filter_active_false(): void
    {
        $result = $this->tool->execute(['active' => false]);
        $data = $this->flat($result);

        self::assertCount(1, $data['employees']);
        self::assertFalse($data['employees'][0]['active']);
    }

    public function test_filter_by_profile_id(): void
    {
        $result = $this->tool->execute(['profile_id' => 1]);
        $data = $this->flat($result);

        self::assertCount(1, $data['employees']);
    }

    public function test_filter_by_search(): void
    {
        $result = $this->tool->execute(['search' => 'Admin']);
        $data = $this->flat($result);

        self::assertCount(1, $data['employees']);
    }

    public function test_email_is_masked_in_output(): void
    {
        $data = $this->flat($this->tool->execute(['columns' => ['*']]));

        self::assertSame(
            ['log***@shop.com', 'adm***@shop.com'],
            array_column($data['employees'], 'email'),
        );
    }

    public function test_columns_wildcard_returns_all(): void
    {
        $result = $this->tool->execute(['columns' => ['*']]);
        $data = $this->flat($result);

        self::assertContains('date_add', $data['columns_returned']);
        self::assertContains('email', $data['columns_returned']);
    }

    public function test_columns_specific_subset(): void
    {
        $result = $this->tool->execute(['columns' => ['firstname', 'lastname']]);
        $data = $this->flat($result);

        self::assertArrayHasKey('id', $data['employees'][0]);
        self::assertArrayHasKey('firstname', $data['employees'][0]);
    }

    public function test_columns_invalid_falls_back_to_defaults(): void
    {
        $result = $this->tool->execute(['columns' => []]);
        $data = $this->flat($result);

        self::assertContains('id', $data['columns_returned']);
    }

    public function test_order_dir_desc(): void
    {
        $data = $this->flat($this->tool->execute(['order_dir' => 'DESC']));

        self::assertSame(
            ['User', 'Manager'],
            array_column($data['employees'], 'lastname'),
        );
    }

    public function test_order_by_invalid_falls_back(): void
    {
        $data = $this->flat($this->tool->execute(['order_by' => 'bad_col']));

        self::assertSame(
            ['Manager', 'User'],
            array_column($data['employees'], 'lastname'),
        );
    }

    public function test_offset_and_limit(): void
    {
        $result = $this->tool->execute(['limit' => 1, 'offset' => 0]);
        $data = $this->flat($result);

        self::assertCount(1, $data['employees']);
    }

    public function test_employees_have_active_boolean(): void
    {
        $data = $this->flat($this->tool->execute([]));

        self::assertSame(
            ['Manager' => false, 'User' => true],
            array_column($data['employees'], 'active', 'lastname'),
        );
    }

    public function test_empty_result_when_no_match(): void
    {
        $result = $this->tool->execute(['search' => 'NoSuchEmployee99999']);
        $data = $this->flat($result);

        self::assertSame([], $data['employees']);
    }

    public function test_count_metadata_present(): void
    {
        $result = $this->tool->execute([]);
        $data = $this->flat($result);

        self::assertArrayHasKey('count', $data);
        self::assertSame(2, $data['count']);
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
        $first = $this->rows($this->tool->execute(['limit' => 1]), 'employees');
        $second = $this->rows($this->tool->execute(['limit' => 1, 'offset' => 1]), 'employees');

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

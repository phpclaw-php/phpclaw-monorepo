<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\PrestaShop\Tests\Helpers\ActsAsChatTierEmployee;
use PhpClaw\PrestaShop\Tests\Helpers\AssertsToolEnvelope;
use PhpClaw\PrestaShop\Tests\Helpers\PsDbTestCase;
use PhpClaw\PrestaShop\Tools\PsModuleTool;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(PsModuleTool::class)]
final class PsModuleToolTest extends PsDbTestCase
{
    use ActsAsChatTierEmployee;
    use AssertsToolEnvelope;

    private PsModuleTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actAsChatTierEmployee();

        $moduleTable = $this->prefix.'module';

        $this->resetTables([
            "CREATE TABLE IF NOT EXISTS `{$moduleTable}` (
                id_module   INT          NOT NULL AUTO_INCREMENT,
                name        VARCHAR(64)  NOT NULL DEFAULT '',
                version     VARCHAR(8)   NOT NULL DEFAULT '1.0.0',
                active      TINYINT      NOT NULL DEFAULT 1,
                PRIMARY KEY (id_module)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ]);

        $this->seed("INSERT INTO `{$moduleTable}` (id_module,name,version,active) VALUES (1,'phpclaw','1.0.0',1)");
        $this->seed("INSERT INTO `{$moduleTable}` (id_module,name,version,active) VALUES (2,'blockcart','2.3.0',0)");
        $this->seed("INSERT INTO `{$moduleTable}` (id_module,name,version,active) VALUES (3,'statsvisits','2.0.1',1)");

        $this->tool = new PsModuleTool($this->db, $this->prefix);
    }

    protected function tearDown(): void
    {
        $this->stopActingAsEmployee();

        parent::tearDown();
    }

    public function test_name_returns_ps_module(): void
    {
        self::assertSame('ps_module', $this->tool->name());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        self::assertStringContainsString(
            'installed PrestaShop modules',
            $this->tool->description(),
        );
    }

    public function test_input_schema_has_properties(): void
    {
        self::assertArrayHasKey('properties', $this->tool->inputSchema());
    }

    public function test_execute_returns_all_modules_by_default(): void
    {
        $result = $this->tool->execute([]);
        $data = $this->flat($result);

        self::assertArrayHasKey('modules', $data);
        self::assertCount(3, $data['modules']);
    }

    public function test_execute_filters_active_modules(): void
    {
        $data = $this->flat($this->tool->execute(['active' => true]));

        self::assertSame(['phpclaw', 'statsvisits'], array_column($data['modules'], 'name'));
    }

    public function test_execute_filters_inactive_modules(): void
    {
        $result = $this->tool->execute(['active' => false]);
        $data = $this->flat($result);

        self::assertCount(1, $data['modules']);
        self::assertFalse($data['modules'][0]['active']);
    }

    public function test_execute_filters_by_search(): void
    {
        $result = $this->tool->execute(['search' => 'phpclaw']);
        $data = $this->flat($result);

        self::assertArrayHasKey('modules', $data);
        self::assertCount(1, $data['modules']);
        self::assertSame('phpclaw', $data['modules'][0]['name']);
    }

    public function test_execute_throws_on_null_db(): void
    {
        $tool = new PsModuleTool(null, $this->prefix);

        $this->expectException(ToolException::class);
        $tool->execute([]);
    }

    public function test_schema_mode_does_not_require_db(): void
    {
        $tool = new PsModuleTool(null, $this->prefix);

        $data = $this->flat($tool->execute(['schema' => true]));

        self::assertSame('schema', $data['mode']);
        self::assertSame(
            (new \ReflectionClassConstant(PsModuleTool::class, 'AVAILABLE_COLUMNS'))->getValue(),
            $data['available_columns'],
        );
    }

    public function test_aggregate_mode_returns_stats(): void
    {
        $result = $this->tool->execute(['aggregate' => true]);
        $data = $this->flat($result);

        self::assertSame('aggregate', $data['mode']);
        self::assertArrayHasKey('stats', $data);
        self::assertSame(3, (int) $data['stats']['total']);
        self::assertSame(2, (int) $data['stats']['active']);
        self::assertSame(1, (int) $data['stats']['inactive']);
    }

    public function test_an_unknown_filter_is_refused_rather_than_silently_ignored(): void
    {
        $result = $this->tool->execute(['author' => 'PrestaShop']);

        self::assertSame('UNKNOWN_ARGUMENT', $this->errorCode($result));
        self::assertContains(
            'search',
            $this->envelope($result)['error']['accepted_arguments'],
            'The refusal must tell the model what it may send instead.',
        );
    }

    public function test_columns_wildcard_returns_all(): void
    {
        $result = $this->tool->execute(['columns' => ['*']]);
        $data = $this->flat($result);

        self::assertSame(['id', 'name', 'version', 'active'], $data['columns_returned']);
    }

    public function test_columns_specific_subset(): void
    {
        $result = $this->tool->execute(['columns' => ['name', 'version']]);
        $data = $this->flat($result);

        self::assertArrayHasKey('id', $data['modules'][0]);
        self::assertArrayHasKey('name', $data['modules'][0]);
        self::assertArrayHasKey('version', $data['modules'][0]);
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
            ['statsvisits', 'phpclaw', 'blockcart'],
            array_column($data['modules'], 'name'),
        );
    }

    public function test_order_by_invalid_falls_back(): void
    {
        $data = $this->flat($this->tool->execute(['order_by' => 'bad_col']));

        self::assertSame(
            ['blockcart', 'phpclaw', 'statsvisits'],
            array_column($data['modules'], 'name'),
        );
    }

    public function test_limit_applied(): void
    {
        $result = $this->tool->execute(['limit' => 1]);
        $data = $this->flat($result);

        self::assertCount(1, $data['modules']);
    }

    public function test_offset_applied(): void
    {
        $result = $this->tool->execute(['offset' => 2]);
        $data = $this->flat($result);

        self::assertCount(1, $data['modules']);
    }

    public function test_active_boolean_in_results(): void
    {
        $data = $this->flat($this->tool->execute([]));

        self::assertSame(
            ['blockcart' => false, 'phpclaw' => true, 'statsvisits' => true],
            array_column($data['modules'], 'active', 'name'),
        );
    }

    public function test_empty_result_when_no_match(): void
    {
        $result = $this->tool->execute(['search' => 'ZZZNOMATCH99999']);
        $data = $this->flat($result);

        self::assertSame([], $data['modules']);
    }

    public function test_count_metadata_present(): void
    {
        $result = $this->tool->execute([]);
        $data = $this->flat($result);

        self::assertArrayHasKey('count', $data);
        self::assertSame(3, $data['count']);
    }

    public function test_aggregate_with_active_filter(): void
    {
        $result = $this->tool->execute(['aggregate' => true, 'active' => true]);
        $data = $this->flat($result);

        self::assertSame(2, (int) $data['stats']['total']);
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
        $first = $this->rows($this->tool->execute(['limit' => 1]), 'modules');
        $second = $this->rows($this->tool->execute(['limit' => 1, 'offset' => 1]), 'modules');

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

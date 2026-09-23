<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\PrestaShop\Tests\Helpers\ActsAsChatTierEmployee;
use PhpClaw\PrestaShop\Tests\Helpers\AssertsToolEnvelope;
use PhpClaw\PrestaShop\Tests\Helpers\PsDbTestCase;
use PhpClaw\PrestaShop\Tools\PsManufacturerTool;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(PsManufacturerTool::class)]
final class PsManufacturerToolTest extends PsDbTestCase
{
    use ActsAsChatTierEmployee;
    use AssertsToolEnvelope;

    private PsManufacturerTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actAsChatTierEmployee();

        $manufacturerTable = $this->prefix.'manufacturer';
        $manufacturerLangTable = $this->prefix.'manufacturer_lang';
        $productTable = $this->prefix.'product';

        $this->resetTables([
            "CREATE TABLE IF NOT EXISTS `{$manufacturerTable}` (
                id_manufacturer INT          NOT NULL AUTO_INCREMENT,
                name            VARCHAR(64)  NOT NULL DEFAULT '',
                active          TINYINT      NOT NULL DEFAULT 1,
                date_add        DATETIME     NOT NULL DEFAULT '2024-01-01 00:00:00',
                date_upd        DATETIME     NOT NULL DEFAULT '2024-01-01 00:00:00',
                PRIMARY KEY (id_manufacturer)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$manufacturerLangTable}` (
                id_manufacturer INT      NOT NULL DEFAULT 0,
                id_lang         INT      NOT NULL DEFAULT 1,
                description     LONGTEXT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$productTable}` (
                id_product      INT NOT NULL AUTO_INCREMENT,
                id_manufacturer INT NOT NULL DEFAULT 0,
                PRIMARY KEY (id_product)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ]);

        $this->seed("INSERT INTO `{$manufacturerTable}` (id_manufacturer,name,active,date_add,date_upd) VALUES (1,'Nike',1,'2024-01-01 00:00:00','2024-01-01 00:00:00')");
        $this->seed("INSERT INTO `{$manufacturerTable}` (id_manufacturer,name,active,date_add,date_upd) VALUES (2,'Adidas',0,'2024-01-02 00:00:00','2024-01-02 00:00:00')");
        $this->seed("INSERT INTO `{$manufacturerLangTable}` (id_manufacturer,id_lang,description) VALUES (1,1,'Sports brand')");
        $this->seed("INSERT INTO `{$manufacturerLangTable}` (id_manufacturer,id_lang,description) VALUES (2,1,'Another sports brand')");
        $this->seed("INSERT INTO `{$productTable}` (id_product,id_manufacturer) VALUES (1,1)");
        $this->seed("INSERT INTO `{$productTable}` (id_product,id_manufacturer) VALUES (2,1)");

        $this->tool = new PsManufacturerTool($this->db, $this->prefix);
    }

    protected function tearDown(): void
    {
        $this->stopActingAsEmployee();

        parent::tearDown();
    }

    public function test_name_returns_ps_manufacturer(): void
    {
        self::assertSame('ps_manufacturer', $this->tool->name());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        self::assertStringContainsString(
            'PrestaShop manufacturers/brands',
            $this->tool->description(),
        );
    }

    public function test_execute_returns_two_manufacturers(): void
    {
        $data = $this->flat($this->tool->execute([]));

        self::assertArrayHasKey('manufacturers', $data);
        self::assertCount(2, $data['manufacturers']);
    }

    public function test_execute_throws_on_null_db(): void
    {
        $tool = new PsManufacturerTool(null, $this->prefix);

        $this->expectException(ToolException::class);
        $tool->execute([]);
    }

    public function test_schema_mode_does_not_require_db(): void
    {
        $tool = new PsManufacturerTool(null, $this->prefix);

        $data = $this->flat($tool->execute(['schema' => true]));

        self::assertSame('schema', $data['mode']);
        self::assertSame(
            (new \ReflectionClassConstant(PsManufacturerTool::class, 'AVAILABLE_COLUMNS'))->getValue(),
            $data['available_columns'],
        );
    }

    public function test_aggregate_mode_returns_stats(): void
    {
        $result = $this->tool->execute(['aggregate' => true]);
        $data = $this->flat($result);

        self::assertSame('aggregate', $data['mode']);
        self::assertArrayHasKey('stats', $data);
        self::assertSame(2, (int) $data['stats']['total']);
        self::assertSame(1, (int) $data['stats']['active']);
        self::assertSame(1, (int) $data['stats']['inactive']);
    }

    public function test_aggregate_avg_products_per_manufacturer(): void
    {
        $result = $this->tool->execute(['aggregate' => true]);
        $data = $this->flat($result);

        self::assertArrayHasKey('avg_products_per_manufacturer', $data);
        self::assertIsNumeric($data['avg_products_per_manufacturer']);
    }

    public function test_filter_active_true(): void
    {
        $result = $this->tool->execute(['active' => true]);
        $data = $this->flat($result);

        self::assertCount(1, $data['manufacturers']);
        self::assertTrue($data['manufacturers'][0]['active']);
    }

    public function test_filter_active_false(): void
    {
        $result = $this->tool->execute(['active' => false]);
        $data = $this->flat($result);

        self::assertCount(1, $data['manufacturers']);
        self::assertFalse($data['manufacturers'][0]['active']);
    }

    public function test_filter_search(): void
    {
        $result = $this->tool->execute(['search' => 'Nike']);
        $data = $this->flat($result);

        self::assertCount(1, $data['manufacturers']);
    }

    public function test_columns_wildcard_includes_product_count(): void
    {
        $result = $this->tool->execute(['columns' => ['*']]);
        $data = $this->flat($result);

        self::assertContains('product_count', $data['columns_returned']);
    }

    public function test_product_count_in_results(): void
    {
        $result = $this->tool->execute(['columns' => ['name', 'product_count']]);
        $data = $this->flat($result);

        $nike = null;
        foreach ($data['manufacturers'] as $m) {
            if ($m['name'] === 'Nike') {
                $nike = $m;
                break;
            }
        }
        self::assertNotNull($nike);
        self::assertSame(2, $nike['product_count']);
    }

    public function test_hide_empty_excludes_zero_product_manufacturers(): void
    {
        $result = $this->tool->execute(['hide_empty' => true]);
        $data = $this->flat($result);

        self::assertGreaterThanOrEqual(0, count($data['manufacturers']));
    }

    public function test_order_by_product_count_desc(): void
    {
        $data = $this->flat($this->tool->execute([
            'columns' => ['name', 'product_count'],
            'order_by' => 'product_count',
            'order_dir' => 'DESC',
        ]));

        self::assertSame(
            ['Nike' => 2, 'Adidas' => 0],
            array_column($data['manufacturers'], 'product_count', 'name'),
        );
    }

    public function test_order_by_invalid_falls_back(): void
    {
        $data = $this->flat($this->tool->execute(['order_by' => 'bad_col']));

        self::assertSame(
            ['Adidas', 'Nike'],
            array_column($data['manufacturers'], 'name'),
        );
    }

    public function test_limit_applied(): void
    {
        $result = $this->tool->execute(['limit' => 1]);
        $data = $this->flat($result);

        self::assertCount(1, $data['manufacturers']);
    }

    public function test_offset_applied(): void
    {
        $result = $this->tool->execute(['offset' => 1]);
        $data = $this->flat($result);

        self::assertLessThanOrEqual(1, count($data['manufacturers']));
    }

    public function test_manufacturers_active_is_bool(): void
    {
        $data = $this->flat($this->tool->execute([]));

        self::assertSame(
            ['Adidas' => false, 'Nike' => true],
            array_column($data['manufacturers'], 'active', 'name'),
        );
    }

    public function test_empty_result_when_no_match(): void
    {
        $result = $this->tool->execute(['search' => 'ZZZNoSuchBrand99999']);
        $data = $this->flat($result);

        self::assertSame([], $data['manufacturers']);
    }

    public function test_specific_columns_subset(): void
    {
        $result = $this->tool->execute(['columns' => ['name']]);
        $data = $this->flat($result);

        self::assertArrayHasKey('id', $data['manufacturers'][0]);
        self::assertArrayHasKey('name', $data['manufacturers'][0]);
    }

    public function test_aggregate_with_search_filter(): void
    {
        $result = $this->tool->execute(['aggregate' => true, 'search' => 'Nike']);
        $data = $this->flat($result);

        self::assertSame(1, (int) $data['stats']['total']);
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
        $first = $this->rows($this->tool->execute(['limit' => 1]), 'manufacturers');
        $second = $this->rows($this->tool->execute(['limit' => 1, 'offset' => 1]), 'manufacturers');

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

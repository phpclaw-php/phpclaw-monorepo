<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tests\Unit\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\OpenCart\Tests\Helpers\OcDbTestCase;
use PhpClaw\OpenCart\Tools\OcShippingTool;

final class OcShippingToolTest extends OcDbTestCase
{
    private OcShippingTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $extTable = $this->prefix.'extension';
        $settingTable = $this->prefix.'setting';
        $geoTable = $this->prefix.'geo_zone';

        $this->resetTables([
            "CREATE TABLE IF NOT EXISTS `{$extTable}` (
                extension_id INT         NOT NULL AUTO_INCREMENT,
                type         VARCHAR(32) NOT NULL DEFAULT '',
                extension    VARCHAR(64) NOT NULL DEFAULT '',
                code         VARCHAR(64) NOT NULL DEFAULT '',
                PRIMARY KEY (extension_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$settingTable}` (
                setting_id INT         NOT NULL AUTO_INCREMENT,
                store_id   INT         NOT NULL DEFAULT 0,
                code       VARCHAR(64) NOT NULL DEFAULT '',
                `key`      VARCHAR(64) NOT NULL DEFAULT '',
                value      LONGTEXT    NOT NULL,
                serialized TINYINT     NOT NULL DEFAULT 0,
                PRIMARY KEY (setting_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$geoTable}` (
                geo_zone_id INT         NOT NULL AUTO_INCREMENT,
                name        VARCHAR(64) NOT NULL DEFAULT '',
                PRIMARY KEY (geo_zone_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ]);

        $this->seed("INSERT INTO `{$extTable}` (extension_id,type,extension,code) VALUES (1,'shipping','flat','flat')");
        $this->seed("INSERT INTO `{$extTable}` (extension_id,type,extension,code) VALUES (2,'shipping','free','free')");
        $this->seed("INSERT INTO `{$geoTable}` (geo_zone_id,name) VALUES (1,'World')");
        $this->seed("INSERT INTO `{$settingTable}` (setting_id,store_id,code,`key`,value,serialized) VALUES (1,0,'shipping_flat','shipping_flat_status','1',0)");
        $this->seed("INSERT INTO `{$settingTable}` (setting_id,store_id,code,`key`,value,serialized) VALUES (2,0,'shipping_flat','shipping_flat_cost','5.00',0)");
        $this->seed("INSERT INTO `{$settingTable}` (setting_id,store_id,code,`key`,value,serialized) VALUES (3,0,'shipping_flat','shipping_flat_geo_zone_id','1',0)");
        $this->seed("INSERT INTO `{$settingTable}` (setting_id,store_id,code,`key`,value,serialized) VALUES (4,0,'shipping_free','shipping_free_status','0',0)");
        $this->seed("INSERT INTO `{$settingTable}` (setting_id,store_id,code,`key`,value,serialized) VALUES (5,0,'shipping_free','shipping_free_cost','0.00',0)");

        $this->tool = new OcShippingTool($this->db, $this->prefix, true);
    }

    private function payload(string $json): array
    {
        $d = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($d['success']);

        return $d['data'];
    }

    public function test_name(): void
    {
        self::assertSame('oc_shipping', $this->tool->name());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        self::assertStringContainsString(
            'OpenCart shipping methods',
            $this->tool->description(),
        );
    }

    public function test_forbidden_when_caller_may_not_use_module(): void
    {
        $tool = new OcShippingTool($this->db, $this->prefix, false);
        $d = json_decode($tool->execute([]), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($d['success']);
        self::assertSame('FORBIDDEN', $d['error']['code']);
    }

    public function test_throws_when_pdo_is_null(): void
    {
        $tool = new OcShippingTool(null, $this->prefix, true);
        $this->expectException(ToolException::class);
        $tool->execute([]);
    }

    public function test_schema_works_without_db(): void
    {
        $tool = new OcShippingTool(null, $this->prefix, true);
        $d = json_decode($tool->execute(['schema' => true]), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($d['success']);
        self::assertSame('schema', $d['meta']['mode']);
        self::assertArrayHasKey('available_columns', $d['data']);
    }

    public function test_returns_all_shipping_methods(): void
    {
        $payload = $this->payload($this->tool->execute([]));
        self::assertCount(2, $payload['shipping_methods']);
    }

    public function test_schema_mode_returns_columns(): void
    {
        $d = json_decode($this->tool->execute(['schema' => true]), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($d['success']);
        self::assertSame('schema', $d['meta']['mode']);
        self::assertArrayHasKey('available_columns', $d['data']);
        self::assertArrayHasKey('default_columns', $d['data']);
    }

    public function test_schema_mode_includes_status_filters(): void
    {
        $d = json_decode($this->tool->execute(['schema' => true]), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('status_filters', $d['data']);
        self::assertContains('enabled', $d['data']['status_filters']);
        self::assertContains('disabled', $d['data']['status_filters']);
    }

    public function test_aggregate_returns_counts(): void
    {
        $d = json_decode($this->tool->execute(['aggregate' => true]), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($d['success']);
        self::assertSame('aggregate', $d['meta']['mode']);
        self::assertSame(2, $d['data']['stats']['total_methods']);
        self::assertSame(1, $d['data']['stats']['enabled']);
        self::assertSame(1, $d['data']['stats']['disabled']);
    }

    public function test_filter_by_status_enabled(): void
    {
        $payload = $this->payload($this->tool->execute(['status' => 'enabled']));
        self::assertCount(1, $payload['shipping_methods']);
    }

    public function test_filter_by_status_disabled(): void
    {
        $payload = $this->payload($this->tool->execute(['status' => 'disabled']));
        self::assertCount(1, $payload['shipping_methods']);
    }

    public function test_filter_by_status_all(): void
    {
        $payload = $this->payload($this->tool->execute(['status' => 'all']));
        self::assertCount(2, $payload['shipping_methods']);
    }

    public function test_search_by_method_name(): void
    {
        $payload = $this->payload($this->tool->execute(['search' => 'flat']));
        self::assertCount(1, $payload['shipping_methods']);
    }

    public function test_filter_by_geo_zone_id(): void
    {
        $payload = $this->payload($this->tool->execute(['geo_zone_id' => 1]));
        self::assertCount(1, $payload['shipping_methods']);
    }

    public function test_columns_wildcard(): void
    {
        $payload = $this->payload($this->tool->execute(['columns' => ['*']]));
        self::assertContains('tax_class', $payload['columns_returned']);
        self::assertContains('sort_order', $payload['columns_returned']);
    }

    public function test_columns_empty_returns_defaults(): void
    {
        $payload = $this->payload($this->tool->execute(['columns' => []]));
        self::assertContains('name', $payload['columns_returned']);
        self::assertContains('cost', $payload['columns_returned']);
    }

    public function test_limit_caps_results(): void
    {
        $payload = $this->payload($this->tool->execute(['limit' => 1]));
        self::assertCount(1, $payload['shipping_methods']);
    }

    public function test_offset_skips_rows(): void
    {
        $payload = $this->payload($this->tool->execute(['offset' => 1]));
        self::assertCount(1, $payload['shipping_methods']);
    }

    public function test_aggregate_with_search(): void
    {
        $d = json_decode($this->tool->execute(['aggregate' => true, 'search' => 'flat']), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $d['data']['stats']['total_methods']);
    }

    public function test_has_more_is_true_when_limit_reached(): void
    {
        $d = json_decode($this->tool->execute(['limit' => 1]), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($d['meta']['has_more']);
    }

    public function test_has_more_is_false_when_all_returned(): void
    {
        $d = json_decode($this->tool->execute([]), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($d['meta']['has_more']);
    }

    public function test_meta_shown_matches_returned_count(): void
    {
        $d = json_decode($this->tool->execute([]), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($d['meta']['shown'], count($d['data']['shipping_methods']));
    }

    public function test_meta_total_reflects_filtered_count(): void
    {
        $d = json_decode($this->tool->execute(['status' => 'enabled']), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $d['meta']['total']);
    }

    public function test_status_field_returns_string_enabled_or_disabled(): void
    {
        $payload = $this->payload($this->tool->execute(['columns' => ['*']]));
        self::assertCount(2, $payload['shipping_methods']);

        foreach ($payload['shipping_methods'] as $method) {
            self::assertContains($method['status'], ['enabled', 'disabled']);
        }
    }
}

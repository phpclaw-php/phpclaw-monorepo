<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Unit\Tools;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use PhpClaw\Drupal\Tests\Unit\Fixtures\BootsDrupalContainer;
use PhpClaw\Drupal\Tools\DrupalBlockTool;
use PHPUnit\Framework\TestCase;

final class DrupalBlockToolTest extends TestCase
{
    use BootsDrupalContainer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDrupalContainerWithUser();
    }

    private function buildBlock(string $id, string $label, string $region, string $plugin, bool $enabled, int $weight, string $theme): object
    {
        $block = new \stdClass;
        $block->id = $id;
        $block->label = $label;
        $block->region = $region;
        $block->plugin = $plugin;
        $block->enabled = $enabled;
        $block->weight = $weight;
        $block->theme = $theme;

        $mock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['id', 'label', 'getRegion', 'getPluginId', 'status', 'getWeight', 'getTheme'])
            ->getMock();

        $mock->method('id')->willReturn($id);
        $mock->method('label')->willReturn($label);
        $mock->method('getRegion')->willReturn($region);
        $mock->method('getPluginId')->willReturn($plugin);
        $mock->method('status')->willReturn($enabled);
        $mock->method('getWeight')->willReturn($weight);
        $mock->method('getTheme')->willReturn($theme);

        return $mock;
    }

    private function buildEtm(array $blocks): EntityTypeManagerInterface
    {
        $query = $this->createMock(QueryInterface::class);
        $query->method('accessCheck')->willReturnSelf();
        $query->method('range')->willReturnSelf();
        $query->method('execute')->willReturn(array_keys($blocks));

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('getQuery')->willReturn($query);
        $storage->method('loadMultiple')->willReturn($blocks);

        $etm = $this->createMock(EntityTypeManagerInterface::class);
        $etm->method('getStorage')->with('block')->willReturn($storage);

        return $etm;
    }

    public function test_name_returns_correct_value(): void
    {
        $tool = new DrupalBlockTool($this->buildEtm([]));
        $this->assertSame('drupal_blocks', $tool->name());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        $tool = new DrupalBlockTool($this->buildEtm([]));

        self::assertStringContainsString(
            'List Drupal block instances',
            $tool->description(),
        );
    }

    public function test_input_schema_has_expected_properties(): void
    {
        $tool = new DrupalBlockTool($this->buildEtm([]));
        $schema = $tool->inputSchema();

        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('region', $schema['properties']);
        $this->assertArrayHasKey('search', $schema['properties']);
        $this->assertArrayHasKey('required', $schema);
        $this->assertSame([], $schema['required']);
    }

    public function test_region_property_has_string_type(): void
    {
        $tool = new DrupalBlockTool($this->buildEtm([]));
        $schema = $tool->inputSchema();

        $this->assertSame('string', $schema['properties']['region']['type']);
    }

    public function test_search_property_has_description(): void
    {
        $tool = new DrupalBlockTool($this->buildEtm([]));
        $schema = $tool->inputSchema();

        self::assertSame(
            'Search by block label or plugin ID.',
            $schema['properties']['search']['description'],
        );
    }

    public function test_execute_returns_json_with_expected_keys(): void
    {
        $b1 = $this->buildBlock('header_branding', 'Branding', 'header', 'system_branding_block', true, 0, 'olivero');

        $tool = new DrupalBlockTool($this->buildEtm(['header_branding' => $b1]));
        $result = $tool->execute([]);
        $decoded = json_decode($result, true);

        $this->assertTrue($decoded['success']);
        $this->assertSame('query', $decoded['meta']['mode']);
        $this->assertArrayHasKey('blocks', $decoded['data']);
        $this->assertArrayHasKey('total', $decoded['meta']);
        $this->assertArrayHasKey('region_counts', $decoded['data']);
    }

    public function test_execute_empty_block_list(): void
    {
        $tool = new DrupalBlockTool($this->buildEtm([]));
        $result = $tool->execute([]);
        $decoded = json_decode($result, true);

        $this->assertSame([], $decoded['data']['blocks']);
        $this->assertSame(0, $decoded['meta']['total']);
        $this->assertSame([], $decoded['data']['region_counts']);
    }

    public function test_execute_block_has_expected_structure(): void
    {
        $b1 = $this->buildBlock('main_nav', 'Main Navigation', 'header', 'system_menu_block:main', true, 1, 'olivero');

        $tool = new DrupalBlockTool($this->buildEtm(['main_nav' => $b1]));
        $result = $tool->execute([]);
        $decoded = json_decode($result, true);

        $block = $decoded['data']['blocks'][0];
        $this->assertSame('main_nav', $block['id']);
        $this->assertSame('Main Navigation', $block['label']);
        $this->assertSame('header', $block['region']);
        $this->assertSame('system_menu_block:main', $block['plugin']);
        $this->assertSame('enabled', $block['status']);
        $this->assertSame(1, $block['weight']);
        $this->assertSame('olivero', $block['theme']);
    }

    public function test_execute_disabled_block_shows_disabled_status(): void
    {
        $b1 = $this->buildBlock('footer_powered', 'Powered By', 'footer', 'system_powered_by_block', false, 0, 'olivero');
        $tool = new DrupalBlockTool($this->buildEtm(['footer_powered' => $b1]));

        $decoded = json_decode($tool->execute([]), true);

        $this->assertSame('disabled', $decoded['data']['blocks'][0]['status']);
    }

    public function test_execute_filters_by_region(): void
    {
        $b1 = $this->buildBlock('header_b', 'Header Block', 'header', 'system_branding_block', true, 0, 'olivero');
        $b2 = $this->buildBlock('sidebar_b', 'Sidebar Block', 'sidebar_first', 'views_block:content', true, 0, 'olivero');

        $tool = new DrupalBlockTool($this->buildEtm(['header_b' => $b1, 'sidebar_b' => $b2]));
        $decoded = json_decode($tool->execute(['region' => 'sidebar_first']), true);

        $this->assertSame(1, $decoded['meta']['total']);
        $this->assertSame('sidebar_b', $decoded['data']['blocks'][0]['id']);
    }

    public function test_execute_filters_by_search_label(): void
    {
        $b1 = $this->buildBlock('logo_block', 'Site Logo', 'header', 'system_branding_block', true, 0, 'olivero');
        $b2 = $this->buildBlock('help_block', 'Help Text', 'content', 'help_block', true, 0, 'olivero');

        $tool = new DrupalBlockTool($this->buildEtm(['logo_block' => $b1, 'help_block' => $b2]));
        $decoded = json_decode($tool->execute(['search' => 'logo']), true);

        $this->assertSame(1, $decoded['meta']['total']);
        $this->assertSame('logo_block', $decoded['data']['blocks'][0]['id']);
    }

    public function test_execute_filters_by_search_plugin_id(): void
    {
        $b1 = $this->buildBlock('b1', 'Block One', 'header', 'views_block:articles', true, 0, 'olivero');
        $b2 = $this->buildBlock('b2', 'Block Two', 'content', 'system_menu_block:main', true, 0, 'olivero');

        $tool = new DrupalBlockTool($this->buildEtm(['b1' => $b1, 'b2' => $b2]));
        $decoded = json_decode($tool->execute(['search' => 'views_block']), true);

        $this->assertSame(1, $decoded['meta']['total']);
        $this->assertSame('b1', $decoded['data']['blocks'][0]['id']);
    }

    public function test_execute_region_counts_aggregated_correctly(): void
    {
        $b1 = $this->buildBlock('b1', 'B1', 'header', 'plugin_a', true, 0, 'olivero');
        $b2 = $this->buildBlock('b2', 'B2', 'header', 'plugin_b', true, 1, 'olivero');
        $b3 = $this->buildBlock('b3', 'B3', 'footer', 'plugin_c', true, 0, 'olivero');

        $tool = new DrupalBlockTool($this->buildEtm(['b1' => $b1, 'b2' => $b2, 'b3' => $b3]));
        $decoded = json_decode($tool->execute([]), true);

        $this->assertSame(3, $decoded['meta']['total']);
        $this->assertSame(2, $decoded['data']['region_counts']['header']);
        $this->assertSame(1, $decoded['data']['region_counts']['footer']);
    }

    public function test_execute_sorted_by_region_then_weight(): void
    {
        $b1 = $this->buildBlock('b1', 'B1', 'content', 'p1', true, 5, 'olivero');
        $b2 = $this->buildBlock('b2', 'B2', 'content', 'p2', true, 2, 'olivero');
        $b3 = $this->buildBlock('b3', 'B3', 'header', 'p3', true, 0, 'olivero');

        $tool = new DrupalBlockTool($this->buildEtm(['b1' => $b1, 'b2' => $b2, 'b3' => $b3]));
        $decoded = json_decode($tool->execute([]), true);

        $this->assertSame('b2', $decoded['data']['blocks'][0]['id']);
        $this->assertSame('b1', $decoded['data']['blocks'][1]['id']);
        $this->assertSame('b3', $decoded['data']['blocks'][2]['id']);
    }

    public function test_execute_label_falls_back_to_id_when_empty(): void
    {
        $mock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['id', 'label', 'getRegion', 'getPluginId', 'status', 'getWeight', 'getTheme'])
            ->getMock();

        $mock->method('id')->willReturn('no_label_block');
        $mock->method('label')->willReturn('');
        $mock->method('getRegion')->willReturn('header');
        $mock->method('getPluginId')->willReturn('system_branding_block');
        $mock->method('status')->willReturn(true);
        $mock->method('getWeight')->willReturn(0);
        $mock->method('getTheme')->willReturn('olivero');

        $tool = new DrupalBlockTool($this->buildEtm(['no_label_block' => $mock]));
        $decoded = json_decode($tool->execute([]), true);

        $this->assertSame('no_label_block', $decoded['data']['blocks'][0]['label']);
    }

    public function test_execute_array_input_values_are_normalized(): void
    {
        $b1 = $this->buildBlock('sidebar_b', 'Sidebar Block', 'sidebar_first', 'views_block:content', true, 0, 'olivero');
        $tool = new DrupalBlockTool($this->buildEtm(['sidebar_b' => $b1]));

        $decoded = json_decode($tool->execute(['region' => ['sidebar_first']]), true);

        $this->assertSame(1, $decoded['meta']['total']);
    }

    public function test_execute_region_and_search_combined(): void
    {
        $b1 = $this->buildBlock('b1', 'Navigation Menu', 'header', 'system_menu_block:main', true, 0, 'olivero');
        $b2 = $this->buildBlock('b2', 'Navigation Menu', 'sidebar_first', 'system_menu_block:account', true, 0, 'olivero');

        $tool = new DrupalBlockTool($this->buildEtm(['b1' => $b1, 'b2' => $b2]));
        $decoded = json_decode($tool->execute(['region' => 'header', 'search' => 'navigation']), true);

        $this->assertSame(1, $decoded['meta']['total']);
        $this->assertSame('b1', $decoded['data']['blocks'][0]['id']);
    }

    public function test_forbidden_when_the_caller_lacks_the_permission(): void
    {
        $this->bootDrupalContainerWithUser(2, allPermissions: false);

        $tool = new DrupalBlockTool($this->buildEtm([]), null);

        $decoded = json_decode($tool->execute([]), true, 512, JSON_THROW_ON_ERROR);

        self::assertFalse($decoded['success']);
        self::assertSame('FORBIDDEN', $decoded['error']['code']);
        self::assertStringContainsString('use phpclaw chat', $decoded['error']['message']);
    }

    public function test_refuses_cleanly_when_the_block_module_is_absent(): void
    {
        $handler = $this->createMock(ModuleHandlerInterface::class);
        $handler->method('moduleExists')->willReturn(false);

        $decoded = json_decode(
            (new DrupalBlockTool($this->buildEtm([]), $handler))->execute([]),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame('MODULE_NOT_INSTALLED', $decoded['error']['code']);
        self::assertSame('block', $decoded['error']['module']);
    }

    public function test_schema_mode_declares_no_untrusted_field(): void
    {
        $decoded = json_decode(
            (new DrupalBlockTool($this->buildEtm([]), null))->execute(['schema' => true]),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame('schema', $decoded['meta']['mode']);
        self::assertSame([], $decoded['data']['untrusted_columns']);
        self::assertSame('block', $decoded['data']['required_module']);
        self::assertCount(3, $decoded['data']['examples']);
    }

    public function test_sort_is_region_then_weight_then_id(): void
    {
        $blocks = [
            'zulu' => $this->buildBlock('zulu', 'Zulu', 'footer', 'plug', true, 5, 'olivero'),
            'alpha' => $this->buildBlock('alpha', 'Alpha', 'footer', 'plug', true, 5, 'olivero'),
            'early' => $this->buildBlock('early', 'Early', 'footer', 'plug', true, 1, 'olivero'),
        ];

        $decoded = json_decode(
            (new DrupalBlockTool($this->buildEtm($blocks), null))->execute([]),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(['early', 'alpha', 'zulu'], array_column($decoded['data']['blocks'], 'id'));
    }

    public function test_total_is_the_matched_set_and_paging_is_a_slice(): void
    {
        $blocks = [];

        foreach (['a', 'b', 'c'] as $id) {
            $blocks[$id] = $this->buildBlock($id, strtoupper($id), 'content', 'plug', true, 0, 'olivero');
        }

        $decoded = json_decode(
            (new DrupalBlockTool($this->buildEtm($blocks), null))->execute(['limit' => 2]),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(3, $decoded['meta']['total']);
        self::assertSame(2, $decoded['meta']['count']);
        self::assertTrue($decoded['meta']['has_more']);
        self::assertSame(2, $decoded['meta']['next_offset']);
    }

    public function test_unknown_argument_is_refused(): void
    {
        $decoded = json_decode(
            (new DrupalBlockTool($this->buildEtm([]), null))->execute(['nope' => 1]),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame('UNKNOWN_ARGUMENT', $decoded['error']['code']);
    }

    public function test_a_full_last_page_does_not_claim_more(): void
    {
        $blocks = [
            'a' => $this->buildBlock('a', 'A', 'content', 'plug', true, 0, 'olivero'),
            'b' => $this->buildBlock('b', 'B', 'content', 'plug', true, 0, 'olivero'),
        ];

        $decoded = json_decode(
            (new DrupalBlockTool($this->buildEtm($blocks), null))->execute(['limit' => 2]),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(2, $decoded['meta']['total']);
        self::assertSame(2, $decoded['meta']['count']);
        self::assertFalse($decoded['meta']['has_more'], 'a full page that exhausts the set has no next page');
        self::assertNull($decoded['meta']['next_offset']);
    }
}

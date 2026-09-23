<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\Tools;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\WordPress\Tests\Concerns\StubsCapabilities;
use PhpClaw\WordPress\Tools\WpMenuTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WpMenuTool::class)]
final class WpMenuToolTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use StubsCapabilities;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->grantCapability('phpclaw_use_chat');
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_name_is_wp_menus(): void
    {
        self::assertSame('wp_menus', (new WpMenuTool)->name());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        self::assertStringContainsString(
            'WordPress navigation menus',
            (new WpMenuTool)->description(),
        );
    }

    public function test_input_schema_describes_all_params(): void
    {
        $s = (new WpMenuTool)->inputSchema();

        foreach (['columns', 'schema', 'aggregate', 'menu', 'parent', 'depth', 'limit'] as $k) {
            self::assertArrayHasKey($k, $s['properties']);
        }
    }

    public function test_schema_mode_returns_columns_and_modes(): void
    {
        $r = json_decode((new WpMenuTool)->execute(['schema' => true]), true);

        self::assertSame('schema', $r['meta']['mode']);
        self::assertNotEmpty($r['data']['available_columns']);
        self::assertNotEmpty($r['data']['default_columns']);
        self::assertContains('aggregate', $r['data']['modes']);
    }

    public function test_aggregate_mode_returns_per_menu_counts(): void
    {
        $a = new \stdClass;
        $a->name = 'Primary';
        $a->count = 5;
        $b = new \stdClass;
        $b->name = 'Footer';
        $b->count = 3;
        Functions\expect('wp_get_nav_menus')->once()->andReturn([$a, $b]);

        $r = json_decode((new WpMenuTool)->execute(['aggregate' => true]), true);

        self::assertSame('aggregate', $r['meta']['mode']);
        self::assertSame(2, $r['meta']['total_menus']);
        self::assertSame(8, $r['meta']['total_items']);
        self::assertSame('Primary', $r['data']['menus'][0]['menu_name']);
        self::assertSame(5, $r['data']['menus'][0]['item_count']);
    }

    public function test_execute_lists_all_menus(): void
    {
        $menu = new \stdClass;
        $menu->term_id = 1;
        $menu->name = 'Primary Menu';
        $menu->slug = 'primary';
        $menu->count = 5;

        Functions\expect('wp_get_nav_menus')->once()->andReturn([$menu]);
        Functions\expect('get_nav_menu_locations')->once()->andReturn(['primary' => 1]);
        Functions\expect('get_registered_nav_menus')->once()->andReturn(['primary' => 'Primary']);

        $result = json_decode((new WpMenuTool)->execute([]), true);

        self::assertArrayHasKey('menus', $result['data']);
        self::assertSame(1, $result['meta']['total']);
        self::assertSame('Primary Menu', $result['data']['menus'][0]['name']);
        self::assertSame(5, $result['data']['menus'][0]['item_count']);
    }

    public function test_execute_gets_specific_menu_items(): void
    {
        $item = new \stdClass;
        $item->ID = 10;
        $item->title = 'Home';
        $item->url = '/';
        $item->menu_item_parent = 0;
        $item->menu_order = 1;
        $item->type = 'custom';
        $item->object = 'custom';
        $item->object_id = 10;
        $item->target = '';
        $item->description = '';
        $item->classes = [];

        $menuObj = new \stdClass;
        $menuObj->name = 'Primary Menu';

        Functions\stubs([
            'get_nav_menu_locations' => fn () => [],
            'get_registered_nav_menus' => fn () => [],
        ]);
        Functions\expect('wp_get_nav_menu_items')->with('primary')->once()->andReturn([$item]);
        Functions\expect('wp_get_nav_menu_object')->zeroOrMoreTimes()->andReturn($menuObj);

        $result = json_decode((new WpMenuTool)->execute(['menu' => 'primary']), true);

        self::assertSame(1, $result['meta']['count']);
        self::assertSame('Home', $result['data']['items'][0]['title']);
        self::assertSame('/', $result['data']['items'][0]['url']);
    }

    public function test_execute_returns_empty_for_unknown_menu(): void
    {
        Functions\stubs([
            'get_nav_menu_locations' => fn () => [],
            'get_registered_nav_menus' => fn () => [],
        ]);
        Functions\expect('wp_get_nav_menu_items')->with('nonexistent')->once()->andReturn(false);

        $result = json_decode((new WpMenuTool)->execute(['menu' => 'nonexistent']), true);

        self::assertTrue($result['success']);
        self::assertSame([], $result['data']['items']);
        self::assertSame('nonexistent', $result['meta']['menu']);
        self::assertFalse($result['meta']['has_more']);
    }

    public function test_menu_numeric_id_is_passed_as_int(): void
    {
        Functions\expect('wp_get_nav_menu_items')->with(42)->once()->andReturn([]);

        $result = json_decode((new WpMenuTool)->execute(['menu' => '42']), true);

        self::assertSame(42, (int) $result['meta']['menu']);
    }

    public function test_execute_menu_items_filters_by_parent_and_depth(): void
    {
        $parent = (object) [
            'ID' => 1, 'title' => 'Parent', 'url' => '/p', 'menu_item_parent' => 0,
            'menu_order' => 1, 'type' => 'custom', 'object' => 'custom', 'object_id' => 1,
            'target' => '', 'description' => '', 'classes' => [],
        ];
        $child = (object) [
            'ID' => 2, 'title' => 'Child', 'url' => '/p/c', 'menu_item_parent' => 1,
            'menu_order' => 2, 'type' => 'custom', 'object' => 'custom', 'object_id' => 2,
            'target' => '', 'description' => '', 'classes' => [],
        ];
        $menuObj = (object) ['name' => 'Primary'];

        Functions\expect('wp_get_nav_menu_items')->andReturn([$parent, $child]);
        Functions\expect('wp_get_nav_menu_object')->zeroOrMoreTimes()->andReturn($menuObj);

        $result = json_decode((new WpMenuTool)->execute(['menu' => 'primary', 'parent' => 0]), true);

        self::assertSame(1, $result['meta']['count']);
        self::assertSame('Parent', $result['data']['items'][0]['title']);
    }

    public function test_execute_menu_items_depth_filter(): void
    {
        $parent = (object) [
            'ID' => 1, 'title' => 'Parent', 'url' => '/p', 'menu_item_parent' => 0,
            'menu_order' => 1, 'type' => 'custom', 'object' => 'custom', 'object_id' => 1,
            'target' => '', 'description' => '', 'classes' => [],
        ];
        $child = (object) [
            'ID' => 2, 'title' => 'Child', 'url' => '/c', 'menu_item_parent' => 1,
            'menu_order' => 2, 'type' => 'custom', 'object' => 'custom', 'object_id' => 2,
            'target' => '_blank', 'description' => 'desc', 'classes' => ['cls'],
        ];
        $menuObj = (object) ['name' => 'Primary'];

        Functions\expect('wp_get_nav_menu_items')->andReturn([$parent, $child]);
        Functions\expect('wp_get_nav_menu_object')->zeroOrMoreTimes()->andReturn($menuObj);

        $result = json_decode((new WpMenuTool)->execute(['menu' => 'primary', 'depth' => 1]), true);

        self::assertSame(1, $result['meta']['count']);
        self::assertSame('Child', $result['data']['items'][0]['title']);
    }

    public function test_build_row_with_all_columns_via_reflection(): void
    {
        $item = (object) [
            'ID' => 7, 'title' => 'Hello', 'url' => '/x', 'menu_item_parent' => 0,
            'menu_order' => 3, 'type' => 'post_type', 'object' => 'page', 'object_id' => 99,
            'target' => '_blank', 'description' => 'desc', 'classes' => ['a', 'b', ''],
        ];

        $tool = new WpMenuTool;
        $ref = new \ReflectionClass($tool);
        $m = $ref->getMethod('buildRow');
        $m->setAccessible(true);

        $allCols = ['id', 'title', 'url', 'type', 'object', 'object_id', 'menu_order', 'parent', 'menu_name', 'depth', 'classes', 'target', 'description'];
        $row = $m->invoke($tool, $item, $allCols, 'Primary', 2);

        self::assertSame(7, $row['id']);
        self::assertSame(2, $row['depth']);
        self::assertSame('_blank', $row['target']);
        self::assertSame('desc', $row['description']);
        self::assertSame('a b', $row['classes']);
        self::assertSame('Primary', $row['menu_name']);
        self::assertSame('post_type', $row['type']);
        self::assertSame('page', $row['object']);
        self::assertSame(99, $row['object_id']);
    }

    public function test_resolve_columns_reflection(): void
    {
        $t = new WpMenuTool;
        $ref = new \ReflectionClass($t);
        $m = $ref->getMethod('resolveColumns');
        $m->setAccessible(true);

        self::assertNotEmpty($m->invoke($t, ['*']));
        self::assertContains('id', $m->invoke($t, []));
        self::assertSame(['id', 'title'], $m->invoke($t, ['title', 'bad_col']));
        self::assertContains('id', $m->invoke($t, 'not-array'));
    }

    public function test_it_returns_forbidden_without_the_required_capability(): void
    {
        $this->denyAllCapabilities();

        $this->assertForbiddenEnvelope((new WpMenuTool)->execute(['schema' => true]));
    }

    public function test_it_returns_unknown_argument_instead_of_throwing(): void
    {
        $decoded = json_decode((new WpMenuTool)->execute(['phpclaw_bogus_arg' => 1]), true);

        self::assertFalse($decoded['success']);
        self::assertSame('UNKNOWN_ARGUMENT', $decoded['error']['code']);
        self::assertNotEmpty($decoded['error']['accepted_arguments']);
    }

    public function test_successful_envelope_has_exactly_the_contract_keys(): void
    {

        $decoded = json_decode((new WpMenuTool)->execute(['schema' => true]), true);

        self::assertTrue($decoded['success']);
        self::assertSame(['success', 'data', 'meta', 'warnings'], array_keys($decoded));
        self::assertArrayHasKey('mode', $decoded['meta']);
    }
}

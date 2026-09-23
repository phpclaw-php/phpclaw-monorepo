<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Unit\Tools;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use PhpClaw\Drupal\Tests\Unit\Fixtures\BootsDrupalContainer;
use PhpClaw\Drupal\Tools\DrupalViewsTool;
use PHPUnit\Framework\TestCase;

final class DrupalViewsToolTest extends TestCase
{
    use BootsDrupalContainer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDrupalContainerWithUser();
    }

    private function buildView(string $id, string $label, bool $enabled, array $displays): object
    {
        $mock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['id', 'label', 'status', 'get'])
            ->getMock();

        $mock->method('id')->willReturn($id);
        $mock->method('label')->willReturn($label);
        $mock->method('status')->willReturn($enabled);
        $mock->method('get')->with('display')->willReturn($displays);

        return $mock;
    }

    private function buildModuleHandler(bool $viewsEnabled): ModuleHandlerInterface
    {
        $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
        $moduleHandler->method('moduleExists')
            ->with('views')
            ->willReturn($viewsEnabled);

        return $moduleHandler;
    }

    private function buildEtm(array $views): EntityTypeManagerInterface
    {
        $query = $this->createMock(QueryInterface::class);
        $query->method('accessCheck')->willReturnSelf();
        $query->method('range')->willReturnSelf();
        $query->method('execute')->willReturn(array_keys($views));

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('getQuery')->willReturn($query);
        $storage->method('loadMultiple')->willReturn($views);

        $etm = $this->createMock(EntityTypeManagerInterface::class);
        $etm->method('getStorage')->with('view')->willReturn($storage);

        return $etm;
    }

    private function buildTool(bool $viewsEnabled = true, array $views = []): DrupalViewsTool
    {
        return new DrupalViewsTool(
            $this->buildModuleHandler($viewsEnabled),
            $this->buildEtm($views),
        );
    }

    public function test_name_returns_correct_value(): void
    {
        $this->assertSame('drupal_views', $this->buildTool()->name());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        $tool = $this->buildTool();

        self::assertStringContainsString(
            'List Drupal Views',
            $tool->description(),
        );
    }

    public function test_input_schema_has_expected_properties(): void
    {
        $schema = $this->buildTool()->inputSchema();

        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('search', $schema['properties']);
        $this->assertArrayHasKey('status', $schema['properties']);
        $this->assertArrayHasKey('required', $schema);
        $this->assertSame([], $schema['required']);
    }

    public function test_status_property_has_enum(): void
    {
        $schema = $this->buildTool()->inputSchema();
        $this->assertSame(['all', 'enabled', 'disabled'], $schema['properties']['status']['enum']);
    }

    public function test_search_property_has_string_type(): void
    {
        $schema = $this->buildTool()->inputSchema();
        $this->assertSame('string', $schema['properties']['search']['type']);
    }

    public function test_execute_returns_error_when_views_module_disabled(): void
    {
        $tool = $this->buildTool(viewsEnabled: false);
        $result = $tool->execute([]);
        $decoded = json_decode($result, true);

        $this->assertFalse($decoded['success']);
        $this->assertSame('MODULE_NOT_INSTALLED', $decoded['error']['code']);
        $this->assertSame('views', $decoded['error']['module']);
        $this->assertNull($decoded['data']);
        $this->assertSame('error', $decoded['meta']['mode']);
        $this->assertStringContainsString('not installed', $decoded['error']['message']);
    }

    public function test_execute_returns_json_with_expected_keys(): void
    {
        $result = $this->buildTool()->execute([]);
        $decoded = json_decode($result, true);

        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['success']);
        $this->assertArrayHasKey('views', $decoded['data']);
        $this->assertArrayHasKey('total', $decoded['meta']);
    }

    public function test_execute_empty_views_list(): void
    {
        $result = $this->buildTool()->execute([]);
        $decoded = json_decode($result, true);

        $this->assertSame([], $decoded['data']['views']);
        $this->assertSame(0, $decoded['meta']['total']);
    }

    public function test_execute_view_has_expected_structure(): void
    {
        $displays = [
            'default' => [
                'display_title' => 'Master',
                'display_plugin' => 'default',
                'display_options' => ['path' => ''],
            ],
        ];

        $view = $this->buildView('content', 'Content', true, $displays);
        $tool = $this->buildTool(views: ['content' => $view]);
        $result = $tool->execute([]);
        $decoded = json_decode($result, true);

        $v = $decoded['data']['views'][0];
        $this->assertSame('content', $v['id']);
        $this->assertSame('Content', $v['label']);
        $this->assertSame('enabled', $v['status']);
        $this->assertArrayHasKey('displays', $v);
        $this->assertCount(1, $v['displays']);
    }

    public function test_execute_disabled_view_shows_disabled_status(): void
    {
        $view = $this->buildView('archive', 'Archive', false, []);
        $tool = $this->buildTool(views: ['archive' => $view]);
        $result = $tool->execute([]);
        $decoded = json_decode($result, true);

        $this->assertSame('disabled', $decoded['data']['views'][0]['status']);
    }

    public function test_execute_filters_enabled_only(): void
    {
        $v1 = $this->buildView('active_view', 'Active View', true, []);
        $v2 = $this->buildView('inactive_view', 'Inactive View', false, []);
        $tool = $this->buildTool(views: ['active_view' => $v1, 'inactive_view' => $v2]);

        $result = $tool->execute(['status' => 'enabled']);
        $decoded = json_decode($result, true);

        $this->assertSame(1, $decoded['meta']['total']);
        $this->assertSame('active_view', $decoded['data']['views'][0]['id']);
    }

    public function test_execute_filters_disabled_only(): void
    {
        $v1 = $this->buildView('active_view', 'Active View', true, []);
        $v2 = $this->buildView('inactive_view', 'Inactive View', false, []);
        $tool = $this->buildTool(views: ['active_view' => $v1, 'inactive_view' => $v2]);

        $result = $tool->execute(['status' => 'disabled']);
        $decoded = json_decode($result, true);

        $this->assertSame(1, $decoded['meta']['total']);
        $this->assertSame('inactive_view', $decoded['data']['views'][0]['id']);
    }

    public function test_execute_search_by_label(): void
    {
        $v1 = $this->buildView('frontpage', 'Frontpage', true, []);
        $v2 = $this->buildView('content', 'Content', true, []);
        $tool = $this->buildTool(views: ['frontpage' => $v1, 'content' => $v2]);

        $result = $tool->execute(['search' => 'front']);
        $decoded = json_decode($result, true);

        $this->assertSame(1, $decoded['meta']['total']);
        $this->assertSame('frontpage', $decoded['data']['views'][0]['id']);
    }

    public function test_execute_search_by_id(): void
    {
        $v1 = $this->buildView('frontpage', 'Frontpage', true, []);
        $v2 = $this->buildView('content', 'Content', true, []);
        $tool = $this->buildTool(views: ['frontpage' => $v1, 'content' => $v2]);

        $result = $tool->execute(['search' => 'content']);
        $decoded = json_decode($result, true);

        $this->assertSame(1, $decoded['meta']['total']);
        $this->assertSame('content', $decoded['data']['views'][0]['id']);
    }

    public function test_execute_display_has_expected_structure(): void
    {
        $displays = [
            'page_1' => [
                'display_title' => 'Page',
                'display_plugin' => 'page',
                'display_options' => ['path' => '/content'],
            ],
        ];

        $view = $this->buildView('content', 'Content', true, $displays);
        $tool = $this->buildTool(views: ['content' => $view]);
        $result = $tool->execute([]);
        $decoded = json_decode($result, true);

        $display = $decoded['data']['views'][0]['displays'][0];
        $this->assertSame('page_1', $display['id']);
        $this->assertSame('Page', $display['title']);
        $this->assertSame('page', $display['type']);
        $this->assertSame('/content', $display['path']);
    }

    public function test_execute_display_title_falls_back_to_id(): void
    {
        $displays = [
            'block_1' => [
                'display_plugin' => 'block',
                'display_options' => [],
            ],
        ];

        $view = $this->buildView('sidebar_view', 'Sidebar View', true, $displays);
        $tool = $this->buildTool(views: ['sidebar_view' => $view]);
        $result = $tool->execute([]);
        $decoded = json_decode($result, true);

        $this->assertSame('block_1', $decoded['data']['views'][0]['displays'][0]['title']);
    }

    public function test_execute_display_path_falls_back_to_dash(): void
    {
        $displays = [
            'default' => [
                'display_title' => 'Master',
                'display_plugin' => 'default',
                'display_options' => [],
            ],
        ];

        $view = $this->buildView('my_view', 'My View', true, $displays);
        $tool = $this->buildTool(views: ['my_view' => $view]);
        $result = $tool->execute([]);
        $decoded = json_decode($result, true);

        $this->assertSame('-', $decoded['data']['views'][0]['displays'][0]['path']);
    }

    public function test_execute_view_label_falls_back_to_id(): void
    {
        $mock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['id', 'label', 'status', 'get'])
            ->getMock();

        $mock->method('id')->willReturn('no_label_view');
        $mock->method('label')->willReturn('');
        $mock->method('status')->willReturn(true);
        $mock->method('get')->with('display')->willReturn([]);

        $tool = $this->buildTool(views: ['no_label_view' => $mock]);
        $result = $tool->execute([]);
        $decoded = json_decode($result, true);

        $this->assertSame('no_label_view', $decoded['data']['views'][0]['label']);
    }

    public function test_execute_multiple_displays_per_view(): void
    {
        $displays = [
            'default' => [
                'display_title' => 'Master',
                'display_plugin' => 'default',
                'display_options' => [],
            ],
            'page_1' => [
                'display_title' => 'Page',
                'display_plugin' => 'page',
                'display_options' => ['path' => '/articles'],
            ],
            'block_1' => [
                'display_title' => 'Block',
                'display_plugin' => 'block',
                'display_options' => [],
            ],
        ];

        $view = $this->buildView('articles', 'Articles', true, $displays);
        $tool = $this->buildTool(views: ['articles' => $view]);
        $result = $tool->execute([]);
        $decoded = json_decode($result, true);

        $this->assertCount(3, $decoded['data']['views'][0]['displays']);
    }

    public function test_execute_array_input_normalized(): void
    {
        $view = $this->buildView('active_view', 'Active View', true, []);
        $tool = $this->buildTool(views: ['active_view' => $view]);
        $result = $tool->execute(['status' => ['enabled']]);
        $decoded = json_decode($result, true);

        $this->assertSame(1, $decoded['meta']['total']);
    }

    public function test_forbidden_when_the_caller_lacks_the_permission(): void
    {
        $this->bootDrupalContainerWithUser(2, allPermissions: false);
        $tool = new DrupalViewsTool($this->buildModuleHandler(true), $this->buildEtm([]));

        $decoded = json_decode($tool->execute([]), true, 512, JSON_THROW_ON_ERROR);

        self::assertFalse($decoded['success']);
        self::assertSame('FORBIDDEN', $decoded['error']['code']);
        self::assertStringContainsString('use phpclaw chat', $decoded['error']['message']);
    }

    public function test_schema_mode_declares_no_untrusted_field(): void
    {
        $decoded = json_decode($this->buildTool()->execute(['schema' => true]), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('schema', $decoded['meta']['mode']);
        self::assertSame([], $decoded['data']['untrusted_columns']);
        self::assertSame('views', $decoded['data']['required_module']);
        self::assertCount(3, $decoded['data']['examples']);
    }

    public function test_a_full_last_page_does_not_claim_more(): void
    {
        $views = [
            'alpha' => $this->buildView('alpha', 'Alpha', true, []),
            'bravo' => $this->buildView('bravo', 'Bravo', true, []),
        ];

        $decoded = json_decode(
            $this->buildTool(views: $views)->execute(['limit' => 2]),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(2, $decoded['meta']['total']);
        self::assertSame(2, $decoded['meta']['count']);
        self::assertFalse($decoded['meta']['has_more'], 'a full page that exhausts the set has no next page');
        self::assertNull($decoded['meta']['next_offset']);
    }

    public function test_view_order_does_not_depend_on_storage_order(): void
    {
        $views = [
            'zebra' => $this->buildView('zebra', 'Zebra', true, []),
            'mango' => $this->buildView('mango', 'Mango', true, []),
            'apple' => $this->buildView('apple', 'Apple', true, []),
        ];

        $decoded = json_decode(
            $this->buildTool(views: $views)->execute(['limit' => 2]),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(['apple', 'mango'], array_column($decoded['data']['views'], 'id'));
    }

    public function test_unknown_argument_is_refused(): void
    {
        $decoded = json_decode($this->buildTool()->execute(['nope' => 1]), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('UNKNOWN_ARGUMENT', $decoded['error']['code']);
    }

    public function test_unknown_status_is_refused(): void
    {
        $decoded = json_decode($this->buildTool()->execute(['status' => 'zzz']), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('INVALID_ARGUMENT', $decoded['error']['code']);
    }
}

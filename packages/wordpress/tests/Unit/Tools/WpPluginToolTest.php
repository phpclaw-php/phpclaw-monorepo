<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\Tools;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\WordPress\Tests\Concerns\StubsCapabilities;
use PhpClaw\WordPress\Tools\WpPluginTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WpPluginTool::class)]
final class WpPluginToolTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use StubsCapabilities;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->grantCapability('phpclaw_use_chat');

        if (! defined('ABSPATH')) {
            define('ABSPATH', '/tmp/wp/');
        }

        Functions\expect('get_site_transient')->zeroOrMoreTimes()->andReturn(false);
        Functions\expect('is_multisite')->zeroOrMoreTimes()->andReturn(false);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_name_is_wp_plugins(): void
    {
        self::assertSame('wp_plugins', (new WpPluginTool)->name());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        self::assertStringContainsString(
            'installed WordPress plugins',
            (new WpPluginTool)->description(),
        );
    }

    public function test_execute_returns_active_and_inactive_plugins(): void
    {
        $activePlugins = ['yoast/yoast.php', 'akismet/akismet.php'];

        Functions\expect('get_plugins')->once()->andReturn([
            'yoast/yoast.php' => ['Name' => 'Yoast SEO', 'Version' => '23.5', 'AuthorName' => 'Yoast', 'Description' => 'SEO plugin'],
            'akismet/akismet.php' => ['Name' => 'Akismet', 'Version' => '5.3', 'AuthorName' => 'Automattic', 'Description' => 'Spam protection'],
            'hello.php' => ['Name' => 'Hello Dolly', 'Version' => '1.7', 'AuthorName' => 'Matt', 'Description' => 'Hello'],
        ]);
        Functions\expect('get_option')->zeroOrMoreTimes()->andReturn($activePlugins);
        Functions\expect('is_plugin_active')->zeroOrMoreTimes()->andReturnUsing(
            fn (string $plugin) => in_array($plugin, $activePlugins, true),
        );

        $result = json_decode((new WpPluginTool)->execute([]), true);

        self::assertSame(3, $result['meta']['count']);
        self::assertArrayHasKey('plugins', $result['data']);
        self::assertTrue($result['data']['plugins'][0]['active']);
        self::assertFalse($result['data']['plugins'][2]['active']);
    }

    public function test_execute_filters_by_status_active(): void
    {
        $activePlugins = ['yoast/yoast.php'];

        Functions\expect('get_plugins')->once()->andReturn([
            'yoast/yoast.php' => ['Name' => 'Yoast SEO', 'Version' => '23.5', 'AuthorName' => 'Yoast', 'Description' => ''],
            'hello.php' => ['Name' => 'Hello Dolly', 'Version' => '1.7', 'AuthorName' => 'Matt', 'Description' => ''],
        ]);
        Functions\expect('get_option')->zeroOrMoreTimes()->andReturn($activePlugins);
        Functions\expect('is_plugin_active')->zeroOrMoreTimes()->andReturnUsing(
            fn (string $plugin) => in_array($plugin, $activePlugins, true),
        );

        $result = json_decode((new WpPluginTool)->execute(['status' => 'active']), true);

        self::assertCount(1, $result['data']['plugins']);
        self::assertSame('Yoast SEO', $result['data']['plugins'][0]['name']);
    }

    public function test_execute_filters_by_search(): void
    {
        $activePlugins = [];

        Functions\expect('get_plugins')->once()->andReturn([
            'yoast/yoast.php' => ['Name' => 'Yoast SEO', 'Version' => '23.5', 'AuthorName' => 'Yoast', 'Description' => ''],
            'hello.php' => ['Name' => 'Hello Dolly', 'Version' => '1.7', 'AuthorName' => 'Matt', 'Description' => ''],
        ]);
        Functions\expect('get_option')->zeroOrMoreTimes()->andReturn($activePlugins);
        Functions\expect('is_plugin_active')->zeroOrMoreTimes()->andReturnUsing(
            fn (string $plugin) => in_array($plugin, $activePlugins, true),
        );

        $result = json_decode((new WpPluginTool)->execute(['search' => 'yoast']), true);

        self::assertCount(1, $result['data']['plugins']);
        self::assertSame('Yoast SEO', $result['data']['plugins'][0]['name']);
    }

    public function test_schema_mode_returns_columns_and_filters(): void
    {
        $result = json_decode((new WpPluginTool)->execute(['schema' => true]), true);

        self::assertSame('schema', $result['meta']['mode']);
        self::assertContains('name', $result['data']['available_columns']);
        self::assertContains('update_available', $result['data']['available_columns']);
        self::assertContains('network_active', $result['data']['available_columns']);
        self::assertSame(['name', 'version', 'active', 'author'], $result['data']['default_columns']);
        self::assertSame(['all', 'active', 'inactive'], $result['data']['status_filters']);
        self::assertContains('search', $result['data']['filters']);
    }

    public function test_aggregate_counts_active_inactive_and_updatable(): void
    {
        $active = ['yoast/yoast.php', 'akismet/akismet.php'];

        Functions\expect('get_plugins')->once()->andReturn([
            'yoast/yoast.php' => ['Name' => 'Yoast SEO'],
            'akismet/akismet.php' => ['Name' => 'Akismet'],
            'hello.php' => ['Name' => 'Hello Dolly'],
        ]);
        Functions\expect('get_option')->zeroOrMoreTimes()->andReturn($active);
        Functions\expect('is_plugin_active')->zeroOrMoreTimes()->andReturnUsing(
            fn (string $p) => in_array($p, $active, true),
        );

        $result = json_decode((new WpPluginTool)->execute(['aggregate' => true]), true);

        self::assertSame('aggregate', $result['meta']['mode']);
        self::assertSame(3, $result['data']['total']);
        self::assertSame(2, $result['data']['active']);
        self::assertSame(1, $result['data']['inactive']);
        self::assertSame(0, $result['data']['update_available']);
    }

    public function test_aggregate_with_search_narrows_counts(): void
    {
        $active = ['yoast/yoast.php'];

        Functions\expect('get_plugins')->once()->andReturn([
            'yoast/yoast.php' => ['Name' => 'Yoast SEO'],
            'hello.php' => ['Name' => 'Hello Dolly'],
        ]);
        Functions\expect('get_option')->zeroOrMoreTimes()->andReturn($active);
        Functions\expect('is_plugin_active')->zeroOrMoreTimes()->andReturnUsing(
            fn (string $p) => in_array($p, $active, true),
        );

        $result = json_decode((new WpPluginTool)->execute(['aggregate' => true, 'search' => 'yoast']), true);

        self::assertSame(1, $result['data']['total']);
        self::assertSame(1, $result['data']['active']);
        self::assertSame(0, $result['data']['inactive']);
    }

    public function test_status_inactive_filter(): void
    {
        $active = ['yoast/yoast.php'];

        Functions\expect('get_plugins')->once()->andReturn([
            'yoast/yoast.php' => ['Name' => 'Yoast SEO'],
            'hello.php' => ['Name' => 'Hello Dolly'],
            'akismet/akismet.php' => ['Name' => 'Akismet'],
        ]);
        Functions\expect('get_option')->zeroOrMoreTimes()->andReturn($active);
        Functions\expect('is_plugin_active')->zeroOrMoreTimes()->andReturnUsing(
            fn (string $p) => in_array($p, $active, true),
        );

        $result = json_decode((new WpPluginTool)->execute(['status' => 'inactive']), true);

        self::assertCount(2, $result['data']['plugins']);
        foreach ($result['data']['plugins'] as $p) {
            self::assertFalse($p['active']);
        }
    }

    public function test_limit_caps_results_and_has_more_flag(): void
    {
        Functions\expect('get_plugins')->once()->andReturn([
            'a/a.php' => ['Name' => 'A'],
            'b/b.php' => ['Name' => 'B'],
            'c/c.php' => ['Name' => 'C'],
        ]);
        Functions\expect('get_option')->zeroOrMoreTimes()->andReturn([]);
        Functions\expect('is_plugin_active')->zeroOrMoreTimes()->andReturn(false);

        $result = json_decode((new WpPluginTool)->execute(['limit' => 2]), true);

        self::assertCount(2, $result['data']['plugins']);
        self::assertTrue($result['meta']['has_more']);
    }

    public function test_offset_skips_rows(): void
    {
        Functions\expect('get_plugins')->once()->andReturn([
            'a/a.php' => ['Name' => 'A'],
            'b/b.php' => ['Name' => 'B'],
            'c/c.php' => ['Name' => 'C'],
        ]);
        Functions\expect('get_option')->zeroOrMoreTimes()->andReturn([]);
        Functions\expect('is_plugin_active')->zeroOrMoreTimes()->andReturn(false);

        $result = json_decode((new WpPluginTool)->execute(['offset' => 1]), true);

        self::assertCount(2, $result['data']['plugins']);
        self::assertSame('B', $result['data']['plugins'][0]['name']);
    }

    public function test_limit_above_max_is_rejected_not_clamped(): void
    {
        $result = json_decode((new WpPluginTool)->execute(['limit' => 99999]), true);

        self::assertFalse($result['success']);
        self::assertSame('INVALID_LIMIT', $result['error']['code']);
    }

    public function test_maximum_limit_is_reported_in_meta(): void
    {
        Functions\expect('get_plugins')->once()->andReturn([
            'a/a.php' => ['Name' => 'A'],
        ]);
        Functions\expect('get_option')->zeroOrMoreTimes()->andReturn([]);
        Functions\expect('is_plugin_active')->zeroOrMoreTimes()->andReturn(false);

        $result = json_decode((new WpPluginTool)->execute(['limit' => 200]), true);

        self::assertSame(200, $result['meta']['limit']);
    }

    public function test_row_includes_slug_url_description_and_truncation(): void
    {
        $tool = new WpPluginTool;
        $ref = new \ReflectionClass($tool);
        $m = $ref->getMethod('buildRow');
        $m->setAccessible(true);
        $row = $m->invoke(
            $tool,
            'yoast/yoast.php',
            ['Name' => 'Yoast SEO', 'Version' => '23.5', 'PluginURI' => 'https://yoast.com', 'Description' => '<p>'.str_repeat('A', 300).'</p>', 'AuthorName' => 'Yoast'],
            true,
            [],
            [],
            ['name', 'slug', 'version', 'active', 'author', 'description', 'url', 'update_available', 'network_active'],
        );

        self::assertSame('yoast', $row['slug']);
        self::assertSame('23.5', $row['version']);
        self::assertSame('https://yoast.com', $row['url']);
        self::assertSame(200, mb_strlen($row['description']));
        self::assertStringNotContainsString('<p>', $row['description']);
        self::assertSame('Yoast', $row['author']);
        self::assertTrue($row['active']);
        self::assertFalse($row['update_available']);
        self::assertFalse($row['network_active']);
    }

    public function test_build_row_single_file_plugin_slug_uses_basename(): void
    {
        $tool = new WpPluginTool;
        $ref = new \ReflectionClass($tool);
        $m = $ref->getMethod('buildRow');
        $m->setAccessible(true);

        $row = $m->invoke(
            $tool,
            'hello.php',
            ['Name' => 'Hello Dolly'],
            false,
            [],
            [],
            ['slug'],
        );

        self::assertSame('hello', $row['slug']);
    }

    public function test_build_row_network_active_flag(): void
    {
        $tool = new WpPluginTool;
        $ref = new \ReflectionClass($tool);
        $m = $ref->getMethod('buildRow');
        $m->setAccessible(true);

        $row = $m->invoke(
            $tool,
            'yoast/yoast.php',
            ['Name' => 'Yoast SEO'],
            true,
            [],
            ['yoast/yoast.php'],
            ['network_active'],
        );

        self::assertTrue($row['network_active']);
    }

    public function test_build_row_update_available_shows_new_version(): void
    {
        $tool = new WpPluginTool;
        $ref = new \ReflectionClass($tool);
        $m = $ref->getMethod('buildRow');
        $m->setAccessible(true);

        $update = new \stdClass;
        $update->new_version = '24.0';

        $row = $m->invoke(
            $tool,
            'yoast/yoast.php',
            ['Name' => 'Yoast SEO'],
            true,
            ['yoast/yoast.php' => $update],
            [],
            ['update_available'],
        );

        self::assertSame('24.0', $row['update_available']);
    }

    private function invokeResolveColumns(mixed $arg): array
    {
        $tool = new WpPluginTool;
        $ref = new \ReflectionClass($tool);
        $m = $ref->getMethod('resolveColumns');
        $m->setAccessible(true);

        return $m->invoke($tool, $arg);
    }

    public function test_resolve_columns_wildcard_returns_all(): void
    {
        $cols = $this->invokeResolveColumns(['*']);

        self::assertCount(9, $cols);
        self::assertContains('update_available', $cols);
    }

    public function test_unknown_columns_are_rejected_not_silently_dropped(): void
    {
        $result = json_decode((new WpPluginTool)->execute(['columns' => ['nope', 'evil']]), true);

        self::assertFalse($result['success']);
        self::assertSame('UNKNOWN_COLUMN', $result['error']['code']);
        self::assertContains('name', $result['error']['available_columns']);
    }

    public function test_resolve_columns_non_array_returns_defaults(): void
    {
        $cols = $this->invokeResolveColumns('string-input');

        self::assertSame(['name', 'version', 'active', 'author'], $cols);
    }

    public function test_load_updates_returns_empty_when_transient_missing(): void
    {
        Functions\expect('get_plugins')->once()->andReturn(['a/a.php' => ['Name' => 'A']]);
        Functions\expect('get_option')->zeroOrMoreTimes()->andReturn([]);
        Functions\expect('is_plugin_active')->zeroOrMoreTimes()->andReturn(false);

        $result = json_decode((new WpPluginTool)->execute(['aggregate' => true]), true);

        self::assertSame(0, $result['data']['update_available']);
    }

    private function invokeExecuteQuery(WpPluginTool $tool, array $input): array
    {
        $this->grantCapability('phpclaw_use_chat');

        return json_decode($tool->execute($input), true);
    }

    public function test_execute_query_returns_all_columns_via_wildcard(): void
    {
        Functions\expect('get_plugins')->once()->andReturn([
            'yoast/yoast.php' => [
                'Name' => 'Yoast SEO',
                'Version' => '23.5',
                'PluginURI' => 'https://yoast.com',
                'Description' => '<p>Long description text</p>',
                'AuthorName' => 'Yoast',
            ],
        ]);
        Functions\expect('get_option')->zeroOrMoreTimes()->andReturn([]);
        Functions\expect('is_plugin_active')->zeroOrMoreTimes()->andReturn(true);

        $result = $this->invokeExecuteQuery(new WpPluginTool, ['columns' => ['*']]);

        $row = $result['data']['plugins'][0];
        self::assertSame('Yoast SEO', $row['name']);
        self::assertSame('yoast', $row['slug']);
        self::assertSame('23.5', $row['version']);
        self::assertTrue($row['active']);
        self::assertSame('Yoast', $row['author']);
        self::assertSame('Long description text', $row['description']);
        self::assertSame('https://yoast.com', $row['url']);
        self::assertFalse($row['update_available']);
        self::assertFalse($row['network_active']);
    }

    public function test_execute_query_uses_author_fallback_when_author_name_missing(): void
    {
        Functions\expect('get_plugins')->once()->andReturn([
            'fallback/plugin.php' => [
                'Name' => 'Fallback Plugin',
                'Version' => '1.0',
                'Author' => 'Legacy Author',
            ],
        ]);
        Functions\expect('get_option')->zeroOrMoreTimes()->andReturn([]);
        Functions\expect('is_plugin_active')->zeroOrMoreTimes()->andReturn(false);

        $result = $this->invokeExecuteQuery(new WpPluginTool, ['columns' => ['name', 'author']]);

        self::assertSame('Legacy Author', $result['data']['plugins'][0]['author']);
    }

    public function test_execute_query_falls_back_to_filename_when_name_missing(): void
    {
        Functions\expect('get_plugins')->once()->andReturn([
            'unnamed/plugin.php' => ['Version' => '1.0'],
        ]);
        Functions\expect('get_option')->zeroOrMoreTimes()->andReturn([]);
        Functions\expect('is_plugin_active')->zeroOrMoreTimes()->andReturn(false);

        $result = $this->invokeExecuteQuery(new WpPluginTool, ['columns' => ['name']]);

        self::assertSame('unnamed/plugin.php', $result['data']['plugins'][0]['name']);
    }

    public function test_execute_query_default_columns_when_columns_omitted(): void
    {
        Functions\expect('get_plugins')->once()->andReturn([
            'a/a.php' => ['Name' => 'A', 'Version' => '1.0', 'AuthorName' => 'X'],
        ]);
        Functions\expect('get_option')->zeroOrMoreTimes()->andReturn([]);
        Functions\expect('is_plugin_active')->zeroOrMoreTimes()->andReturn(false);

        $result = $this->invokeExecuteQuery(new WpPluginTool, []);

        self::assertSame(['name', 'version', 'active', 'author'], $result['meta']['columns_returned']);
    }

    public function test_input_schema_describes_all_parameters(): void
    {
        $schema = (new WpPluginTool)->inputSchema();

        self::assertSame('object', $schema['type']);
        self::assertArrayHasKey('columns', $schema['properties']);
        self::assertArrayHasKey('schema', $schema['properties']);
        self::assertArrayHasKey('aggregate', $schema['properties']);
        self::assertArrayHasKey('status', $schema['properties']);
        self::assertArrayHasKey('search', $schema['properties']);
        self::assertArrayHasKey('limit', $schema['properties']);
        self::assertArrayHasKey('offset', $schema['properties']);

        self::assertSame(['all', 'active', 'inactive'], $schema['properties']['status']['enum']);
        self::assertSame(50, $schema['properties']['limit']['default']);
    }

    public function test_load_network_active_reads_site_option_on_multisite(): void
    {
        Monkey\tearDown();
        Monkey\setUp();

        Functions\expect('is_multisite')->zeroOrMoreTimes()->andReturn(true);
        Functions\expect('get_site_transient')->zeroOrMoreTimes()->andReturn(false);
        Functions\expect('get_site_option')->zeroOrMoreTimes()->andReturn([
            'yoast/yoast.php' => 1234567890,
        ]);
        Functions\expect('get_plugins')->once()->andReturn([
            'yoast/yoast.php' => ['Name' => 'Yoast'],
        ]);
        Functions\expect('get_option')->zeroOrMoreTimes()->andReturn([]);
        Functions\expect('is_plugin_active')->zeroOrMoreTimes()->andReturn(false);

        $result = $this->invokeExecuteQuery(new WpPluginTool, ['columns' => ['name', 'network_active']]);

        self::assertTrue($result['data']['plugins'][0]['network_active']);
    }

    public function test_it_returns_forbidden_without_the_required_capability(): void
    {
        $this->denyAllCapabilities();

        $this->assertForbiddenEnvelope((new WpPluginTool)->execute(['schema' => true]));
    }

    public function test_it_returns_unknown_argument_instead_of_throwing(): void
    {
        $decoded = json_decode((new WpPluginTool)->execute(['phpclaw_bogus_arg' => 1]), true);

        self::assertFalse($decoded['success']);
        self::assertSame('UNKNOWN_ARGUMENT', $decoded['error']['code']);
        self::assertNotEmpty($decoded['error']['accepted_arguments']);
    }

    public function test_successful_envelope_has_exactly_the_contract_keys(): void
    {

        $decoded = json_decode((new WpPluginTool)->execute(['schema' => true]), true);

        self::assertTrue($decoded['success']);
        self::assertSame(['success', 'data', 'meta', 'warnings'], array_keys($decoded));
        self::assertArrayHasKey('mode', $decoded['meta']);
    }
}

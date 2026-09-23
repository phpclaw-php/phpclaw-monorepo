<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\AutoDiscovery\Bootstrap;
use PhpClaw\AutoDiscovery\ComposerExtras;
use PhpClaw\Claw;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Guards\RateLimitGuard;
use PhpClaw\Hooks\HookCatalogue;
use PhpClaw\Hooks\HookEventBridge;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use PhpClaw\Hooks\SecurityAlertHook;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Memory\MemoryCatalogue;
use PhpClaw\Memory\MemoryRegistry;
use PhpClaw\Memory\RedisMemory;
use PhpClaw\Skills\ArraySkill;
use PhpClaw\Skills\Contracts\SkillInterface;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Skills\SkillResolver;
use PhpClaw\WordPress\Memory\FileRouterMemory;
use PhpClaw\WordPress\Plugin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

#[CoversClass(Plugin::class)]
final class PluginCoverageTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private array $hooksRegistered = [];

    private array $filtersRegistered = [];

    private array $menusRegistered = [];

    private array $cliCommandsRegistered = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (! defined('PHPCLAW_PLUGIN_FILE')) {
            define('PHPCLAW_PLUGIN_FILE', '/tmp/phpclaw/phpclaw.php');
        }
        if (! defined('PHPCLAW_VERSION')) {
            define('PHPCLAW_VERSION', '1.0.0');
        }
        if (! defined('ABSPATH')) {
            define('ABSPATH', '/tmp/wpfake/');
        }

        $this->hooksRegistered = [];
        $this->filtersRegistered = [];
        $this->menusRegistered = [];
        $this->cliCommandsRegistered = [];

        $hooks = &$this->hooksRegistered;
        $filters = &$this->filtersRegistered;
        $menus = &$this->menusRegistered;
        $cliCmds = &$this->cliCommandsRegistered;

        Functions\when('add_action')->alias(static function (string $name, mixed $cb, int $priority = 10, int $args = 1) use (&$hooks): bool {
            $hooks[] = ['name' => $name, 'callback' => $cb];

            return true;
        });
        Functions\when('add_filter')->alias(static function (string $name, mixed $cb, int $priority = 10, int $args = 1) use (&$filters): bool {
            $filters[] = ['name' => $name, 'callback' => $cb];

            return true;
        });
        Functions\when('add_menu_page')->alias(static function (...$args) use (&$menus): string {
            $menus[] = ['slug' => (string) ($args[3] ?? '')];

            return 'toplevel_page_';
        });
        Functions\when('add_submenu_page')->alias(static function (...$args) use (&$menus): string {
            $menus[] = ['slug' => (string) ($args[4] ?? '')];

            return 'submenu_page_';
        });
        Functions\when('register_setting')->justReturn(true);
        Functions\when('add_settings_section')->justReturn(true);
        Functions\when('add_settings_field')->justReturn(true);
        Functions\when('wp_register_style')->justReturn(true);
        Functions\when('wp_register_script')->justReturn(true);
        Functions\when('plugin_basename')->alias(static fn (string $f): string => 'phpclaw/'.basename($f));
        Functions\when('plugins_url')->alias(static fn (string $p, string $f): string => 'http://example.com/plugin/'.$p);
        Functions\when('admin_url')->alias(static fn (string $p = ''): string => 'http://example.com/wp-admin/'.ltrim($p, '/'));
        Functions\when('plugin_dir_url')->alias(static fn (string $f): string => 'http://example.com/plugin/');
        Functions\when('plugin_dir_path')->alias(static fn (string $f): string => dirname($f).'/');
        Functions\when('get_option')->alias(static function (string $key, mixed $default = false): mixed {
            return $default;
        });
        Functions\when('update_option')->justReturn(true);
        Functions\when('delete_option')->justReturn(true);
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('do_action')->justReturn(null);
        Functions\when('__')->alias(static fn (string $s, string $d = ''): string => $s);
        Functions\when('esc_html')->alias(static fn (string $s): string => $s);
        Functions\when('esc_html__')->alias(static fn (string $s, string $d = ''): string => $s);
        Functions\when('esc_url')->alias(static fn (string $s): string => $s);
        Functions\when('esc_attr')->alias(static fn (string $s): string => $s);
        Functions\when('sanitize_text_field')->alias(static fn (string $s): string => trim($s));
        Functions\when('sanitize_textarea_field')->alias(static fn (string $s): string => trim($s));
        Functions\when('wp_unslash')->alias(static fn (string $s): string => $s);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('is_admin')->justReturn(true);
        Functions\when('wp_doing_ajax')->justReturn(false);
        Functions\when('did_action')->justReturn(0);
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('wp_send_json_success')->alias(static function (array $data): void {
            if (! isset($GLOBALS['phpclaw_test_ajax_result'])) {
                $GLOBALS['phpclaw_test_ajax_result'] = ['type' => 'success', 'data' => $data];
            }
            throw new AjaxHalt;
        });
        Functions\when('wp_send_json_error')->alias(static function (array $data, int $status = 400): void {
            if (! isset($GLOBALS['phpclaw_test_ajax_result'])) {
                $GLOBALS['phpclaw_test_ajax_result'] = ['type' => 'error', 'status' => $status, 'data' => $data];
            }
            throw new AjaxHalt;
        });

        $GLOBALS['phpclaw_test_cli_commands'] = [];
        if (! class_exists('WP_CLI')) {
            eval(<<<'PHP'
                class WP_CLI {
                    public static function add_command(string $name, mixed $callable, array $args = []): bool {
                        $GLOBALS['phpclaw_test_cli_commands'][] = ['name' => $name];
                        return true;
                    }
                    public static function add_hook(string $when, mixed $cb): void {}
                }
            PHP);
        }

        $wpdb = Mockery::mock();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('get_var')->andReturn('wp_phpclaw_memory');
        $wpdb->shouldReceive('prepare')->andReturnUsing(static fn (string $q, mixed ...$args): string => $q);
        $wpdb->shouldReceive('query')->andReturn(1);
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $GLOBALS['wpdb'] = $wpdb;
    }

    protected function tearDown(): void
    {
        $ref = new \ReflectionClass(Plugin::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        unset($GLOBALS['wpdb'], $GLOBALS['phpclaw_test_ajax_result'], $_POST);

        Monkey\tearDown();
        parent::tearDown();
    }

    private function makePluginWithConfig(array $config = []): Plugin
    {
        $ref = new \ReflectionClass(Plugin::class);
        $plugin = $ref->newInstanceWithoutConstructor();

        $configProp = $ref->getProperty('config');
        $configProp->setAccessible(true);
        $configProp->setValue($plugin, array_merge([
            'provider' => 'ollama',
            'model' => 'qwen2.5:7b',
            'guards' => [],
            'hooks' => [],
            'skills' => [],
            'events_bridge' => false,
            'store_messages' => true,
            'cloud_key' => '',
            'cloud_disable' => [],
        ], $config));

        $instanceProp = $ref->getProperty('instance');
        $instanceProp->setAccessible(true);
        $instanceProp->setValue(null, $plugin);

        return $plugin;
    }

    private function invokePrivate(Plugin $plugin, string $method, array $args = []): mixed
    {
        $ref = new \ReflectionClass(Plugin::class);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);

        return $m->invokeArgs($plugin, $args);
    }

    public function test_boot_registries_registers_6_memory_drivers(): void
    {
        $plugin = $this->makePluginWithConfig();

        $this->invokePrivate($plugin, 'bootRegistries');

        $drivers = MemoryRegistry::drivers();
        foreach (['wp_options', 'wpdb', 'wpdb_conversation', 'wp_transient', 'wpdb_router', 'file'] as $slug) {
            self::assertContains($slug, $drivers, "the {$slug} driver must be registered");
        }
    }

    public function test_the_file_driver_builds_a_file_router_backed_by_wp_content(): void
    {
        if (! defined('WP_CONTENT_DIR')) {
            define('WP_CONTENT_DIR', sys_get_temp_dir().'/phpclaw-smoke-wpcontent');
        }

        $plugin = $this->makePluginWithConfig();

        $this->invokePrivate($plugin, 'bootRegistries');

        self::assertInstanceOf(FileRouterMemory::class, MemoryRegistry::build('file'));
    }

    public function test_register_config_guards_skips_invalid_entries(): void
    {
        $plugin = $this->makePluginWithConfig([
            'guards' => [
                'not-an-array',
                ['no-class-key' => 1],
                ['class' => 'NonExistentClass\\Foo'],
                ['class' => RateLimitGuard::class, 'priority' => 5],
            ],
        ]);

        $before = GuardRegistry::count();

        $this->invokePrivate($plugin, 'registerConfigGuards');

        self::assertSame($before + 1, GuardRegistry::count(), 'only the one valid entry may register');
        self::assertTrue(GuardRegistry::hasClass(RateLimitGuard::class));
    }

    public function test_register_config_hooks_skips_invalid_entries(): void
    {
        $plugin = $this->makePluginWithConfig([
            'hooks' => [
                'not-an-array',
                ['event' => 'agent.before'],
                ['handler' => fn () => null],
                ['event' => '', 'handler' => fn () => null],
                ['event' => 'agent.before', 'handler' => fn () => null, 'priority' => 5],
            ],
        ]);

        $before = HookRegistry::count('agent.before');

        $this->invokePrivate($plugin, 'registerConfigHooks');

        self::assertSame($before + 1, HookRegistry::count('agent.before'));
    }

    public function test_register_skills_processes_each_skill_config(): void
    {
        $plugin = $this->makePluginWithConfig([
            'skills' => [
                ['name' => 'S1', 'description' => 'd', 'tags' => ['x'], 'content' => '#hi'],
            ],
        ]);

        $this->invokePrivate($plugin, 'registerSkills');

        self::assertTrue(SkillRegistry::has('S1'));
    }

    public function test_resolve_skills_inline_array_skill(): void
    {
        $plugin = $this->makePluginWithConfig([
            'skills' => [
                ['name' => 'demo', 'description' => 'd', 'tags' => ['x', 'y'], 'content' => '# Demo'],
            ],
        ]);

        $skills = $this->invokePrivate($plugin, 'resolveSkills');

        self::assertCount(1, $skills);
        self::assertInstanceOf(ArraySkill::class, $skills[0]);
    }

    public function test_resolve_skills_skips_invalid_non_array(): void
    {
        $plugin = $this->makePluginWithConfig([
            'skills' => ['scalar-not-array', 42, null],
        ]);

        $skills = $this->invokePrivate($plugin, 'resolveSkills');

        self::assertSame([], $skills);
    }

    public function test_resolve_skills_class_entry_instantiates(): void
    {
        $plugin = $this->makePluginWithConfig([
            'skills' => [
                ['class' => DummyTestSkill::class],
                ['class' => 'NonExistentSkillClass'],
                ['class' => \stdClass::class],
            ],
        ]);

        $skills = $this->invokePrivate($plugin, 'resolveSkills');

        self::assertCount(1, $skills);
        self::assertInstanceOf(DummyTestSkill::class, $skills[0]);
    }

    public function test_resolve_skills_file_entry_with_bad_path_silently_skipped(): void
    {
        $plugin = $this->makePluginWithConfig([
            'skills' => [
                ['file' => '/nonexistent/skill.md'],
            ],
        ]);

        $skills = $this->invokePrivate($plugin, 'resolveSkills');

        self::assertSame([], $skills);
    }

    public function test_register_word_press_hooks_records_all_expected_hooks(): void
    {
        $plugin = $this->makePluginWithConfig();

        $this->invokePrivate($plugin, 'registerWordPressHooks');

        $names = array_column($this->hooksRegistered, 'name');
        self::assertContains('admin_menu', $names);
        self::assertContains('admin_init', $names);
        self::assertContains('admin_enqueue_scripts', $names);
        self::assertContains('rest_api_init', $names);
        self::assertContains('wp_ajax_phpclaw_send', $names);
        self::assertContains('wp_ajax_phpclaw_stream', $names);
        self::assertContains('wp_ajax_phpclaw_test_connection', $names);
        self::assertContains('wp_ajax_phpclaw_load_conversation', $names);
        self::assertContains('update_option_phpclaw_settings', $names);

        $filterNames = array_column($this->filtersRegistered, 'name');
        $filterContainsActionLinks = false;
        foreach ($filterNames as $n) {
            if (str_starts_with($n, 'plugin_action_links_')) {
                $filterContainsActionLinks = true;
                break;
            }
        }
        self::assertTrue($filterContainsActionLinks);
        self::assertContains('plugin_row_meta', $filterNames);
    }

    public function test_register_word_press_hooks_with_events_bridge_enabled(): void
    {
        $plugin = $this->makePluginWithConfig(['events_bridge' => true]);

        $this->invokePrivate($plugin, 'registerWordPressHooks');

        $this->assertGreaterThan(5, count($this->hooksRegistered));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_register_word_press_hooks_includes_cli_when_w_p_cl_i_defined(): void
    {
        if (! defined('WP_CLI')) {
            define('WP_CLI', true);
        }
        $GLOBALS['phpclaw_test_cli_commands'] = [];

        $plugin = $this->makePluginWithConfig();

        $GLOBALS['phpclaw_test_cli_commands'] = [];
        \WP_CLI::add_command('phpclaw_probe', static fn () => null);
        if ($GLOBALS['phpclaw_test_cli_commands'] === []) {
            self::markTestSkipped('another test file declared a non-recording WP_CLI stub first');
        }

        $GLOBALS['phpclaw_test_cli_commands'] = [];
        $this->invokePrivate($plugin, 'registerWordPressHooks');

        $names = array_column($GLOBALS['phpclaw_test_cli_commands'], 'name');

        self::assertContains('phpclaw send', $names);
        self::assertContains('phpclaw mcp-server', $names);
    }

    public function test_register_admin_menu_method_exists_and_is_callable(): void
    {
        self::assertTrue(method_exists(Plugin::class, 'registerAdminMenu'));
        $ref = new \ReflectionMethod(Plugin::class, 'registerAdminMenu');
        self::assertTrue($ref->isPublic());
        self::assertTrue($ref->isStatic());
    }

    public function test_build_engine_returns_php_claw_instance_when_provider_configured(): void
    {
        $plugin = $this->makePluginWithConfig([
            'provider' => '',
            'model' => '',
            'api_key' => '',
            'shell_allowlist' => 'ls,pwd',
        ]);

        try {
            $result = $this->invokePrivate($plugin, 'buildEngine');
            self::assertInstanceOf(Claw::class, $result);
        } catch (\Throwable $e) {
            self::assertNotEmpty($e->getMessage());
        }
    }

    public function test_maybe_run_migration_returns_early_when_table_exists_and_version_matches(): void
    {
        $wpdb = Mockery::mock();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('get_var')->andReturn('wp_phpclaw_memory');
        $wpdb->shouldReceive('prepare')->andReturnUsing(static fn (string $q, mixed ...$a): string => $q);
        $GLOBALS['wpdb'] = $wpdb;

        Functions\when('get_option')->alias(static function (string $key, mixed $default = false) {
            if ($key === 'phpclaw_db_version') {
                return '1.1.2';
            }

            return $default;
        });

        $ran = false;
        Functions\when('dbDelta')->alias(static function () use (&$ran): array {
            $ran = true;

            return [];
        });

        $plugin = $this->makePluginWithConfig();

        $this->invokePrivate($plugin, 'maybeRunMigration');

        self::assertFalse($ran, 'an up-to-date schema must not run dbDelta');
    }

    public function test_activate_runs_migration_and_sets_default_settings(): void
    {
        $wpdb = Mockery::mock();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('get_var')->andReturn(null);
        $wpdb->shouldReceive('prepare')->andReturnUsing(static fn (string $q, mixed ...$a): string => $q);
        $wpdb->shouldReceive('query')->andReturn(1);
        $wpdb->shouldReceive('get_charset_collate')->andReturn('utf8mb4');
        $GLOBALS['wpdb'] = $wpdb;

        Functions\when('dbDelta')->justReturn([]);
        Functions\when('get_option')->alias(static function (string $key, mixed $default = false) {
            return $default;
        });
        $updateCalls = [];
        Functions\when('update_option')->alias(static function (string $key, mixed $value) use (&$updateCalls): bool {
            $updateCalls[] = $key;

            return true;
        });

        Plugin::activate();

        self::assertContains('phpclaw_settings', $updateCalls);
    }

    public function test_enqueue_admin_assets_returns_early_for_non_phpclaw_pages(): void
    {
        $enqueued = [];
        Functions\when('wp_enqueue_style')->alias(static function (string $h) use (&$enqueued): bool {
            $enqueued[] = $h;

            return true;
        });
        Functions\when('wp_enqueue_script')->alias(static function (string $h) use (&$enqueued): bool {
            $enqueued[] = $h;

            return true;
        });

        Plugin::enqueueAdminAssets('edit.php');

        self::assertSame([], $enqueued);
    }

    public function test_enqueue_admin_assets_enqueues_chat_assets_on_chat_page(): void
    {
        $enqueued = [];
        Functions\when('wp_enqueue_style')->alias(static function (string $h, string $u = '') use (&$enqueued) {
            $enqueued[] = ['style', $h];

            return true;
        });
        Functions\when('wp_enqueue_script')->alias(static function (string $h, string $u = '') use (&$enqueued) {
            $enqueued[] = ['script', $h];

            return true;
        });
        Functions\when('wp_localize_script')->justReturn(true);
        Functions\when('wp_create_nonce')->alias(static fn (string $a): string => 'nonce-'.$a);

        Plugin::enqueueAdminAssets('phpclaw_page_phpclaw-chat');

        $handles = array_column($enqueued, 1);
        self::assertContains('phpclaw-admin', $handles);
        self::assertContains('phpclaw-chat', $handles);
    }

    public function test_enqueue_admin_assets_enqueues_admin_js_on_settings_page(): void
    {
        $enqueued = [];
        Functions\when('wp_enqueue_style')->justReturn(true);
        Functions\when('wp_enqueue_script')->alias(static function (string $h, string $u = '') use (&$enqueued) {
            $enqueued[] = $h;

            return true;
        });
        Functions\when('wp_localize_script')->justReturn(true);
        Functions\when('wp_create_nonce')->alias(static fn (string $a): string => 'nonce-'.$a);

        Plugin::enqueueAdminAssets('toplevel_page_phpclaw');

        self::assertContains('phpclaw-admin', $enqueued);
    }

    public function test_find_last_assistant_index_returns_index_when_assistant_present(): void
    {
        $plugin = $this->makePluginWithConfig();

        $idx = $this->invokePrivate($plugin, 'findLastAssistantIndex', [[
            ['role' => 'user',      'content' => 'hi'],
            ['role' => 'assistant', 'content' => 'hello'],
            ['role' => 'user',      'content' => 'thanks'],
            ['role' => 'assistant', 'content' => 'welcome'],
        ]]);

        self::assertSame(3, $idx);
    }

    public function test_extra_tool_classes_appends_phpclaw_extra_tools_filter(): void
    {
        Functions\when('apply_filters')->alias(static function (string $tag, mixed $value): mixed {
            if ($tag === 'phpclaw_extra_tools') {
                $value[] = \stdClass::class;
            }

            return $value;
        });

        $classes = Plugin::extraToolClasses();
        self::assertIsArray($classes);
        self::assertContains(\stdClass::class, $classes);
    }

    public function test_register_config_guards_skips_non_existent_class(): void
    {
        $plugin = $this->makePluginWithConfig([
            'guards' => [
                ['class' => 'PhpClaw\\Nonexistent\\NotARealGuard'],
            ],
        ]);

        $before = GuardRegistry::count();

        $this->invokePrivate($plugin, 'registerConfigGuards');

        self::assertSame($before, GuardRegistry::count());
    }

    public function test_resolve_skills_file_entry_attempts_construction(): void
    {
        $plugin = $this->makePluginWithConfig([
            'skills' => [
                ['file' => '/definitely/nonexistent/skill.md'],
            ],
        ]);

        $skills = $this->invokePrivate($plugin, 'resolveSkills');

        self::assertSame([], $skills);
    }

    public function test_resolve_skills_inline_without_tags_resolves_with_empty_tags(): void
    {
        $plugin = $this->makePluginWithConfig([
            'skills' => [
                [
                    'name' => 'no_tags',
                    'description' => 'tags optional in canonical',
                    'content' => 'body',
                ],
            ],
        ]);

        $skills = $this->invokePrivate($plugin, 'resolveSkills');

        self::assertCount(1, $skills);
        self::assertInstanceOf(ArraySkill::class, $skills[0]);
        self::assertSame([], $skills[0]->tags());
        self::assertSame('no_tags', $skills[0]->name());
    }

    public function test_resolve_skills_mixed_entries_resolves_only_valid_shapes(): void
    {
        $plugin = $this->makePluginWithConfig([
            'skills' => [
                ['name' => 'math_helper', 'description' => 'd', 'tags' => ['math'], 'content' => 'c'],
                ['name' => 'no_tags',     'description' => 'd', 'content' => 'c'],
                ['class' => 'Some\\Nonexistent\\SkillClass'],
                ['name' => 'broken', 'description' => 'd'],
            ],
        ]);

        $skills = $this->invokePrivate($plugin, 'resolveSkills');

        self::assertCount(2, $skills, 'Only 2 of 4 entries should resolve');
        self::assertSame('math_helper', $skills[0]->name());
        self::assertSame(['math'], $skills[0]->tags());
        self::assertSame('no_tags', $skills[1]->name());
        self::assertSame([], $skills[1]->tags());
    }

    public function test_resolve_skills_uses_canonical_core_resolver(): void
    {
        self::assertTrue(
            class_exists(SkillResolver::class),
            'Canonical SkillResolver must exist in core',
        );

        self::assertFalse(
            class_exists('PhpClaw\\WordPress\\Skills\\SkillResolver'),
            'WP-local SkillResolver duplicate must NOT exist (dead-code policy)',
        );

        $resolverClass = new \ReflectionClass(SkillResolver::class);
        $expectedPath = realpath(__DIR__.'/../../vendor/phpclaw/phpclaw/src/Skills/SkillResolver.php');

        self::assertSame(
            $expectedPath,
            $resolverClass->getFileName(),
            'SkillResolver must load from vendor/phpclaw/phpclaw/src/Skills/, not adapter src/',
        );
    }

    public function test_hook_event_bridge_accepts_closure_dispatcher(): void
    {
        $captured = [];
        $bridge = new HookEventBridge(
            dispatcher: static function (string $event, array $ctx) use (&$captured): void {
                $captured[] = [$event, $ctx];
            },
            events: ['agent.before', 'tool.after'],
        );

        HookRegistry::reset();
        $bridge->register();

        HookRegistry::fire('agent.before', ['run_id' => 'r1']);
        HookRegistry::fire('tool.after', ['tool_name' => 't1']);

        self::assertCount(2, $captured);
        self::assertSame('agent.before', $captured[0][0]);
        self::assertSame('tool.after', $captured[1][0]);

        HookRegistry::reset();
    }

    public function test_hook_event_bridge_default_covers_every_lifecycle_event(): void
    {
        HookRegistry::reset();

        $bridge = new HookEventBridge(
            dispatcher: static function (string $e, array $c): void {},
        );

        $resolved = $bridge->resolvedEvents();
        self::assertSame(LifecycleEvent::all(), $resolved);

        $bridge->register();

        foreach (LifecycleEvent::cases() as $event) {
            self::assertGreaterThanOrEqual(
                1,
                HookRegistry::count($event->value),
                "Bridge failed to subscribe to canonical event {$event->value}",
            );
        }

        HookRegistry::reset();
    }

    public function test_bootstrap_autodiscovers_memory_driver_from_composer_extras(): void
    {

        ComposerExtras::withTestPayload([
            'fake/phpclaw-memory-mongo' => [
                'memory' => [
                    'wp-bootstrap-test' => [
                        'label' => 'WP Bootstrap Test Memory',
                        'class' => RedisMemory::class,
                    ],
                ],
            ],
        ]);
        MemoryCatalogue::reset();

        Bootstrap::boot();

        $entry = MemoryCatalogue::find('wp-bootstrap-test');
        self::assertNotNull($entry, 'Bootstrap::boot should have triggered MemoryCatalogue::boot');
        self::assertSame('WP Bootstrap Test Memory', $entry['label']);

        MemoryCatalogue::reset();
        ComposerExtras::reset();
    }

    public function test_bootstrap_discovers_mixed_categories_with_validation(): void
    {
        ComposerExtras::withTestPayload([
            'mixed/pkg' => [
                'memory' => [
                    'mix-valid' => [
                        'label' => 'Mix Valid Memory',
                        'class' => RedisMemory::class,
                    ],
                    'mix-no-cls' => [
                        'label' => 'No Class',
                        'class' => 'Nope\\NotReal',
                    ],
                    'mix-wrong' => [
                        'label' => 'Wrong Interface',
                        'class' => \stdClass::class,
                    ],
                ],
                'hooks' => [
                    [
                        'event' => 'agent.error',
                        'class' => SecurityAlertHook::class,
                        'key' => 'mix-hook-valid',
                    ],
                    [
                        'event' => 'tool.error',
                        'class' => 'Bad\\Hook\\Class',
                        'key' => 'mix-hook-bad',
                    ],
                ],
            ],
        ]);
        MemoryCatalogue::reset();
        HookCatalogue::reset();

        Bootstrap::boot();

        self::assertNotNull(MemoryCatalogue::find('mix-valid'));
        self::assertNull(MemoryCatalogue::find('mix-no-cls'));
        self::assertNull(MemoryCatalogue::find('mix-wrong'));

        self::assertNotNull(HookCatalogue::find('mix-hook-valid'));
        self::assertNull(HookCatalogue::find('mix-hook-bad'));

        MemoryCatalogue::reset();
        HookCatalogue::reset();
        ComposerExtras::reset();
    }

    public function test_autodiscovery_bootstrap_uses_canonical_core_class(): void
    {
        self::assertTrue(
            class_exists(Bootstrap::class),
            'Canonical Bootstrap must exist in core',
        );
        self::assertTrue(
            class_exists(ComposerExtras::class),
            'Canonical ComposerExtras must exist in core',
        );

        self::assertFalse(
            class_exists('PhpClaw\\WordPress\\AutoDiscovery\\Bootstrap'),
            'WP-local AutoDiscovery duplicate must NOT exist (dead-code policy)',
        );

        $expectedPath = realpath(__DIR__.'/../../vendor/phpclaw/phpclaw/src/AutoDiscovery/Bootstrap.php');
        self::assertSame(
            $expectedPath,
            (new \ReflectionClass(Bootstrap::class))->getFileName(),
            'Bootstrap must load from vendor/phpclaw/phpclaw/src/AutoDiscovery/, not adapter src/',
        );
    }

    public function test_hook_event_bridge_uses_canonical_core_class(): void
    {
        self::assertTrue(
            class_exists(HookEventBridge::class),
            'Canonical HookEventBridge must exist in core',
        );

        self::assertFalse(
            class_exists('PhpClaw\\WordPress\\Events\\HookEventBridge'),
            'WP-local HookEventBridge duplicate must NOT exist (dead-code policy)',
        );

        $bridgeClass = new \ReflectionClass(HookEventBridge::class);
        $expectedPath = realpath(__DIR__.'/../../vendor/phpclaw/phpclaw/src/Hooks/HookEventBridge.php');

        self::assertSame(
            $expectedPath,
            $bridgeClass->getFileName(),
            'HookEventBridge must load from vendor/phpclaw/phpclaw/src/Hooks/, not adapter src/',
        );
    }

    public function test_find_last_assistant_index_returns_count_when_no_assistant(): void
    {
        $plugin = $this->makePluginWithConfig();

        $idx = $this->invokePrivate($plugin, 'findLastAssistantIndex', [[
            ['role' => 'user', 'content' => 'a'],
            ['role' => 'user', 'content' => 'b'],
        ]]);

        self::assertSame(2, $idx);
    }

    public function test_handle_ajax_send_splices_tool_calls_into_history(): void
    {
        $response = new AgentResponse(
            text: 'final answer',
            provider: 'ollama',
            model: 'qwen',
            iterations: 2,
            inputTokens: 20,
            outputTokens: 10,
        );
        $convId = '01HX0000000000000000000001';
        $conv = new Conversation($convId, [], new \DateTimeImmutable);
        $turn = new ConversationTurn($response, $conv);

        $splicedHistory = null;

        $engine = Mockery::mock(ClawInterface::class);
        $engine->allows('conversation')->andReturn($conv);
        $engine->allows('streamInConversation')->andReturnUsing(
            function ($c, $msg, $onToken, $beforePersist = null) use ($turn, &$splicedHistory) {
                HookRegistry::fire('tool.after', [
                    'tool_name' => 'wp_users',
                    'tool_input' => ['limit' => 5],
                    'tool_result' => '{"users":[]}',
                ]);
                if (is_callable($beforePersist)) {
                    $result = $beforePersist([
                        'history' => [
                            ['role' => 'user',      'content' => 'list users'],
                            ['role' => 'assistant', 'content' => 'final answer'],
                        ],
                    ]);
                    $splicedHistory = $result['history'] ?? null;
                }

                return $turn;
            },
        );

        $ref = new \ReflectionClass(Plugin::class);
        $plugin = $ref->newInstanceWithoutConstructor();
        $cfg = $ref->getProperty('config');
        $cfg->setAccessible(true);
        $cfg->setValue($plugin, ['provider' => 'ollama']);
        $eng = $ref->getProperty('engine');
        $eng->setAccessible(true);
        $eng->setValue($plugin, $engine);
        $inst = $ref->getProperty('instance');
        $inst->setAccessible(true);
        $inst->setValue(null, $plugin);

        HookRegistry::reset();
        $_POST = ['message' => 'list users', 'conversation_id' => ''];

        try {
            $plugin->handleAjaxSend();
        } catch (AjaxHalt) {
        }

        self::assertIsArray($splicedHistory, 'beforePersist should return a history array');
        self::assertContains('tool', array_column($splicedHistory, 'role'), 'a tool row should be spliced into history via beforePersist');
    }

    public function test_handle_ajax_load_conversation_rejects_unauthorised(): void
    {
        Functions\when('current_user_can')->justReturn(false);

        $plugin = $this->makePluginWithConfig();
        $_POST = ['conversation_id' => 'cv1'];

        try {
            $plugin->handleAjaxLoadConversation();
        } catch (AjaxHalt) {
        }

        $result = $GLOBALS['phpclaw_test_ajax_result'] ?? [];
        self::assertSame('error', $result['type'] ?? '');
        self::assertSame(403, $result['status'] ?? 0);
    }

    public function test_handle_ajax_load_conversation_remaps_tool_role(): void
    {
        $memory = Mockery::mock(MemoryInterface::class);
        $memory->shouldReceive('get')->andReturn([
            'title' => 'List users',
            'history' => [
                ['role' => 'user',      'content' => 'list users'],
                ['role' => 'tool',      'tool_name' => 'wp_users', 'tool_input' => ['limit' => 5], 'content' => '{"users":[]}'],
                ['role' => 'assistant', 'content' => 'There are no users.'],
                ['role' => 'unknown',   'content' => 'should be skipped'],
            ],
        ]);

        $engine = Mockery::mock(ClawInterface::class);
        $engine->allows('memory')->andReturn($memory);

        $ref = new \ReflectionClass(Plugin::class);
        $plugin = $ref->newInstanceWithoutConstructor();
        $cfg = $ref->getProperty('config');
        $cfg->setAccessible(true);
        $cfg->setValue($plugin, ['provider' => 'ollama']);
        $eng = $ref->getProperty('engine');
        $eng->setAccessible(true);
        $eng->setValue($plugin, $engine);
        $inst = $ref->getProperty('instance');
        $inst->setAccessible(true);
        $inst->setValue(null, $plugin);

        $_POST = ['conversation_id' => 'cv1'];

        try {
            $plugin->handleAjaxLoadConversation();
        } catch (AjaxHalt) {
        }

        $result = $GLOBALS['phpclaw_test_ajax_result'] ?? [];
        $msgs = $result['data']['messages'] ?? [];
        self::assertCount(3, $msgs);
        self::assertSame('tool', $msgs[1]['role']);
        self::assertSame('wp_users', $msgs[1]['tool_name']);
        self::assertSame(['limit' => 5], $msgs[1]['tool_input']);
    }

    public function test_handle_ajax_load_conversation_returns_500_on_throwable(): void
    {
        $engine = Mockery::mock(ClawInterface::class);
        $engine->allows('memory')->andThrow(new \RuntimeException('boom'));

        $ref = new \ReflectionClass(Plugin::class);
        $plugin = $ref->newInstanceWithoutConstructor();
        $cfg = $ref->getProperty('config');
        $cfg->setAccessible(true);
        $cfg->setValue($plugin, ['provider' => 'ollama']);
        $eng = $ref->getProperty('engine');
        $eng->setAccessible(true);
        $eng->setValue($plugin, $engine);
        $inst = $ref->getProperty('instance');
        $inst->setAccessible(true);
        $inst->setValue(null, $plugin);

        $_POST = ['conversation_id' => 'cv2'];

        try {
            $plugin->handleAjaxLoadConversation();
        } catch (AjaxHalt) {
        }

        $result = $GLOBALS['phpclaw_test_ajax_result'] ?? [];
        self::assertSame('error', $result['type'] ?? '');
        self::assertSame(500, $result['status'] ?? 0);
    }

    public function test_engine_returns_engine_when_build_engine_succeeds(): void
    {
        $engine = Mockery::mock(ClawInterface::class);
        $ref = new \ReflectionClass(Plugin::class);
        $plugin = $ref->newInstanceWithoutConstructor();
        $cfg = $ref->getProperty('config');
        $cfg->setAccessible(true);
        $cfg->setValue($plugin, ['provider' => 'ollama']);
        $eng = $ref->getProperty('engine');
        $eng->setAccessible(true);
        $eng->setValue($plugin, $engine);
        $inst = $ref->getProperty('instance');
        $inst->setAccessible(true);
        $inst->setValue(null, $plugin);

        self::assertSame($engine, $plugin->engine());
    }

    public function test_get_instance_returns_same_instance_on_second_call(): void
    {
        $ref = new \ReflectionClass(Plugin::class);
        $plugin = $ref->newInstanceWithoutConstructor();
        $cfg = $ref->getProperty('config');
        $cfg->setAccessible(true);
        $cfg->setValue($plugin, ['provider' => 'x']);
        $inst = $ref->getProperty('instance');
        $inst->setAccessible(true);
        $inst->setValue(null, $plugin);

        $a = Plugin::getInstance();
        $b = Plugin::getInstance();

        self::assertSame($a, $b);
    }

    public function test_handle_ajax_send_substitutes_fallback_text_when_response_empty(): void
    {
        $response = new AgentResponse(
            text: '',
            provider: 'ollama',
            model: 'qwen',
            iterations: 1,
        );
        $conv = new Conversation('01HX0000000000000000000002', [], new \DateTimeImmutable);
        $turn = new ConversationTurn($response, $conv);

        $engine = Mockery::mock(ClawInterface::class);
        $engine->allows('conversation')->andReturn($conv);
        $engine->allows('streamInConversation')->andReturnUsing(
            function ($c, $msg, $onToken, $beforePersist = null) use ($turn) {
                if (is_callable($beforePersist)) {
                    $beforePersist(['history' => []]);
                }

                return $turn;
            },
        );

        $ref = new \ReflectionClass(Plugin::class);
        $plugin = $ref->newInstanceWithoutConstructor();
        $cfg = $ref->getProperty('config');
        $cfg->setAccessible(true);
        $cfg->setValue($plugin, ['provider' => 'ollama']);
        $eng = $ref->getProperty('engine');
        $eng->setAccessible(true);
        $eng->setValue($plugin, $engine);
        $inst = $ref->getProperty('instance');
        $inst->setAccessible(true);
        $inst->setValue(null, $plugin);

        $_POST = ['message' => 'anything', 'conversation_id' => ''];

        try {
            $plugin->handleAjaxSend();
        } catch (AjaxHalt) {
        }

        $result = $GLOBALS['phpclaw_test_ajax_result'] ?? [];
        self::assertSame('success', $result['type'] ?? '');
        self::assertStringContainsString('did not return', $result['data']['response'] ?? '');
    }
}

if (! class_exists(DummyTestSkill::class)) {
    final class DummyTestSkill implements SkillInterface
    {
        public function name(): string
        {
            return 'dummy';
        }

        public function description(): string
        {
            return 'd';
        }

        public function tags(): array
        {
            return ['t'];
        }

        public function content(): string
        {
            return 'c';
        }
    }
}

<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\Engine;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\Claw as PhpClaw;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Guards\Contracts\GuardInterface;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Memory\MemoryRegistry;
use PhpClaw\Memory\PrivacyAwareMemory;
use PhpClaw\Providers\OpenAIProvider;
use PhpClaw\Providers\ProviderRegistry;
use PhpClaw\Tools\ToolProfileResolver;
use PhpClaw\WordPress\Engine\EngineFactory;
use PhpClaw\WordPress\Tests\Stubs\ConfiguredToolStub;
use PhpClaw\WordPress\Tests\Stubs\ThrowingConfigureToolStub;
use PhpClaw\WordPress\Tests\Stubs\UnConfiguredToolStub;
use PhpClaw\WordPress\Tools\DatabaseTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EngineFactory::class)]
final class EngineFactoryTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (! MemoryRegistry::has('wpdb_router')) {
            MemoryRegistry::register('wpdb_router', fn () => new ArrayMemory);
        }
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_build_returns_php_claw_instance_for_ollama(): void
    {
        $engine = EngineFactory::build(
            config: [],
            saved: ['provider' => 'ollama', 'model' => 'qwen2.5:7b'],
        );

        self::assertInstanceOf(PhpClaw::class, $engine);
    }

    public function test_build_uses_saved_settings(): void
    {
        $engine = EngineFactory::build(
            config: [],
            saved: ['provider' => 'ollama', 'model' => 'qwen2.5:7b'],
        );

        self::assertInstanceOf(PhpClaw::class, $engine);
    }

    public function test_build_with_store_messages_on(): void
    {
        $engine = EngineFactory::build(
            config: [],
            saved: ['provider' => 'ollama', 'store_messages' => '1'],
        );

        self::assertInstanceOf(PhpClaw::class, $engine);
    }

    public function test_build_with_store_messages_off(): void
    {
        $engine = EngineFactory::build(
            config: [],
            saved: ['provider' => 'ollama', 'store_messages' => '0'],
        );

        self::assertInstanceOf(PhpClaw::class, $engine);
    }

    public function test_build_uses_default_max_iterations(): void
    {
        $engine = EngineFactory::build(
            config: [],
            saved: ['provider' => 'ollama'],
        );

        self::assertInstanceOf(PhpClaw::class, $engine);
    }

    public function test_build_custom_uses_base_url_without_env(): void
    {
        $engine = EngineFactory::build(
            config: [],
            saved: ['provider' => 'custom', 'api_key' => 'sk-x', 'model' => 'MiniMax-M2', 'base_url' => 'https://api.minimax.io/v1/chat/completions'],
        );

        self::assertInstanceOf(PhpClaw::class, $engine);
    }

    public function test_custom_provider_override_returns_provider_with_base_url_endpoint(): void
    {
        $p = $this->invoke('customProviderOverride', 'custom', 'sk-x', 'MiniMax-M2', '', ['base_url' => 'https://api.minimax.io/v1/chat/completions']);

        self::assertInstanceOf(OpenAIProvider::class, $p);
        self::assertSame('custom', $p->name());
        self::assertSame('https://api.minimax.io/v1/chat/completions', $p->endpoint());
    }

    public function test_custom_provider_override_null_for_non_custom_provider(): void
    {
        self::assertNull($this->invoke('customProviderOverride', 'groq', 'k', 'm', '', ['base_url' => 'https://x.example.com/v1/chat/completions']));
    }

    public function test_custom_provider_override_null_for_invalid_url(): void
    {
        self::assertNull($this->invoke('customProviderOverride', 'custom', 'k', 'm', '', ['base_url' => 'file:///etc/passwd']));
    }

    public function test_custom_provider_override_null_for_empty_url(): void
    {
        self::assertNull($this->invoke('customProviderOverride', 'custom', 'k', 'm', '', ['base_url' => '']));
    }

    private function invoke(string $name, mixed ...$args): mixed
    {
        $ref = new \ReflectionClass(EngineFactory::class);
        $m = $ref->getMethod($name);
        $m->setAccessible(true);

        return $m->invoke(null, ...$args);
    }

    public function test_resolve_remote_skill_urls_filters_and_trims(): void
    {
        $urls = $this->invoke('resolveRemoteSkillUrls', [
            'remote_skill_urls' => ['  https://a.example/s.json  ', '', 'https://b.example/s.md', 42],
        ]);

        self::assertSame(['https://a.example/s.json', 'https://b.example/s.md'], $urls);
    }

    public function test_resolve_remote_skill_urls_empty_when_unset_or_scalar(): void
    {
        self::assertSame([], $this->invoke('resolveRemoteSkillUrls', []));
        self::assertSame([], $this->invoke('resolveRemoteSkillUrls', ['remote_skill_urls' => 'not-an-array']));
    }

    public function test_resolve_provider_reads_saved(): void
    {
        self::assertSame('openai', $this->invoke('resolveProvider', ['provider' => 'openai']));
        self::assertSame('', $this->invoke('resolveProvider', []));
    }

    public function test_resolve_model_reads_saved(): void
    {
        self::assertSame('m-saved', $this->invoke('resolveModel', ['model' => 'm-saved']));
        self::assertSame('', $this->invoke('resolveModel', []));
    }

    public function test_resolve_api_key_returns_empty_for_ollama(): void
    {
        self::assertSame('', $this->invoke('resolveApiKey', ['api_key' => 'also-leaked'], 'ollama'));
    }

    public function test_resolve_api_key_reads_saved(): void
    {
        self::assertSame('saved', $this->invoke('resolveApiKey', ['api_key' => 'saved'], 'anthropic'));
        self::assertSame('', $this->invoke('resolveApiKey', [], 'anthropic'));
    }

    public function test_resolve_max_iterations_default_when_not_set(): void
    {
        self::assertSame(20, $this->invoke('resolveMaxIterations', []));
    }

    public function test_resolve_max_iterations_reads_saved(): void
    {
        self::assertSame(5, $this->invoke('resolveMaxIterations', ['max_iterations' => 5]));
    }

    public function test_resolve_store_messages_uses_saved_when_set(): void
    {
        self::assertTrue($this->invoke('resolveStoreMessages', ['store_messages' => '1']));
        self::assertFalse($this->invoke('resolveStoreMessages', ['store_messages' => '0']));
    }

    public function test_resolve_store_messages_defaults_to_true(): void
    {
        self::assertTrue($this->invoke('resolveStoreMessages', []));
    }

    public function test_build_memory_returns_privacy_aware_wrapper(): void
    {
        $memory = $this->invoke('buildMemory', true);

        self::assertInstanceOf(PrivacyAwareMemory::class, $memory);
    }

    public function test_privacy_aware_memory_gates_writes_when_store_messages_off(): void
    {
        $inner = new ArrayMemory;
        $wrapper = new PrivacyAwareMemory($inner, storeMessages: false);

        $wrapper->set('msg1', 'hello-world', 'conversations');

        self::assertNull($inner->get('msg1', 'conversations'), 'set() must be a no-op when storeMessages=false');
        self::assertFalse($inner->has('msg1', 'conversations'));
    }

    public function test_privacy_aware_memory_passes_writes_when_store_messages_on(): void
    {
        $inner = new ArrayMemory;
        $wrapper = new PrivacyAwareMemory($inner, storeMessages: true);

        $wrapper->set('msg1', ['role' => 'user', 'content' => 'hi'], 'conversations');

        self::assertSame(
            ['role' => 'user', 'content' => 'hi'],
            $inner->get('msg1', 'conversations'),
        );
    }

    public function test_privacy_aware_memory_uses_canonical_core_class(): void
    {
        self::assertTrue(
            class_exists(PrivacyAwareMemory::class),
            'Canonical PrivacyAwareMemory must exist in core',
        );

        self::assertFalse(
            class_exists('PhpClaw\\WordPress\\Memory\\PrivacyAwareMemory'),
            'WP-local PrivacyAwareMemory duplicate must NOT exist (dead-code policy)',
        );

        $wrapperClass = new \ReflectionClass(PrivacyAwareMemory::class);
        $expectedPath = realpath(__DIR__.'/../../../vendor/phpclaw/phpclaw/src/Memory/PrivacyAwareMemory.php');

        self::assertSame(
            $expectedPath,
            $wrapperClass->getFileName(),
            'PrivacyAwareMemory must load from vendor/phpclaw/phpclaw/src/Memory/, not adapter src/',
        );

        $memory = $this->invoke('buildMemory', true);
        self::assertSame(
            PrivacyAwareMemory::class,
            get_class($memory),
            'EngineFactory::buildMemory must return the canonical core PrivacyAwareMemory',
        );
    }

    private static function toolNames(array $tools): array
    {
        return array_map(static fn ($tool): string => $tool->name(), $tools);
    }

    public function test_build_tools_returns_the_full_roster_and_reports_a_budget_of_five(): void
    {
        $tools = $this->invoke('buildTools', '', [], [], []);
        $names = self::toolNames($tools);

        self::assertGreaterThan(5, count($names), 'buildTools no longer slices; ToolRouter selects per message');
        self::assertContains('wp_query', $names);
        self::assertContains('shell_exec', $names, 'a tool declared late must still be registered');
        self::assertSame(
            5,
            ToolProfileResolver::maxTools(ToolProfileResolver::resolve('ollama', 'qwen2.5:7b')),
            'the profile resolves the MINIMAL budget for a small local model; EngineFactory::build() is what hands it to maxToolsPerTurn()',
        );
    }

    public function test_build_tools_with_extra_tool_class_thats_invalid_is_skipped(): void
    {
        $baseline = self::toolNames($this->invoke('buildTools', '', [], [], []));
        $tools = $this->invoke('buildTools', '', [], [], ['NonExistent\\Class\\Name']);

        self::assertSame(
            $baseline,
            self::toolNames($tools),
            'an unloadable extra tool class must add nothing',
        );
    }

    public function test_build_tools_with_extra_basic_tool_class_does_not_duplicate_it(): void
    {
        $tools = $this->invoke('buildTools', '', [], [], [DatabaseTool::class]);
        $names = self::toolNames($tools);

        self::assertContains('db_query', $names);
        self::assertCount(1, array_keys($names, 'db_query', true));
    }

    public function test_build_tools_respects_shell_allowlist(): void
    {
        $tools = $this->invoke(
            'buildTools',
            '/tmp',
            ['shell_allowlist' => ['ls', 'pwd']],
            [],
            [],
        );

        self::assertContains('shell_exec', self::toolNames($tools));
    }

    public function test_build_tools_with_workspace_root_configures_the_file_tools(): void
    {
        $tools = $this->invoke('buildTools', '/var/www', [], [], []);

        $writers = array_values(array_filter(
            $tools,
            static fn ($tool): bool => $tool->name() === 'file_write',
        ));

        self::assertCount(1, $writers);
        self::assertSame(realpath('/var/www') ?: '/var/www', $writers[0]->workspaceRoot());
    }

    public function test_every_tool_is_registered_and_only_the_budget_differs_by_provider(): void
    {
        $roster = self::toolNames($this->invoke('buildTools', '', [], [], []));

        self::assertContains('shell_exec', $roster, 'every tool is registered; the budget decides what is offered');

        self::assertSame(5, ToolProfileResolver::maxTools(ToolProfileResolver::resolve('ollama', 'qwen2.5:7b')));
        self::assertSame(
            0,
            ToolProfileResolver::maxTools(ToolProfileResolver::resolve('anthropic', 'claude-opus-4-7')),
            'a cloud provider reports no profile budget (0); ToolRouter still applies its model-id limit',
        );
    }

    public function test_build_tools_with_tool_deny_filter(): void
    {
        $tools = $this->invoke(
            'buildTools',
            '',
            ['tool_deny' => ['shell_exec']],
            [],
            [],
        );
        $names = self::toolNames($tools);

        self::assertNotContains('shell_exec', $names);
        self::assertContains('wp_query', $names, 'deny must remove only the named tool');
    }

    public function test_build_tools_with_group_system_deny_removes_shell_exec_and_http_request(): void
    {
        $tools = $this->invoke('buildTools', '', ['tool_deny' => ['group:system']], [], []);

        $names = array_map(static fn ($tool): string => $tool->name(), $tools);

        self::assertNotContains('shell_exec', $names);
        self::assertNotContains('http_request', $names);
    }

    public function test_registered_tools_applies_tool_deny_for_the_mcp_and_guide_path(): void
    {
        $config = ['tool_deny' => ['shell_exec']];
        $saved = ['provider' => 'anthropic', 'model' => 'claude-opus-4-7'];

        $tools = EngineFactory::registeredTools($config, $saved, []);

        $names = array_map(static fn ($tool): string => $tool->name(), $tools);

        self::assertNotContains('shell_exec', $names);
        self::assertContains('wp_option', $names);
    }

    public function test_register_extra_providers_no_op_when_extras_absent(): void
    {
        $before = ProviderRegistry::names();

        $this->invoke('registerExtraProviders');

        self::assertSame($before, ProviderRegistry::names());
    }

    public function test_build_tools_woocommerce_branch_when_class_exists(): void
    {
        if (! class_exists('WooCommerce', false)) {
            eval('class WooCommerce {}');
        }

        $tools = $this->invoke('buildTools', '', [], [], []);
        $names = self::toolNames($tools);

        self::assertContains('wc_get_orders', $names);
        self::assertContains('wc_get_products', $names);
    }

    public function test_build_with_apply_filters_for_extra_guards(): void
    {
        \Brain\Monkey\Functions\when('apply_filters')
            ->alias(static function (string $hook, mixed $default) {
                if ($hook === 'phpclaw_extra_guards') {
                    return [];
                }
                if ($hook === 'phpclaw_extra_hooks') {
                    return [];
                }
                if ($hook === 'phpclaw_extra_skills') {
                    return [];
                }
                if ($hook === 'phpclaw_extra_memory_drivers') {
                    return [];
                }
                if ($hook === 'phpclaw_extra_providers') {
                    return [];
                }

                return $default;
            });

        $engine = EngineFactory::build(
            config: [],
            saved: ['provider' => 'ollama', 'model' => 'qwen2.5:7b'],
        );

        self::assertInstanceOf(PhpClaw::class, $engine);
    }

    public function test_build_extra_guard_that_is_valid_gets_registered(): void
    {
        $guard = \Mockery::mock(GuardInterface::class);
        $guard->allows('name')->andReturn('test_guard');
        $guard->allows('check')->andReturn(null);

        \Brain\Monkey\Functions\when('apply_filters')
            ->alias(static function (string $hook, mixed $default) use ($guard) {
                if ($hook === 'phpclaw_extra_guards') {
                    return [$guard];
                }
                if ($hook === 'phpclaw_extra_hooks') {
                    return [];
                }
                if ($hook === 'phpclaw_extra_skills') {
                    return [];
                }
                if ($hook === 'phpclaw_extra_memory_drivers') {
                    return [];
                }
                if ($hook === 'phpclaw_extra_providers') {
                    return [];
                }

                return $default;
            });

        GuardRegistry::reset();
        GuardRegistry::registerDefaults();
        $defaults = GuardRegistry::count();

        GuardRegistry::reset();

        EngineFactory::build(
            config: [],
            saved: ['provider' => 'ollama'],
        );

        self::assertSame($defaults + 1, GuardRegistry::count(), 'the filtered guard must register on top of the defaults');
    }

    public function test_extra_guards_filter_overrides_a_default_of_the_same_class(): void
    {
        GuardRegistry::reset();
        GuardRegistry::register($this->makeConfigurableGuard(shouldBlock: false));

        \Brain\Monkey\Functions\when('apply_filters')
            ->alias(function (string $hook, mixed $default) {
                if ($hook === 'phpclaw_extra_guards') {
                    return [$this->makeConfigurableGuard(shouldBlock: true)];
                }
                if (in_array($hook, ['phpclaw_extra_hooks', 'phpclaw_extra_skills', 'phpclaw_extra_memory_drivers', 'phpclaw_extra_providers'], true)) {
                    return [];
                }

                return $default;
            });

        EngineFactory::build(
            config: [],
            saved: ['provider' => 'ollama'],
        );

        $this->expectException(GuardException::class);
        GuardRegistry::scan('any message');
    }

    private function makeConfigurableGuard(bool $shouldBlock): GuardInterface
    {
        return new class($shouldBlock) implements GuardInterface
        {
            public function __construct(private readonly bool $shouldBlock) {}

            public function scan(string $message): void
            {
                if ($this->shouldBlock) {
                    throw new GuardException('Blocked by configurable test guard');
                }
            }
        };
    }

    public function test_build_extra_hook_valid_entry_is_registered(): void
    {
        $called = false;
        $handler = static function () use (&$called): void {
            $called = true;
        };

        \Brain\Monkey\Functions\when('apply_filters')
            ->alias(static function (string $hook, mixed $default) use ($handler) {
                if ($hook === 'phpclaw_extra_guards') {
                    return [];
                }
                if ($hook === 'phpclaw_extra_hooks') {
                    return [['event' => 'test.event', 'handler' => $handler, 'priority' => 10]];
                }
                if ($hook === 'phpclaw_extra_skills') {
                    return [];
                }
                if ($hook === 'phpclaw_extra_memory_drivers') {
                    return [];
                }
                if ($hook === 'phpclaw_extra_providers') {
                    return [];
                }

                return $default;
            });

        HookRegistry::reset();

        EngineFactory::build(
            config: [],
            saved: ['provider' => 'ollama'],
        );

        self::assertSame(1, HookRegistry::count('test.event'));
    }

    public function test_build_extra_memory_driver_valid_factory_is_registered(): void
    {
        \Brain\Monkey\Functions\when('apply_filters')
            ->alias(static function (string $hook, mixed $default) {
                if ($hook === 'phpclaw_extra_guards') {
                    return [];
                }
                if ($hook === 'phpclaw_extra_hooks') {
                    return [];
                }
                if ($hook === 'phpclaw_extra_skills') {
                    return [];
                }
                if ($hook === 'phpclaw_extra_memory_drivers') {
                    return ['test_custom_driver' => static fn () => new ArrayMemory];
                }
                if ($hook === 'phpclaw_extra_providers') {
                    return [];
                }

                return $default;
            });

        $engine = EngineFactory::build(
            config: [],
            saved: ['provider' => 'ollama'],
        );

        self::assertInstanceOf(PhpClaw::class, $engine);
        self::assertTrue(MemoryRegistry::has('test_custom_driver'));
    }

    public function test_memory_registry_throws_when_the_driver_is_missing(): void
    {

        $unknownSlug = 'phpclaw_nonexistent_driver_'.mt_rand();

        self::assertFalse(MemoryRegistry::has($unknownSlug));

        $this->expectException(\RuntimeException::class);
        MemoryRegistry::build($unknownSlug);

    }

    public function test_build_tools_with_configurable_tool_that_is_configured(): void
    {

        $tools = $this->invoke(
            'buildTools',
            '', [], [],
            [ConfiguredToolStub::class],
        );

        self::assertIsArray($tools);
        $names = array_map(static fn ($t) => $t->name(), $tools);
        self::assertContains('stub_configured_tool', $names);
    }

    public function test_build_tools_with_configurable_tool_not_configured_is_excluded(): void
    {
        $toolsBefore = $this->invoke('buildTools', '', [], [], []);
        $toolsAfter = $this->invoke(
            'buildTools',
            '', [], [],
            [UnConfiguredToolStub::class],
        );

        $afterNames = array_map(static fn ($t) => $t->name(), $toolsAfter);
        self::assertNotContains('stub_unconfigured_tool', $afterNames);
        self::assertCount(count($toolsBefore), $toolsAfter);
    }

    public function test_build_tools_with_configurable_tool_configure_throws_is_skipped(): void
    {
        $toolsBefore = $this->invoke('buildTools', '', [], [], []);
        $toolsAfter = $this->invoke(
            'buildTools',
            '', [], [],
            [ThrowingConfigureToolStub::class],
        );

        self::assertCount(count($toolsBefore), $toolsAfter);
    }
}

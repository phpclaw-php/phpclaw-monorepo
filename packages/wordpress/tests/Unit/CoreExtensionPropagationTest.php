<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PhpClaw\AutoDiscovery\Bootstrap;
use PhpClaw\AutoDiscovery\DiscoveryCache;
use PhpClaw\Claw;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Memory\MemoryRegistry;
use PhpClaw\Providers\AnthropicProvider;
use PhpClaw\Providers\GeminiProvider;
use PhpClaw\Providers\OpenAIProvider;
use PhpClaw\Providers\ProviderCatalogue;
use PhpClaw\Skills\PhpBestPracticesSkill;
use PhpClaw\Skills\SkillCatalogue;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Tools\DatabaseQueryTool;
use PhpClaw\Tools\FileReadTool;
use PhpClaw\Tools\FileWriteTool;
use PhpClaw\Tools\HttpTool;
use PhpClaw\Tools\ShellTool;
use PhpClaw\Tools\ToolCatalogue;
use PhpClaw\WordPress\Plugin;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class CoreExtensionPropagationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (! defined('PHPCLAW_PLUGIN_FILE')) {
            define('PHPCLAW_PLUGIN_FILE', '/tmp/phpclaw/phpclaw.php');
        }

        Functions\stubs([
            '__' => static fn (string $s, string $d = ''): string => $s,
            'get_option' => static fn (string $k, mixed $d = false): mixed => is_array($d) ? $d : [],
            'add_option' => static fn (string $k, mixed $v): bool => true,
            'apply_filters' => static fn (string $tag, mixed $value, mixed ...$args): mixed => $value,
            'wp_next_scheduled' => static fn (): bool => false,
            'wp_schedule_event' => static fn (): bool => true,
        ]);

        HookRegistry::reset();
        GuardRegistry::reset();
        SkillRegistry::reset();
        MemoryRegistry::reset();
        DiscoveryCache::reset();
    }

    protected function tearDown(): void
    {
        $ref = new ReflectionClass(Plugin::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        HookRegistry::reset();
        GuardRegistry::reset();
        SkillRegistry::reset();
        MemoryRegistry::reset();
        DiscoveryCache::reset();

        Monkey\tearDown();
        parent::tearDown();
    }

    private function bootPlugin(): Plugin
    {
        $ref = new ReflectionClass(Plugin::class);
        $plugin = $ref->newInstanceWithoutConstructor();

        $configProp = $ref->getProperty('config');
        $configProp->setAccessible(true);
        $configProp->setValue($plugin, require __DIR__.'/../../config/phpclaw.php');

        $boot = $ref->getMethod('bootRegistries');
        $boot->setAccessible(true);
        $boot->invoke($plugin);

        return $plugin;
    }

    public function test_core_tools_propagate_via_bootstrap_instantiate_core_tools(): void
    {
        $this->bootPlugin();

        $coreTools = ToolCatalogue::instantiateDefaults([
            'workspaceRoot' => '/tmp/phpclaw-test',
            'allowlist' => ['ls'],
        ]);

        $classes = array_map(static fn (object $t): string => $t::class, $coreTools);

        $this->assertContains(HttpTool::class, $classes);
        $this->assertContains(FileReadTool::class, $classes);
        $this->assertContains(FileWriteTool::class, $classes);
        $this->assertContains(ShellTool::class, $classes);
    }

    public function test_non_default_tools_propagate_via_tool_catalogue(): void
    {
        $this->bootPlugin();

        $extraToolClasses = Plugin::extraToolClasses();

        $this->assertContains(DatabaseQueryTool::class, $extraToolClasses);
    }

    public function test_native_providers_resolve_via_bootstrap(): void
    {
        $this->bootPlugin();

        $providers = Bootstrap::providers();

        $this->assertSame(AnthropicProvider::class, $providers['anthropic'] ?? null);
        $this->assertSame(GeminiProvider::class, $providers['gemini'] ?? null);
    }

    public function test_openai_compatible_providers_resolve_via_catalogue(): void
    {
        $this->bootPlugin();

        $all = ProviderCatalogue::all();

        $this->assertSame(AnthropicProvider::class, $all['anthropic']['class'] ?? null);
        $this->assertSame(GeminiProvider::class, $all['gemini']['class'] ?? null);

        foreach (['openai', 'groq', 'deepseek', 'mistral', 'ollama', 'custom'] as $slug) {
            $this->assertSame(OpenAIProvider::class, $all[$slug]['class'] ?? null, "slug {$slug}");
        }
    }

    public function test_provider_buildable_via_claw_builder_after_boot(): void
    {
        $this->bootPlugin();

        $claw = Claw::builder()
            ->apiKey('sk-test')
            ->provider('anthropic')
            ->build();

        $this->assertInstanceOf(AnthropicProvider::class, $claw->config()->buildProvider());

        $clawOpenAI = Claw::builder()
            ->apiKey('sk-test')
            ->provider('openai')
            ->build();

        $this->assertInstanceOf(OpenAIProvider::class, $clawOpenAI->config()->buildProvider());
    }

    public function test_all_seven_core_guards_auto_register_on_default(): void
    {
        $this->bootPlugin();

        $this->assertGreaterThanOrEqual(7, GuardRegistry::count());
    }

    public function test_security_alert_hook_registered_for_guard_blocked_event(): void
    {
        $this->bootPlugin();

        $this->assertGreaterThan(
            0,
            HookRegistry::count('guard.blocked'),
            'SecurityAlertHook with enabledByDefault: true should be wired to guard.blocked'
        );
    }

    public function test_core_memory_drivers_registered_in_memory_registry(): void
    {
        $this->bootPlugin();

        $this->assertTrue(MemoryRegistry::has('file'), 'FileMemory driver should propagate');
        $this->assertTrue(MemoryRegistry::has('array'), 'ArrayMemory driver should propagate');
        $this->assertTrue(MemoryRegistry::has('redis'), 'RedisMemory driver should propagate');
    }

    public function test_core_skills_discoverable_via_catalogue(): void
    {
        $this->bootPlugin();

        $skillClasses = array_map(
            static fn (array $entry): string => $entry['class'],
            SkillCatalogue::all(),
        );

        $this->assertContains(
            PhpBestPracticesSkill::class,
            $skillClasses,
            'PhpBestPracticesSkill must be discoverable and active by default (no admin opt-in)'
        );
    }

    public function test_all_discovered_skills_register_by_default_without_settings(): void
    {
        Functions\when('get_option')->alias(static function (string $k, mixed $d = false): mixed {
            return $k === 'phpclaw_settings'
                ? []
                : (is_array($d) ? $d : []);
        });

        $this->bootPlugin();

        $this->assertTrue(
            SkillRegistry::has('php_best_practices'),
            'Every discovered skill must register into SkillRegistry at boot without any settings (always-on).'
        );
    }
}

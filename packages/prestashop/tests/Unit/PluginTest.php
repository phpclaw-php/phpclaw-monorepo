<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit;

use PhpClaw\Memory\MemoryRegistry;
use PhpClaw\PrestaShop\Contracts\PsDbInterface;
use PhpClaw\PrestaShop\Plugin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Plugin::class)]
final class PluginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        \Configuration::reset();

        $ref = new \ReflectionProperty(Plugin::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);
    }

    protected function tearDown(): void
    {
        \Configuration::reset();

        $ref = new \ReflectionProperty(Plugin::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);

        parent::tearDown();
    }

    public function test_get_instance_returns_same_instance(): void
    {
        $a = Plugin::getInstance(null, 'ps_');
        $b = Plugin::getInstance(null, 'ps_');

        self::assertSame($a, $b);
    }

    public function test_ps_setting_memory_driver_is_registered(): void
    {
        Plugin::getInstance(null, 'ps_');

        self::assertTrue(MemoryRegistry::has('ps_setting'));
    }

    public function test_ps_db_memory_driver_is_registered(): void
    {
        Plugin::getInstance(null, 'ps_');

        self::assertTrue(MemoryRegistry::has('ps_db'));
    }

    public function test_ps_router_memory_driver_is_registered(): void
    {
        Plugin::getInstance(null, 'ps_');

        self::assertTrue(MemoryRegistry::has('ps_router'));
    }

    public function test_config_has_developer_only_keys(): void
    {
        $plugin = Plugin::getInstance(null, 'ps_');
        $config = $plugin->config();

        self::assertArrayHasKey('shell_allowlist', $config);
        self::assertArrayHasKey('events_bridge', $config);
    }

    public function test_config_excludes_user_facing_keys(): void
    {
        $plugin = Plugin::getInstance(null, 'ps_');
        $config = $plugin->config();

        self::assertArrayNotHasKey('provider', $config);
        self::assertArrayNotHasKey('max_iterations', $config);
        self::assertArrayNotHasKey('store_messages', $config);
    }

    public function test_config_shell_allowlist_is_read_only_commands(): void
    {
        $plugin = Plugin::getInstance(null, 'ps_');

        self::assertSame(
            ['ls', 'pwd', 'df', 'cat', 'head', 'tail', 'grep', 'wc', 'date', 'uptime', 'hostname', 'whoami'],
            $plugin->config()['shell_allowlist'],
        );
    }

    public function test_engine_without_a_database_connection_cannot_build_its_memory_driver(): void
    {
        $plugin = Plugin::getInstance(null, 'ps_');

        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('PsDbMemory::__construct()');

        $plugin->engine();
    }

    public function test_save_settings_persists_to_saved(): void
    {
        $plugin = Plugin::getInstance(null, 'ps_');

        $original = $plugin->saved();
        $plugin->saveSettings(array_merge($original, ['max_iterations' => 15]));

        self::assertSame(15, (int) $plugin->saved()['max_iterations']);

        $plugin->saveSettings($original);
    }

    public function test_is_configured_returns_false_when_provider_empty(): void
    {
        $plugin = Plugin::getInstance(null, 'ps_');
        $plugin->saveSettings(['provider' => '', 'model' => '']);

        self::assertFalse($plugin->isConfigured());
    }

    public function test_is_configured_returns_true_when_provider_and_model_set(): void
    {
        $plugin = Plugin::getInstance(null, 'ps_');
        $plugin->saveSettings(['provider' => 'openai', 'model' => 'gpt-4o-mini']);

        self::assertTrue($plugin->isConfigured());
    }

    public function test_save_settings_resets_engine_to_null(): void
    {
        $plugin = Plugin::getInstance(null, 'ps_');

        $engineRef = new \ReflectionProperty(Plugin::class, 'engine');
        $engineRef->setAccessible(true);

        $plugin->saveSettings(['provider' => 'openai', 'model' => 'gpt-4o-mini']);

        self::assertNull($engineRef->getValue($plugin));
    }

    public function test_engine_error_is_cached_on_second_call(): void
    {
        putenv('ANTHROPIC_API_KEY');
        putenv('OPENAI_API_KEY');
        putenv('GROQ_API_KEY');
        putenv('GEMINI_API_KEY');

        $plugin = Plugin::getInstance(null, 'ps_');

        $errorRef = new \ReflectionProperty(Plugin::class, 'engineError');
        $errorRef->setAccessible(true);
        $errorRef->setValue($plugin, new \RuntimeException('cached error'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cached error');
        $plugin->engine();
    }

    public function test_save_settings_clears_engine_error(): void
    {
        $plugin = Plugin::getInstance(null, 'ps_');

        $errorRef = new \ReflectionProperty(Plugin::class, 'engineError');
        $errorRef->setAccessible(true);
        $errorRef->setValue($plugin, new \RuntimeException('stale'));

        $plugin->saveSettings([]);

        self::assertNull($errorRef->getValue($plugin));
    }

    public function test_save_settings_without_configuration_class_does_not_throw(): void
    {
        $plugin = Plugin::getInstance(null, 'ps_');

        $plugin->saveSettings(['provider' => 'openai', 'model' => 'gpt-4o-mini', 'api_key' => 'test']);

        $saved = (new \ReflectionProperty($plugin, 'saved'))->getValue($plugin);

        self::assertSame('openai', $saved['provider'] ?? null, 'settings must be retained even with no Configuration class');
    }

    public function test_config_events_bridge_defaults_true(): void
    {
        $plugin = Plugin::getInstance(null, 'ps_');
        $config = $plugin->config();

        self::assertTrue((bool) ($config['events_bridge'] ?? false));
    }

    public function test_build_engine_with_overrides_registers_all_native_tools(): void
    {
        $db = $this->createMock(PsDbInterface::class);
        $plugin = Plugin::getInstance($db, 'ps_');

        $engine = $plugin->buildEngineWithOverrides([
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'api_key' => 'sk-test-key',
        ]);

        $configRef = new \ReflectionProperty($engine, 'config');
        $configRef->setAccessible(true);
        $config = $configRef->getValue($engine);

        $toolNames = array_map(static fn ($t): string => $t->name(), $config->tools);

        self::assertContains(
            'ps_product',
            $toolNames,
            'Overriding provider/model at the CLI must not drop the PS-specific tools.',
        );
        self::assertGreaterThanOrEqual(
            14,
            count($toolNames),
            'All 14 native PS tools must survive a provider/model override.',
        );
    }

    public function test_build_engine_with_overrides_does_not_persist_settings(): void
    {
        $db = $this->createMock(PsDbInterface::class);
        $plugin = Plugin::getInstance($db, 'ps_');
        $before = $plugin->saved();

        $plugin->buildEngineWithOverrides(['provider' => 'openai', 'model' => 'gpt-4o', 'api_key' => 'sk-test-key']);

        self::assertSame($before, $plugin->saved(), 'buildEngineWithOverrides must be transient and never mutate saved settings.');
    }
}

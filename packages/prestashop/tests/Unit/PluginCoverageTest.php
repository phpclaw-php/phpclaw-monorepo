<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit;

use PhpClaw\Contracts\ClawInterface;
use PhpClaw\PrestaShop\Contracts\PsDbInterface;
use PhpClaw\PrestaShop\Engine\EngineFactory;
use PhpClaw\PrestaShop\Plugin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Plugin::class)]
final class PluginCoverageTest extends TestCase
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

    public function test_engine_cold_start_builds_and_caches_engine(): void
    {
        $instanceRef = new \ReflectionProperty(Plugin::class, 'instance');
        $instanceRef->setAccessible(true);
        $instanceRef->setValue(null, null);

        $plugin = Plugin::getInstance($this->createMock(PsDbInterface::class), 'ps_');
        $plugin->saveSettings([
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-test',
        ]);

        $engineRef = new \ReflectionProperty(Plugin::class, 'engine');
        $engineRef->setAccessible(true);

        self::assertNull($engineRef->getValue($plugin));

        $engine = $plugin->engine();

        self::assertSame($engine, $engineRef->getValue($plugin));
        self::assertSame($engine, $plugin->engine());
    }

    public function test_engine_returns_same_instance_on_second_call(): void
    {
        $plugin = Plugin::getInstance(null, 'ps_');

        $fakeEngine = \Mockery::mock(ClawInterface::class);

        $engineRef = new \ReflectionProperty(Plugin::class, 'engine');
        $engineRef->setAccessible(true);
        $engineRef->setValue($plugin, $fakeEngine);

        self::assertSame($fakeEngine, $plugin->engine());
        self::assertSame($fakeEngine, $plugin->engine());
    }

    public function test_save_settings_rebuilds_engine_factory(): void
    {
        $plugin = Plugin::getInstance(null, 'ps_');

        $factoryRef = new \ReflectionProperty(Plugin::class, 'engineFactory');
        $factoryRef->setAccessible(true);

        $before = $factoryRef->getValue($plugin);

        $plugin->saveSettings(['provider' => 'openai', 'model' => 'gpt-4o-mini']);

        $after = $factoryRef->getValue($plugin);

        self::assertNotSame($before, $after);
        self::assertInstanceOf(EngineFactory::class, $after);
    }

    public function test_save_settings_persists_in_memory_saved_array(): void
    {
        $plugin = Plugin::getInstance(null, 'ps_');

        $plugin->saveSettings(['provider' => 'anthropic', 'model' => 'claude-haiku-4-5-20251001', 'api_key' => 'sk']);

        self::assertSame('anthropic', $plugin->saved()['provider']);
        self::assertSame('claude-haiku-4-5-20251001', $plugin->saved()['model']);
    }

    public function test_save_settings_with_array_value_json_encodes(): void
    {
        \Configuration::reset();
        $plugin = Plugin::getInstance(null, 'ps_');

        $plugin->saveSettings(['cloud_disable' => ['tracing', 'analytics']]);

        $saved = $plugin->saved();
        self::assertSame(['tracing', 'analytics'], $saved['cloud_disable']);
    }

    public function test_load_saved_settings_returns_empty_when_no_keys_stored(): void
    {
        \Configuration::reset();
        $plugin = Plugin::getInstance(null, 'ps_');

        self::assertSame([], $plugin->saved());
    }

    public function test_engine_error_rethrown_on_repeated_calls(): void
    {
        $plugin = Plugin::getInstance(null, 'ps_');

        $err = new \RuntimeException('forced error');

        $errorRef = new \ReflectionProperty(Plugin::class, 'engineError');
        $errorRef->setAccessible(true);
        $errorRef->setValue($plugin, $err);

        $caught = 0;
        for ($i = 0; $i < 2; $i++) {
            try {
                $plugin->engine();
            } catch (\RuntimeException $e) {
                $caught++;
                self::assertSame('forced error', $e->getMessage());
            }
        }

        self::assertSame(2, $caught);
    }

    public function test_is_configured_checks_provider_and_model(): void
    {
        $plugin = Plugin::getInstance(null, 'ps_');

        $plugin->saveSettings(['provider' => 'groq', 'model' => 'llama-3.1-8b-instant']);
        self::assertTrue($plugin->isConfigured());

        $plugin->saveSettings(['provider' => '', 'model' => 'gpt-4o']);
        self::assertFalse($plugin->isConfigured());

        $plugin->saveSettings(['provider' => 'openai', 'model' => '']);
        self::assertFalse($plugin->isConfigured());
    }

    public function test_table_prefix_is_stored(): void
    {
        $plugin = Plugin::getInstance(null, 'myshop_');

        $prefixRef = new \ReflectionProperty(Plugin::class, 'tablePrefix');
        $prefixRef->setAccessible(true);

        self::assertSame('myshop_', $prefixRef->getValue($plugin));
    }

    public function test_singleton_ignores_params_on_second_call(): void
    {
        $first = Plugin::getInstance(null, 'ps_');
        $second = Plugin::getInstance(null, 'other_');

        self::assertSame($first, $second);

        $prefixRef = new \ReflectionProperty(Plugin::class, 'tablePrefix');
        $prefixRef->setAccessible(true);

        self::assertSame('ps_', $prefixRef->getValue($first));
    }

    public function test_save_settings_writes_to_configuration_store(): void
    {
        \Configuration::reset();
        $plugin = Plugin::getInstance(null, 'ps_');

        $plugin->saveSettings(['provider' => 'anthropic', 'model' => 'claude-haiku-4-5-20251001']);

        self::assertSame('anthropic', \Configuration::get('PHPCLAW_PROVIDER'));
        self::assertSame('claude-haiku-4-5-20251001', \Configuration::get('PHPCLAW_MODEL'));
    }

    public function test_save_settings_deletes_existing_before_update(): void
    {
        \Configuration::reset();
        \Configuration::updateValue('PHPCLAW_PROVIDER', 'old-value');

        $plugin = Plugin::getInstance(null, 'ps_');
        $plugin->saveSettings(['provider' => 'openai']);

        self::assertSame('openai', \Configuration::get('PHPCLAW_PROVIDER'));
    }

    public function test_load_saved_settings_reads_stored_values(): void
    {
        \Configuration::reset();
        \Configuration::updateValue('PHPCLAW_PROVIDER', 'groq');
        \Configuration::updateValue('PHPCLAW_MODEL', 'llama-3.1-8b-instant');
        \Configuration::updateValue('PHPCLAW_MAX_ITERATIONS', '15');

        $plugin = Plugin::getInstance(null, 'ps_');

        $saved = $plugin->saved();
        self::assertSame('groq', $saved['provider']);
        self::assertSame('llama-3.1-8b-instant', $saved['model']);
        self::assertSame('15', $saved['max_iterations']);
    }

    public function test_load_saved_settings_decodes_cloud_disable_json(): void
    {
        \Configuration::reset();
        \Configuration::updateValue('PHPCLAW_CLOUD_DISABLE', json_encode(['tool.before']));

        $plugin = Plugin::getInstance(null, 'ps_');

        $saved = $plugin->saved();
        self::assertIsArray($saved['cloud_disable']);
        self::assertContains('tool.before', $saved['cloud_disable']);
    }

    public function test_load_saved_settings_decodes_remote_skill_urls_json(): void
    {
        \Configuration::reset();
        \Configuration::updateValue('PHPCLAW_REMOTE_SKILL_URLS', json_encode(['https://example.com/a.md']));

        $plugin = Plugin::getInstance(null, 'ps_');

        $saved = $plugin->saved();
        self::assertIsArray($saved['remote_skill_urls']);
        self::assertContains('https://example.com/a.md', $saved['remote_skill_urls']);
    }

    public function test_load_saved_settings_handles_cloud_disable_legacy_csv(): void
    {
        \Configuration::reset();
        \Configuration::updateValue('PHPCLAW_CLOUD_DISABLE', 'tool.before,tool.after');

        $plugin = Plugin::getInstance(null, 'ps_');

        $saved = $plugin->saved();
        self::assertIsArray($saved['cloud_disable']);
        self::assertContains('tool.before', $saved['cloud_disable']);
        self::assertContains('tool.after', $saved['cloud_disable']);
    }

    public function test_load_saved_settings_handles_empty_cloud_disable(): void
    {
        \Configuration::reset();
        \Configuration::updateValue('PHPCLAW_CLOUD_DISABLE', '');

        $plugin = Plugin::getInstance(null, 'ps_');

        $saved = $plugin->saved();
        self::assertSame([], $saved['cloud_disable']);
    }

    public function test_save_settings_with_array_encodes_to_json_in_configuration(): void
    {
        \Configuration::reset();
        $plugin = Plugin::getInstance(null, 'ps_');

        $plugin->saveSettings(['cloud_disable' => ['tool.before', 'tool.after']]);

        self::assertSame(
            ['tool.before', 'tool.after'],
            json_decode((string) \Configuration::get('PHPCLAW_CLOUD_DISABLE'), true),
        );
    }
}

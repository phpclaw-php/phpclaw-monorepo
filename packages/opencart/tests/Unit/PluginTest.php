<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tests\Unit;

use PhpClaw\Exceptions\AdapterException;
use PhpClaw\Guards\Contracts\GuardInterface;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Guards\RateLimitGuard;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Memory\InMemoryStore;
use PhpClaw\Memory\PrivacyAwareMemory;
use PhpClaw\OpenCart\Admin\SettingsPage;
use PhpClaw\OpenCart\Contracts\OcDbInterface;
use PhpClaw\OpenCart\Engine\OcEngineFactory;
use PhpClaw\OpenCart\Memory\OcRouterMemory;
use PhpClaw\OpenCart\Plugin;
use PhpClaw\OpenCart\Tests\Helpers\MysqliOcDb;
use PhpClaw\OpenCart\Tests\Helpers\OcDbTestCase;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Providers\OpenAIProvider;
use PhpClaw\Skills\Contracts\SkillInterface;
use PhpClaw\Skills\SkillRegistry;

final class PluginTest extends OcDbTestCase
{
    private function memoryDdl(): string
    {
        return "CREATE TABLE IF NOT EXISTS `{$this->prefix}phpclaw_memory` (
            id          VARCHAR(26)  NOT NULL,
            namespace   VARCHAR(100) NOT NULL DEFAULT 'default',
            lookup_key  VARCHAR(255) NOT NULL DEFAULT '',
            value       LONGTEXT     NOT NULL,
            expires_at  DATETIME     DEFAULT NULL,
            created_at  DATETIME     NOT NULL DEFAULT '2024-01-01 00:00:00',
            updated_at  DATETIME     NOT NULL DEFAULT '2024-01-01 00:00:00',
            PRIMARY KEY (id),
            UNIQUE KEY uq_ns_key (namespace, lookup_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    private function convDdl(): string
    {
        return "CREATE TABLE IF NOT EXISTS `{$this->prefix}phpclaw_conversations` (
            id          VARCHAR(26)  NOT NULL,
            namespace   VARCHAR(100) NOT NULL DEFAULT 'default',
            title       VARCHAR(255) DEFAULT NULL,
            metadata    LONGTEXT     DEFAULT NULL,
            created_at  DATETIME     NOT NULL DEFAULT '2024-01-01 00:00:00',
            updated_at  DATETIME     NOT NULL DEFAULT '2024-01-01 00:00:00',
            PRIMARY KEY (id),
            KEY idx_namespace_created (namespace, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    private function msgDdl(): string
    {
        return "CREATE TABLE IF NOT EXISTS `{$this->prefix}phpclaw_messages` (
            id              VARCHAR(26)  NOT NULL,
            conversation_id VARCHAR(26)  NOT NULL DEFAULT '',
            role            VARCHAR(20)  NOT NULL DEFAULT '',
            content         LONGTEXT     DEFAULT NULL,
            tool_name       VARCHAR(255) DEFAULT NULL,
            tool_input      TEXT         DEFAULT NULL,
            created_at      DATETIME     NOT NULL DEFAULT '2024-01-01 00:00:00',
            PRIMARY KEY (id),
            KEY idx_conv_created (conversation_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    private function settingDdl(): string
    {
        return "CREATE TABLE IF NOT EXISTS `{$this->prefix}setting` (
            setting_id INT NOT NULL AUTO_INCREMENT,
            store_id   INT NOT NULL DEFAULT 0,
            code       VARCHAR(255) NOT NULL DEFAULT '',
            `key`      VARCHAR(255) NOT NULL DEFAULT '',
            value      LONGTEXT     NOT NULL,
            serialized INT NOT NULL DEFAULT 0,
            PRIMARY KEY (setting_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetPlugin();
        SkillRegistry::reset();
    }

    protected function tearDown(): void
    {
        $this->resetPlugin();
        SkillRegistry::reset();
        parent::tearDown();
    }

    private function resetPlugin(): void
    {
        $ref = new \ReflectionClass(Plugin::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    private function makeDb(): MysqliOcDb
    {
        $this->resetTables([$this->memoryDdl(), $this->convDdl(), $this->msgDdl()]);

        return $this->db;
    }

    private function makeFullDb(): MysqliOcDb
    {
        $this->resetTables([$this->memoryDdl(), $this->convDdl(), $this->msgDdl(), $this->settingDdl()]);

        return $this->db;
    }

    private function seedSettingsInDb(array $settings): void
    {
        $table = $this->prefix.'setting';
        foreach ($settings as $field => $value) {
            $serialized = is_array($value) ? 1 : 0;
            $stored = $serialized ? serialize($value) : (string) $value;
            $key = $this->db->escape('module_phpclaw_'.$field);
            $stored = $this->db->escape($stored);
            $this->seed(
                "INSERT INTO `{$table}` (store_id, code, `key`, value, serialized)
                 VALUES (0, 'module_phpclaw', '$key', '$stored', $serialized)",
            );
        }
    }

    public function test_plugin_class_exists(): void
    {
        self::assertTrue(class_exists(Plugin::class));
    }

    public function test_plugin_is_final(): void
    {
        $ref = new \ReflectionClass(Plugin::class);
        self::assertTrue($ref->isFinal());
    }

    public function test_instance_is_initially_null_after_reset(): void
    {
        $ref = new \ReflectionClass(Plugin::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        self::assertNull($prop->getValue(null));
    }

    public function test_get_instance_returns_same_object(): void
    {
        $a = Plugin::getInstance($this->prefix);
        $b = Plugin::getInstance($this->prefix);
        self::assertSame($a, $b);
    }

    public function test_memory_returns_memory_interface(): void
    {
        $db = $this->makeDb();
        $plugin = Plugin::getInstance($this->prefix, null, $db);
        self::assertInstanceOf(MemoryInterface::class, $plugin->memory());
    }

    public function test_run_migration_without_a_db_is_a_no_op(): void
    {
        $plugin = Plugin::getInstance($this->prefix);

        $plugin->runMigration();

        $ref = new \ReflectionClass($plugin);
        $prop = $ref->getProperty('db');
        $prop->setAccessible(true);

        self::assertNull($prop->getValue($plugin), 'runMigration() must return early when no db is wired');
    }

    public function test_run_migration_with_pdo_does_not_throw(): void
    {
        $db = $this->makeDb();
        $plugin = Plugin::getInstance($this->prefix, null, $db);
        $ref = new \ReflectionClass(Plugin::class);
        self::assertTrue($ref->hasMethod('runMigration'));
        self::assertTrue($ref->getMethod('runMigration')->isPublic());
    }

    public function test_save_settings_writes_and_reads_back(): void
    {
        $db = $this->makeFullDb();
        $plugin = Plugin::getInstance($this->prefix, null, $db);
        $settings = ['api_key' => 'test-key', 'provider' => 'anthropic'];

        $plugin->saveSettings($settings);
        $saved = $plugin->saved();

        self::assertSame('test-key', $saved['api_key']);
        self::assertSame('anthropic', $saved['provider']);
    }

    public function test_save_settings_persists_to_oc_setting_table(): void
    {
        $db = $this->makeFullDb();
        $plugin = Plugin::getInstance($this->prefix, null, $db);
        $plugin->saveSettings(['provider' => 'openai', 'api_key' => 'sk-test']);

        $table = $this->prefix.'setting';
        $result = $db->query(
            "SELECT `key`, value FROM `{$table}` WHERE store_id = 0 AND code = 'module_phpclaw' ORDER BY `key` ASC",
        );
        $rows = array_column($result->rows, 'value', 'key');

        self::assertArrayHasKey('module_phpclaw_api_key', $rows);
        self::assertSame('sk-test', $rows['module_phpclaw_api_key']);
        self::assertArrayHasKey('module_phpclaw_provider', $rows);
        self::assertSame('openai', $rows['module_phpclaw_provider']);
    }

    public function test_save_settings_no_op_when_db_null(): void
    {
        $plugin = Plugin::getInstance($this->prefix);
        $plugin->saveSettings(['provider' => 'ollama']);
        self::assertSame('ollama', $plugin->saved()['provider']);
    }

    public function test_config_contains_expected_keys(): void
    {
        $plugin = Plugin::getInstance($this->prefix);
        $config = $plugin->config();
        self::assertArrayHasKey('events_bridge', $config);
        self::assertArrayHasKey('update_server', $config);
    }

    public function test_engine_returns_or_throws_when_no_api_key(): void
    {
        putenv('ANTHROPIC_API_KEY');
        putenv('OPENAI_API_KEY');
        putenv('GROQ_API_KEY');
        putenv('GEMINI_API_KEY');

        $plugin = Plugin::getInstance($this->prefix);

        $this->expectException(AdapterException::class);

        $plugin->engine(true);
    }

    public function test_engine_builds_when_api_key_set(): void
    {
        putenv('ANTHROPIC_API_KEY=test-key-for-build');
        $db = $this->makeFullDb();
        $plugin = Plugin::getInstance($this->prefix, null, $db);

        $engine = $plugin->engine(true);
        self::assertNotNull($engine);

        putenv('ANTHROPIC_API_KEY');
    }

    public function test_saved_defaults_empty_when_no_settings_saved(): void
    {
        $db = $this->makeFullDb();
        $plugin = Plugin::getInstance($this->prefix, null, $db);
        $saved = $plugin->saved();
        self::assertIsArray($saved);
        self::assertEmpty($saved);
    }

    public function test_save_settings_overwrites_previous_values(): void
    {
        $db = $this->makeFullDb();
        $plugin = Plugin::getInstance($this->prefix, null, $db);
        $plugin->saveSettings(['provider' => 'anthropic']);
        $plugin->saveSettings(['provider' => 'openai']);

        self::assertSame('openai', $plugin->saved()['provider']);
    }

    public function test_engine_uses_ollama_path_without_api_key(): void
    {
        $db = $this->makeFullDb();
        $plugin = Plugin::getInstance($this->prefix, null, $db);
        $plugin->saveSettings([
            'provider' => 'ollama',
            'model' => 'qwen2.5:7b',
            'base_url' => '',
            'store_messages' => '1',
            'max_iterations' => 5,
        ]);

        $engine = $plugin->engine(true);
        self::assertNotNull($engine);
    }

    public function test_engine_wraps_memory_with_privacy_when_store_messages_off(): void
    {
        $db = $this->makeFullDb();
        $plugin = Plugin::getInstance($this->prefix, null, $db);
        $plugin->saveSettings([
            'provider' => 'ollama',
            'model' => 'qwen2.5:7b',
            'base_url' => '',
            'store_messages' => '0',
        ]);

        $engine = $plugin->engine(true);
        $memory = $engine->memory();
        self::assertInstanceOf(PrivacyAwareMemory::class, $memory);
    }

    public function test_engine_caches_built_engine_across_calls(): void
    {
        $db = $this->makeFullDb();
        $plugin = Plugin::getInstance($this->prefix, null, $db);
        $plugin->saveSettings([
            'provider' => 'ollama',
            'model' => 'qwen2.5:7b',
            'base_url' => '',
        ]);

        $a = $plugin->engine(true);
        $b = $plugin->engine(true);
        self::assertSame($a, $b);
    }

    public function test_memory_returns_router_when_pdo_present(): void
    {
        $db = $this->makeDb();
        $plugin = Plugin::getInstance($this->prefix, null, $db);

        self::assertInstanceOf(OcRouterMemory::class, $plugin->memory());
    }

    public function test_table_prefix_defaults_to_oc(): void
    {
        $plugin = Plugin::getInstance();
        $ref = new \ReflectionClass(Plugin::class);
        $prop = $ref->getProperty('tablePrefix');
        $prop->setAccessible(true);

        self::assertSame('oc_', $prop->getValue($plugin));
    }

    public function test_custom_table_prefix_is_honoured(): void
    {
        $plugin = Plugin::getInstance('shop_');
        $ref = new \ReflectionClass(Plugin::class);
        $prop = $ref->getProperty('tablePrefix');
        $prop->setAccessible(true);

        self::assertSame('shop_', $prop->getValue($plugin));
    }

    public function test_save_settings_with_guards_config_does_not_throw(): void
    {
        $db = $this->makeFullDb();
        $plugin = Plugin::getInstance($this->prefix, null, $db);
        $plugin->saveSettings([
            'provider' => 'ollama',
            'model' => 'qwen2.5:7b',
            'guards' => [
                ['class' => '\\NonExistentGuard\\ShouldBeSkipped', 'priority' => 5],
                ['class' => '', 'priority' => 10],
                ['not_a_class_key' => 'oops'],
            ],
        ]);

        self::assertSame('ollama', $plugin->engine(true)->config()->providerName, 'invalid guard entries must be skipped, not fatal');
    }

    public function test_save_settings_with_hooks_config_does_not_throw(): void
    {
        $db = $this->makeFullDb();
        $plugin = Plugin::getInstance($this->prefix, null, $db);
        $plugin->saveSettings([
            'provider' => 'ollama',
            'model' => 'qwen2.5:7b',
            'hooks' => [
                ['event' => 'agent.before', 'handler' => ['NotAClass', 'notAMethod']],
                ['event' => '', 'handler' => ['', '']],
                ['no_event_key' => true],
            ],
        ]);

        self::assertSame('ollama', $plugin->engine(true)->config()->providerName, 'invalid hook entries must be skipped, not fatal');
    }

    public function test_save_settings_writes_max_iterations_int(): void
    {
        $db = $this->makeFullDb();
        $plugin = Plugin::getInstance($this->prefix, null, $db);
        $plugin->saveSettings(['max_iterations' => 25]);
        self::assertSame(25, $plugin->saved()['max_iterations']);
    }

    public function test_save_settings_handles_empty_array(): void
    {
        $db = $this->makeFullDb();
        $plugin = Plugin::getInstance($this->prefix, null, $db);
        $plugin->saveSettings([]);
        $saved = $plugin->saved();
        self::assertIsArray($saved);
    }

    public function test_config_contains_shell_allowlist_key(): void
    {
        $plugin = Plugin::getInstance($this->prefix);
        $config = $plugin->config();
        self::assertArrayHasKey('shell_allowlist', $config);
    }

    public function test_memory_function_uses_oc_router_driver(): void
    {
        $db = $this->makeDb();
        $plugin = Plugin::getInstance($this->prefix, null, $db);
        $memory = $plugin->memory();
        self::assertInstanceOf(OcRouterMemory::class, $memory);
    }

    public function test_get_instance_returns_same_instance_after_arg_change(): void
    {
        $a = Plugin::getInstance($this->prefix);
        $b = Plugin::getInstance('shop_', null, $this->db);
        self::assertSame($a, $b);
    }

    public function test_save_settings_with_skills_array_config(): void
    {
        $db = $this->makeFullDb();
        $plugin = Plugin::getInstance($this->prefix, null, $db);
        $plugin->saveSettings([
            'provider' => 'ollama',
            'model' => 'qwen2.5:7b',
            'skills' => [
                ['type' => 'array', 'key' => 'demo', 'system_prompt' => 'You are demo.', 'keywords' => ['demo']],
                ['type' => 'unknown', 'key' => 'invalid'],
                ['key' => 'no_type_key', 'system_prompt' => 'no type'],
            ],
        ]);

        self::assertSame('ollama', $plugin->engine(true)->config()->providerName, 'invalid skill entries must be skipped, not fatal');
    }

    public function test_save_settings_with_file_skill_config(): void
    {
        $db = $this->makeFullDb();
        $plugin = Plugin::getInstance($this->prefix, null, $db);
        $plugin->saveSettings([
            'provider' => 'ollama',
            'model' => 'qwen2.5:7b',
            'skills' => [
                ['type' => 'file', 'path' => '/nonexistent/path/to/skill.md'],
                ['type' => 'file'],
            ],
        ]);

        self::assertSame('ollama', $plugin->engine(true)->config()->providerName, 'a file skill entry must not break the build');
    }

    public function test_engine_builds_with_ollama_provider(): void
    {
        $db = $this->makeFullDb();
        $plugin = Plugin::getInstance($this->prefix, null, $db);
        $plugin->saveSettings([
            'provider' => 'ollama',
            'model' => 'qwen2.5:7b',
            'base_url' => '',
        ]);

        $config = $plugin->engine(true)->config();

        self::assertSame('ollama', $config->providerName);
        self::assertSame('qwen2.5:7b', $config->model);
    }

    public function test_engine_with_tool_deny_filter(): void
    {
        $db = $this->makeFullDb();
        $plugin = Plugin::getInstance($this->prefix, null, $db);
        $plugin->saveSettings([
            'provider' => 'ollama',
            'model' => 'qwen2.5:7b',
            'base_url' => '',
        ]);
        $this->injectConfig($plugin, ['tool_deny' => ['oc_product', 'oc_order']]);

        $names = array_map(
            static fn (object $tool): string => $tool->name(),
            $plugin->engine(true)->config()->tools,
        );

        self::assertNotContains('oc_product', $names);
        self::assertNotContains('oc_order', $names);
        self::assertNotEmpty($names, 'deny must not empty the whole registry');
    }

    public function test_guide_tools_applies_tool_deny_from_config(): void
    {
        $db = $this->makeFullDb();
        $plugin = Plugin::getInstance($this->prefix, null, $db);

        $ref = new \ReflectionClass($plugin);
        $configProp = $ref->getProperty('config');
        $configProp->setAccessible(true);
        $config = $configProp->getValue($plugin);
        $config['tool_deny'] = ['oc_product'];
        $configProp->setValue($plugin, $config);

        $names = array_map(static fn ($tool): string => $tool->name(), $plugin->guideTools(callerMayUseModule: true));

        self::assertNotContains('oc_product', $names, 'guideTools() must apply tool_deny for the MCP/Guide path.');
        self::assertContains('oc_order', $names);
    }

    public function test_engine_with_max_iterations_from_saved_settings(): void
    {
        $db = $this->makeFullDb();
        $plugin = Plugin::getInstance($this->prefix, null, $db);
        $plugin->saveSettings([
            'provider' => 'ollama',
            'model' => 'qwen2.5:7b',
            'base_url' => '',
            'max_iterations' => 7,
        ]);

        self::assertSame(7, $plugin->engine(true)->config()->maxIterations);
    }

    public function test_resolve_engine_config_includes_cloud_signing_secret(): void
    {
        $factory = new OcEngineFactory;
        $resolved = $factory->resolveEngineConfig(['cloud_signing_secret' => 'my-sign-secret']);
        self::assertArrayHasKey('cloudSigningSecret', $resolved);
        self::assertSame('my-sign-secret', $resolved['cloudSigningSecret']);
    }

    public function test_resolve_engine_config_cloud_signing_secret_defaults_to_empty(): void
    {
        $factory = new OcEngineFactory;
        $resolved = $factory->resolveEngineConfig([]);
        self::assertSame('', $resolved['cloudSigningSecret']);
    }

    public function test_custom_provider_override_returns_null_for_non_custom(): void
    {
        $factory = new OcEngineFactory;
        $config = ['provider' => 'anthropic', 'apiKey' => 'sk', 'model' => 'claude', 'systemPrompt' => '', 'baseUrl' => ''];
        self::assertNull($factory->customProviderOverride($config));
    }

    public function test_custom_provider_override_returns_null_for_empty_base_url(): void
    {
        $factory = new OcEngineFactory;
        $config = ['provider' => 'custom', 'apiKey' => 'sk', 'model' => 'my-model', 'systemPrompt' => '', 'baseUrl' => ''];
        self::assertNull($factory->customProviderOverride($config));
    }

    public function test_custom_provider_override_returns_null_for_non_http_base_url(): void
    {
        $factory = new OcEngineFactory;
        $config = ['provider' => 'custom', 'apiKey' => 'sk', 'model' => 'my-model', 'systemPrompt' => '', 'baseUrl' => 'file:///etc/passwd'];
        self::assertNull($factory->customProviderOverride($config));
    }

    public function test_custom_provider_override_returns_provider_for_valid_https_url(): void
    {
        if (! class_exists(OpenAIProvider::class)) {
            self::markTestSkipped('OpenAIProvider not available.');
        }
        $factory = new OcEngineFactory;
        $config = ['provider' => 'custom', 'apiKey' => 'sk-test', 'model' => 'my-model', 'systemPrompt' => '', 'baseUrl' => 'https://api.example.com/v1/chat/completions'];
        $result = $factory->customProviderOverride($config);
        self::assertInstanceOf(ProviderInterface::class, $result);
        self::assertSame('https://api.example.com/v1/chat/completions', $result->endpoint());
    }

    public function test_custom_provider_override_rejects_external_http_base_url(): void
    {
        $factory = new OcEngineFactory;
        $config = ['provider' => 'custom', 'apiKey' => 'sk', 'model' => 'my-model', 'systemPrompt' => '', 'baseUrl' => 'http://api.evil.example/v1'];
        self::assertNull($factory->customProviderOverride($config));
    }

    public function test_custom_provider_override_allows_http_loopback_base_url(): void
    {
        if (! class_exists(OpenAIProvider::class)) {
            self::markTestSkipped('OpenAIProvider not available.');
        }
        $factory = new OcEngineFactory;
        $config = ['provider' => 'custom', 'apiKey' => 'sk', 'model' => 'my-model', 'systemPrompt' => '', 'baseUrl' => 'http://localhost:11434/v1'];
        self::assertInstanceOf(ProviderInterface::class, $factory->customProviderOverride($config));
    }

    public function test_engine_with_anthropic_provider_uses_saved_api_key(): void
    {
        $db = $this->makeFullDb();
        $plugin = Plugin::getInstance($this->prefix, null, $db);
        $plugin->saveSettings([
            'provider' => 'anthropic',
            'model' => 'claude-haiku-4-5',
            'api_key' => 'sk-ant-from-saved-settings',
        ]);

        $config = $plugin->engine(true)->config();

        self::assertSame('anthropic', $config->providerName);
        self::assertSame('sk-ant-from-saved-settings', $config->apiKey);
    }

    public function test_save_settings_persists_shell_allowlist(): void
    {
        $db = $this->makeFullDb();
        $plugin = Plugin::getInstance($this->prefix, null, $db);
        $plugin->saveSettings(['shell_allowlist' => ['ls', 'pwd']]);
        self::assertSame(['ls', 'pwd'], $plugin->saved()['shell_allowlist']);
    }

    public function test_save_settings_with_events_bridge_disabled(): void
    {
        $db = $this->makeFullDb();
        $plugin = Plugin::getInstance($this->prefix, null, $db);
        $plugin->saveSettings([
            'provider' => 'ollama',
            'model' => 'qwen2.5:7b',
            'events_bridge' => false,
        ]);

        self::assertSame('ollama', $plugin->engine(true)->config()->providerName);
    }

    public function test_bootregistries_registers_guards_from_saved_settings(): void
    {
        $db = $this->makeFullDb();
        $this->seedSettingsInDb([
            'provider' => 'ollama',
            'model' => 'qwen2.5:7b',
            'guards' => [
                ['class' => '\\PhpClaw\\Guards\\RateLimitGuard', 'priority' => 5],
                ['class' => 'NonExistent\\Bad', 'priority' => 10],
                ['class' => '', 'priority' => 99],
                ['priority' => 1],
            ],
        ]);

        $plugin = Plugin::getInstance($this->prefix, null, $db);
        $saved = $plugin->saved();
        self::assertSame('ollama', $saved['provider']);
        self::assertNotEmpty($saved['guards']);
    }

    public function test_bootregistries_registers_hooks_from_saved_settings(): void
    {
        $db = $this->makeFullDb();
        $this->seedSettingsInDb([
            'provider' => 'ollama',
            'model' => 'qwen2.5:7b',
            'hooks' => [
                ['event' => 'agent.before', 'handler' => 'strlen', 'priority' => 5],
                ['event' => 'tool.after',   'handler' => 'strlen'],
                ['event' => '',             'handler' => 'strlen'],
                ['handler' => 'strlen'],
            ],
        ]);

        $plugin = Plugin::getInstance($this->prefix, null, $db);
        $saved = $plugin->saved();
        self::assertSame('ollama', $saved['provider']);
        self::assertNotEmpty($saved['hooks']);
    }

    public function test_bootregistries_registers_skills_from_saved_settings(): void
    {
        $db = $this->makeFullDb();
        $this->seedSettingsInDb([
            'provider' => 'ollama',
            'model' => 'qwen2.5:7b',
            'skills' => [
                ['type' => 'array',   'key' => 'demo', 'system_prompt' => 'Be helpful.', 'keywords' => ['hi']],
                ['type' => 'file',    'path' => '/nonexistent/path.md'],
                ['type' => 'unknown', 'key' => 'x'],
                ['key' => 'no_type'],
            ],
        ]);

        $plugin = Plugin::getInstance($this->prefix, null, $db);
        self::assertNotEmpty($plugin->saved()['skills']);
    }

    public function test_engine_resolves_skills_through_skills_config(): void
    {
        $db = $this->makeFullDb();
        $this->seedSettingsInDb([
            'provider' => 'ollama',
            'model' => 'qwen2.5:7b',
            'base_url' => '',
            'skills' => [
                ['type' => 'array', 'key' => 'demo', 'system_prompt' => 'Be brief.', 'keywords' => ['demo']],
            ],
        ]);

        $plugin = Plugin::getInstance($this->prefix, null, $db);
        $engine = $plugin->engine(true);
        self::assertNotNull($engine);
    }

    public function test_engine_with_anthropic_provider_and_saved_api_key(): void
    {
        $db = $this->makeFullDb();
        $this->seedSettingsInDb([
            'provider' => 'anthropic',
            'model' => 'claude-haiku-4-5',
            'api_key' => 'sk-ant-preconfigured',
        ]);

        $plugin = Plugin::getInstance($this->prefix, null, $db);
        $engine = $plugin->engine(true);
        self::assertNotNull($engine);
    }

    public function test_run_migration_executes_install_sql_when_tables_missing(): void
    {
        $plugin = Plugin::getInstance($this->prefix, null, $this->db);

        $plugin->runMigration();

        foreach (['phpclaw_memory', 'phpclaw_conversations', 'phpclaw_messages'] as $suffix) {
            $table = $this->prefix.$suffix;
            self::assertNotEmpty(
                $this->db->query("SHOW TABLES LIKE '{$table}'"),
                "runMigration() must create {$table}",
            );
        }
    }

    public function test_engine_with_provider_override_via_saved_settings(): void
    {
        $db = $this->makeFullDb();
        $this->seedSettingsInDb([
            'provider' => 'ollama',
            'model' => 'qwen2.5:7b',
            'base_url' => '',
            'guards' => [['class' => 'NoSuch\\Guard']],
            'hooks' => [['event' => 'agent.before', 'handler' => 'strlen']],
            'skills' => [['type' => 'array', 'key' => 'k1', 'system_prompt' => 'hi', 'keywords' => ['k']]],
        ]);

        $plugin = Plugin::getInstance($this->prefix, null, $db);
        $engine = $plugin->engine(true);
        self::assertNotNull($engine);
    }

    private function injectConfig(Plugin $plugin, array $config): void
    {
        $ref = new \ReflectionClass($plugin);
        $prop = $ref->getProperty('config');
        $current = $prop->getValue($plugin);
        $prop->setValue($plugin, array_merge($current, $config));
    }

    private function invokePrivate(Plugin $plugin, string $method, array $args = []): mixed
    {
        $ref = new \ReflectionClass($plugin);
        $m = $ref->getMethod($method);

        return $m->invokeArgs($plugin, $args);
    }

    public function test_register_config_guards_skips_non_array_entries(): void
    {
        $plugin = Plugin::getInstance($this->prefix);
        $this->injectConfig($plugin, [
            'guards' => [
                'not-an-array',
                42,
                null,
                ['class' => 'NonExistent\\Guard'],
                ['class' => 123],
                ['no_class_key' => 'oops'],
            ],
        ]);
        GuardRegistry::reset();

        $this->invokePrivate($plugin, 'registerConfigGuards');

        self::assertSame(0, GuardRegistry::count(), 'no malformed entry may register a guard');
    }

    public function test_register_config_guards_registers_real_guard(): void
    {
        $plugin = Plugin::getInstance($this->prefix);
        $this->injectConfig($plugin, [
            'guards' => [
                ['class' => '\\PhpClaw\\Guards\\RateLimitGuard', 'priority' => 7],
            ],
        ]);
        GuardRegistry::reset();

        $this->invokePrivate($plugin, 'registerConfigGuards');

        self::assertSame(1, GuardRegistry::count());
        self::assertTrue(GuardRegistry::hasClass(RateLimitGuard::class));
    }

    public function test_register_config_hooks_skips_invalid_entries(): void
    {
        $plugin = Plugin::getInstance($this->prefix);
        $this->injectConfig($plugin, [
            'hooks' => [
                'not-an-array',
                ['no_event_key' => true, 'handler' => 'strlen'],
                ['event' => '', 'handler' => 'strlen'],
                ['event' => 123, 'handler' => 'strlen'],
                ['event' => 'tool.before'],
                ['event' => 'tool.before', 'handler' => 'strlen'],
            ],
        ]);
        $before = HookRegistry::count('tool.before');
        $this->invokePrivate($plugin, 'registerConfigHooks');

        self::assertSame($before + 1, HookRegistry::count('tool.before'), 'only the one complete entry may register');
    }

    public function test_resolve_config_skills_builds_array_skill_from_inline(): void
    {
        $plugin = Plugin::getInstance($this->prefix);
        $this->injectConfig($plugin, [
            'skills' => [
                [
                    'name' => 'demo',
                    'description' => 'Demo skill.',
                    'tags' => ['demo'],
                    'content' => 'Be helpful.',
                ],
            ],
        ]);

        $result = $this->invokePrivate($plugin, 'resolveConfigSkills');
        self::assertIsArray($result);
        self::assertCount(1, $result);
    }

    public function test_resolve_config_skills_skips_non_array_entries(): void
    {
        $plugin = Plugin::getInstance($this->prefix);
        $this->injectConfig($plugin, [
            'skills' => ['not-array', 42, null],
        ]);

        $result = $this->invokePrivate($plugin, 'resolveConfigSkills');
        self::assertSame([], $result);
    }

    public function test_resolve_config_skills_handles_class_entry_nonexistent(): void
    {
        $plugin = Plugin::getInstance($this->prefix);
        $this->injectConfig($plugin, [
            'skills' => [
                ['class' => 'NonExistent\\Skill\\Class'],
            ],
        ]);

        $result = $this->invokePrivate($plugin, 'resolveConfigSkills');
        self::assertSame([], $result);
    }

    public function test_resolve_config_skills_handles_file_entry_missing_file(): void
    {
        $plugin = Plugin::getInstance($this->prefix);
        $this->injectConfig($plugin, [
            'skills' => [
                ['file' => '/nonexistent/path/to/skill.md'],
            ],
        ]);

        $result = $this->invokePrivate($plugin, 'resolveConfigSkills');
        self::assertSame([], $result);
    }

    public function test_resolve_config_skills_skips_inline_entry_missing_required_keys(): void
    {
        $plugin = Plugin::getInstance($this->prefix);
        $this->injectConfig($plugin, [
            'skills' => [
                ['name' => 'only-name'],
                ['name' => 'no-content', 'description' => 'd'],
            ],
        ]);

        $result = $this->invokePrivate($plugin, 'resolveConfigSkills');
        self::assertSame([], $result);
    }

    public function test_register_config_skills_registers_all_resolved(): void
    {
        $plugin = Plugin::getInstance($this->prefix);
        $this->injectConfig($plugin, [
            'skills' => [
                [
                    'name' => 'inline-one',
                    'description' => 'first',
                    'tags' => ['a'],
                    'content' => 'X',
                ],
                [
                    'name' => 'inline-two',
                    'description' => 'second',
                    'content' => 'Y',
                ],
            ],
        ]);
        $this->invokePrivate($plugin, 'registerConfigSkills');

        self::assertTrue(SkillRegistry::has('inline-one'));
        self::assertTrue(SkillRegistry::has('inline-two'));
    }

    public function test_get_instance_accepts_oc_db_interface_as_third_arg(): void
    {
        $param = (new \ReflectionMethod(Plugin::class, 'getInstance'))
            ->getParameters()[2];

        self::assertSame('db', $param->getName());
        $type = $param->getType();
        self::assertInstanceOf(\ReflectionNamedType::class, $type);
        self::assertSame(
            OcDbInterface::class,
            $type->getName(),
        );
        self::assertTrue($type->allowsNull());
        self::assertTrue($param->isDefaultValueAvailable());
        self::assertNull($param->getDefaultValue());
    }

    public function test_plugin_db_field_stores_injected_native_db_directly(): void
    {
        $db = $this->makeDb();
        $plugin = Plugin::getInstance($this->prefix, null, $db);

        $dbProp = (new \ReflectionClass($plugin))->getProperty('db');
        $dbProp->setAccessible(true);

        self::assertSame(
            $db,
            $dbProp->getValue($plugin),
            'When OcDbInterface is supplied, Plugin must store it directly, with no wrapping.',
        );
    }

    public function test_plugin_db_field_is_null_when_no_db_supplied(): void
    {
        $plugin = Plugin::getInstance($this->prefix);

        $dbProp = (new \ReflectionClass($plugin))->getProperty('db');
        $dbProp->setAccessible(true);

        self::assertNull($dbProp->getValue($plugin));
    }

    public function test_get_instance_takes_prefix_registry_and_db(): void
    {
        $params = (new \ReflectionMethod(Plugin::class, 'getInstance'))->getParameters();

        self::assertSame(
            ['tablePrefix', 'registry', 'db'],
            array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $params),
        );
    }

    public function test_load_saved_settings_returns_empty_when_no_db(): void
    {
        $plugin = Plugin::getInstance($this->prefix);
        self::assertSame([], $plugin->saved());
    }

    public function test_load_saved_settings_reads_from_oc_setting_table(): void
    {
        $db = $this->makeFullDb();
        $this->seedSettingsInDb(['provider' => 'groq', 'api_key' => 'gsk-abc']);

        $plugin = Plugin::getInstance($this->prefix, null, $db);
        $saved = $plugin->saved();

        self::assertSame('groq', $saved['provider']);
        self::assertSame('gsk-abc', $saved['api_key']);
    }

    public function test_save_settings_replaces_existing_rows(): void
    {
        $db = $this->makeFullDb();
        $plugin = Plugin::getInstance($this->prefix, null, $db);

        $plugin->saveSettings(['provider' => 'openai']);
        $plugin->saveSettings(['provider' => 'groq']);

        $settingTable = $this->prefix.'setting';
        $result = $db->query(
            "SELECT COUNT(*) AS cnt FROM `{$settingTable}` WHERE code = 'module_phpclaw' AND `key` = 'module_phpclaw_provider'",
        );
        $count = (int) ($result->row['cnt'] ?? 0);
        self::assertSame(1, $count, 'There must be exactly one row per key after successive saves.');
        self::assertSame('groq', $plugin->saved()['provider']);
    }

    private function makeExtraEventRegistry(): object
    {
        $listeners = [
            'phpclaw/extra/guards' => static function (array &$bucket): void {
                $bucket[] = new class implements GuardInterface
                {
                    public function scan(string $message): void {}
                };
                $bucket[] = 'not_a_guard';
            },
            'phpclaw/extra/hooks' => static function (array &$bucket): void {
                $bucket[] = ['event' => 'agent.after', 'handler' => static fn () => null, 'priority' => 5];
                $bucket[] = ['only_event' => 'agent.after'];
                $bucket[] = 'not_an_array';
            },
            'phpclaw/extra/skills' => static function (array &$bucket): void {
                $bucket[] = new class implements SkillInterface
                {
                    public function name(): string
                    {
                        return 'fixture_skill';
                    }

                    public function description(): string
                    {
                        return 'fixture';
                    }

                    public function tags(): array
                    {
                        return ['fixture'];
                    }

                    public function content(): string
                    {
                        return 'fixture content';
                    }
                };
                $bucket[] = 'not_a_skill';
            },
            'phpclaw/extra/memory' => static function (array &$bucket): void {
                $bucket['fixture_mem'] = static fn (): MemoryInterface => new InMemoryStore;
                $bucket[42] = static fn () => null;
                $bucket['not_callable'] = 'not-callable';
            },
            'phpclaw/extra/providers' => static function (array &$bucket): void {
                $bucket['fixture_provider'] = ['class' => 'PhpClaw\\Providers\\AnthropicProvider'];
                $bucket['missing_class'] = ['class' => 'NonExistent\\Bad\\Class'];
                $bucket['not_array'] = 'not-array';
                $bucket[7] = ['class' => 'PhpClaw\\Providers\\AnthropicProvider'];
            },
        ];

        return new class($listeners)
        {
            public function __construct(private readonly array $listeners) {}

            public function has(string $name): bool
            {
                return $name === 'event';
            }

            public function get(string $name): object
            {
                return new class($this->listeners)
                {
                    public function __construct(private readonly array $listeners) {}

                    public function trigger(string $event, array $args): void
                    {
                        if (isset($this->listeners[$event])) {
                            ($this->listeners[$event])($args[0]);
                        }
                    }
                };
            }
        };
    }

    public function test_getinstance_with_registry_registers_all_extra_subsystems(): void
    {
        $registry = $this->makeExtraEventRegistry();
        $plugin = Plugin::getInstance($this->prefix, $registry);

        self::assertInstanceOf(Plugin::class, $plugin);
    }

    public function test_getinstance_registry_trigger_exceptions_are_swallowed(): void
    {
        $registry = new class
        {
            public function has(string $name): bool
            {
                return $name === 'event';
            }

            public function get(string $name): object
            {
                return new class
                {
                    public function trigger(string $event, array $args): void
                    {
                        throw new \RuntimeException('listener boom');
                    }
                };
            }
        };

        $plugin = Plugin::getInstance($this->prefix, $registry);
        self::assertInstanceOf(Plugin::class, $plugin);
    }

    public function test_getinstance_without_event_registry_skips_extra_events(): void
    {
        $registry = new class
        {
            public function has(string $name): bool
            {
                return false;
            }

            public function get(string $name): object
            {
                throw new \RuntimeException('not reachable');
            }
        };

        $plugin = Plugin::getInstance($this->prefix, $registry);
        self::assertInstanceOf(Plugin::class, $plugin);
    }

    public function test_build_engine_with_overrides_restores_previous_saved_after_success(): void
    {
        $db = $this->makeFullDb();
        $this->seedSettingsInDb(['provider' => 'ollama', 'model' => 'qwen2.5:7b', 'base_url' => '']);
        $plugin = Plugin::getInstance($this->prefix, null, $db);

        $before = $plugin->saved();
        $plugin->buildEngineWithOverrides(['provider' => 'ollama', 'model' => 'llama3.2'], true);

        self::assertSame($before, $plugin->saved(), 'saved() must revert to the pre-override value once the override call returns.');
    }

    public function test_build_engine_with_overrides_restores_saved_even_on_build_failure(): void
    {
        $db = $this->makeFullDb();
        $this->seedSettingsInDb(['provider' => 'anthropic', 'model' => 'claude-haiku', 'api_key' => '']);
        $plugin = Plugin::getInstance($this->prefix, null, $db);

        $before = $plugin->saved();

        try {
            $plugin->buildEngineWithOverrides(['provider' => 'anthropic', 'model' => 'claude-haiku'], true);
            self::fail('Expected build to fail without an API key configured.');
        } catch (\Throwable) {
        }

        self::assertSame($before, $plugin->saved(), 'saved() must revert to the pre-override value even when build() throws.');
    }

    public function test_build_engine_with_overrides_falls_back_to_saved_when_override_omitted(): void
    {
        $db = $this->makeFullDb();
        $this->seedSettingsInDb(['provider' => 'ollama', 'model' => 'qwen2.5:7b', 'base_url' => '']);
        $plugin = Plugin::getInstance($this->prefix, null, $db);

        $engine = $plugin->buildEngineWithOverrides([], true);
        $config = $engine->config();

        self::assertSame('ollama', $config->providerName);
        self::assertSame('qwen2.5:7b', $config->model);
    }

    public function test_remote_skill_urls_empty_when_unconfigured(): void
    {
        $db = $this->makeFullDb();
        $plugin = Plugin::getInstance($this->prefix, null, $db);

        self::assertSame([], SettingsPage::remoteSkillUrls($plugin));
        self::assertSame([], SettingsPage::remoteSkills($plugin, [], true));
    }

    public function test_remote_skills_surfaces_registered_skills_not_already_discovered(): void
    {
        $db = $this->makeFullDb();
        $this->seedSettingsInDb([
            'provider' => 'ollama',
            'model' => 'qwen2.5:7b',
            'base_url' => '',
            'remote_skill_urls' => ['https://skills.invalid/collection.md'],
        ]);
        $plugin = Plugin::getInstance($this->prefix, null, $db);

        self::assertCount(1, SettingsPage::remoteSkillUrls($plugin));

        SkillRegistry::register($this->makeSkill('invoice_reconciliation', 'Reconcile invoices against orders.', ['invoice', 'order']));

        $remote = SettingsPage::remoteSkills($plugin, [['name' => 'php_best_practices']], true);

        self::assertCount(1, $remote);
        self::assertSame('invoice_reconciliation', $remote[0]['name']);
        self::assertSame('Reconcile invoices against orders.', $remote[0]['description']);
        self::assertSame(['invoice', 'order'], $remote[0]['keywords']);
    }

    public function test_remote_skills_excludes_skills_already_discovered(): void
    {
        $db = $this->makeFullDb();
        $this->seedSettingsInDb([
            'provider' => 'ollama',
            'model' => 'qwen2.5:7b',
            'base_url' => '',
            'remote_skill_urls' => ['https://skills.invalid/collection.md'],
        ]);
        $plugin = Plugin::getInstance($this->prefix, null, $db);

        SkillRegistry::register($this->makeSkill('invoice_reconciliation', 'Reconcile invoices against orders.', ['invoice']));

        $remote = SettingsPage::remoteSkills(
            $plugin,
            [['name' => 'php_best_practices'], ['name' => 'invoice_reconciliation']],
            true,
        );

        self::assertSame([], $remote);
    }

    /**
     * Build a skill double for the registry.
     *
     * @param  string  $name  Skill identifier.
     * @param  string  $description  Skill description.
     * @param  list<string>  $tags  Matching keywords.
     * @return SkillInterface
     */
    private function makeSkill(string $name, string $description, array $tags): SkillInterface
    {
        return new class($name, $description, $tags) implements SkillInterface
        {
            /**
             * @param  string  $name  Skill identifier.
             * @param  string  $description  Skill description.
             * @param  list<string>  $tags  Matching keywords.
             */
            public function __construct(
                private readonly string $name,
                private readonly string $description,
                private readonly array $tags,
            ) {}

            /**
             * @return string
             */
            public function name(): string
            {
                return $this->name;
            }

            /**
             * @return string
             */
            public function description(): string
            {
                return $this->description;
            }

            /**
             * @return list<string>
             */
            public function tags(): array
            {
                return $this->tags;
            }

            /**
             * @return string
             */
            public function content(): string
            {
                return 'Reconcile each invoice line against its order line.';
            }
        };
    }
}

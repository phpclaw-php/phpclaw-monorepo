<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Engine;

use PhpClaw\ClawConfig;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Exceptions\AdapterException;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Guards\MessageLengthGuard;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Memory\MemoryRegistry;
use PhpClaw\Memory\PrivacyAwareMemory;
use PhpClaw\PrestaShop\Contracts\PsDbInterface;
use PhpClaw\PrestaShop\Engine\EngineFactory;
use PhpClaw\PrestaShop\Memory\PsRouterMemory;
use PhpClaw\PrestaShop\Plugin;
use PhpClaw\Providers\OpenAIProvider;
use PhpClaw\Providers\ProviderRegistry;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Tools\Contracts\ToolInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EngineFactory::class)]
final class EngineFactoryCoverageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        GuardRegistry::reset();

        $ref = new \ReflectionProperty(Plugin::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);
    }

    protected function tearDown(): void
    {
        GuardRegistry::reset();

        $ref = new \ReflectionProperty(Plugin::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);

        parent::tearDown();
    }

    private static function wiredStoreMessages(): ?bool
    {
        $router = MemoryRegistry::build('ps_router');
        $conv = (new \ReflectionProperty($router, 'conversations'))->getValue($router);

        return (new \ReflectionProperty($conv, 'storeMessages'))->getValue($conv);
    }

    public function test_boot_registries_sets_store_messages_true_by_default(): void
    {
        $db = $this->createMock(PsDbInterface::class);
        $factory = new EngineFactory([], [], $db, 'ps_');
        $factory->bootRegistries();

        self::assertTrue(self::wiredStoreMessages());
    }

    public function test_boot_registries_with_store_messages_true_string(): void
    {
        $db = $this->createMock(PsDbInterface::class);
        $saved = ['store_messages' => '1'];
        $factory = new EngineFactory($saved, [], $db, 'ps_');
        $factory->bootRegistries();

        self::assertTrue(self::wiredStoreMessages());
    }

    public function test_boot_registries_with_store_messages_false_string(): void
    {
        $db = $this->createMock(PsDbInterface::class);
        $saved = ['store_messages' => '0'];
        $factory = new EngineFactory($saved, [], $db, 'ps_');
        $factory->bootRegistries();

        self::assertFalse(self::wiredStoreMessages());
    }

    public function test_build_with_ollama_provider_clears_api_key(): void
    {
        $db = $this->createMock(PsDbInterface::class);
        putenv('ANTHROPIC_API_KEY');
        putenv('OPENAI_API_KEY');

        $factory = new EngineFactory([
            'provider' => 'ollama',
            'model' => 'qwen2.5:7b',
            'api_key' => 'should-be-cleared',
        ], [], $db, 'ps_');
        $factory->bootRegistries();

        $config = $factory->build()->config();

        self::assertSame('ollama', $config->providerName);
        self::assertSame('', $config->apiKey);
    }

    public function test_build_with_store_messages_true_uses_the_router_memory_unwrapped(): void
    {
        $db = $this->createMock(PsDbInterface::class);

        $factory = new EngineFactory([
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-test-key',
            'store_messages' => '1',
        ], [], $db, 'ps_');
        $factory->bootRegistries();

        self::assertInstanceOf(PsRouterMemory::class, $factory->build()->memory());
    }

    public function test_build_with_store_messages_false_wraps_in_privacy_memory(): void
    {
        $db = $this->createMock(PsDbInterface::class);

        $factory = new EngineFactory([
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-test-key',
            'store_messages' => '0',
        ], [], $db, 'ps_');
        $factory->bootRegistries();

        self::assertInstanceOf(PrivacyAwareMemory::class, $factory->build()->memory());
    }

    public function test_build_with_yes_store_messages_string(): void
    {
        $db = $this->createMock(PsDbInterface::class);
        $saved = [
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-test-key',
            'store_messages' => 'yes',
        ];

        $factory = new EngineFactory($saved, [], $db, 'ps_');
        $factory->bootRegistries();

        $engine = $factory->build();

        self::assertTrue(self::wiredStoreMessages());
    }

    public function test_build_with_custom_provider_and_valid_base_url(): void
    {
        $db = $this->createMock(PsDbInterface::class);

        $factory = new EngineFactory([
            'provider' => 'custom',
            'model' => 'my-model',
            'api_key' => 'sk-custom',
            'base_url' => 'https://api.custom.ai/v1',
        ], [], $db, 'ps_');
        $factory->bootRegistries();

        $config = $factory->build()->config();

        self::assertSame('custom', $config->providerName);
        self::assertSame('my-model', $config->model);
        self::assertSame('sk-custom', $config->apiKey);
    }

    public function test_custom_provider_rejects_external_http_base_url(): void
    {
        $factory = new EngineFactory(['provider' => 'custom', 'base_url' => 'http://api.evil.example/v1'], [], null, 'ps_');
        $method = new \ReflectionMethod(EngineFactory::class, 'customProviderOverride');
        $method->setAccessible(true);

        self::assertNull($method->invoke($factory, 'custom', 'sk', 'model', ''));
    }

    public function test_custom_provider_allows_http_loopback_base_url(): void
    {
        $factory = new EngineFactory(['provider' => 'custom', 'base_url' => 'http://localhost:11434/v1'], [], null, 'ps_');
        $method = new \ReflectionMethod(EngineFactory::class, 'customProviderOverride');
        $method->setAccessible(true);

        $provider = $method->invoke($factory, 'custom', 'sk', 'model', '');

        self::assertInstanceOf(OpenAIProvider::class, $provider);
        self::assertSame('custom', $provider->name());
    }

    public function test_build_with_custom_provider_and_invalid_base_url_skips_override(): void
    {
        $db = $this->createMock(PsDbInterface::class);
        $saved = [
            'provider' => 'custom',
            'model' => 'my-model',
            'api_key' => 'sk-test',
            'base_url' => 'not-a-url',
        ];

        $factory = new EngineFactory($saved, [], $db, 'ps_');
        $factory->bootRegistries();

        $this->expectException(AdapterException::class);

        $factory->build();
    }

    public function test_build_with_empty_base_url_skips_custom_override(): void
    {
        $db = $this->createMock(PsDbInterface::class);
        $saved = [
            'provider' => 'custom',
            'model' => 'my-model',
            'api_key' => 'sk-test-key',
            'base_url' => '',
        ];

        $factory = new EngineFactory($saved, [], $db, 'ps_');
        $factory->bootRegistries();

        $this->expectException(AdapterException::class);

        $factory->build();
    }

    public function test_build_with_non_custom_provider_skips_override(): void
    {
        $db = $this->createMock(PsDbInterface::class);
        $saved = [
            'provider' => 'anthropic',
            'model' => 'claude-haiku-4-5-20251001',
            'api_key' => 'sk-ant-test',
        ];

        $factory = new EngineFactory($saved, [], $db, 'ps_');
        $factory->bootRegistries();

        $engine = $factory->build();

        self::assertNull($engine->config()->providerOverride, 'a named provider must not install a custom override');
        self::assertSame('anthropic', $engine->config()->providerName);
    }

    public function test_build_max_iterations_falls_back_to_the_core_default(): void
    {
        $db = $this->createMock(PsDbInterface::class);

        $factory = new EngineFactory([
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-test-key',
        ], [], $db, 'ps_');
        $factory->bootRegistries();

        self::assertSame(ClawConfig::DEFAULT_MAX_ITERATIONS, $factory->build()->config()->maxIterations);
    }

    public function test_build_with_system_prompt(): void
    {
        $db = $this->createMock(PsDbInterface::class);
        $saved = [
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-test-key',
            'system_prompt' => 'You are a PrestaShop expert.',
        ];

        $factory = new EngineFactory($saved, [], $db, 'ps_');
        $factory->bootRegistries();

        $engine = $factory->build();

        self::assertStringContainsString('You are a PrestaShop expert.', $engine->config()->systemPrompt);
    }

    public function test_fire_extra_event_returns_bucket_when_hook_class_absent(): void
    {
        $factory = new EngineFactory([], [], null, 'ps_');

        $ref = new \ReflectionMethod(EngineFactory::class, 'fireExtraEvent');
        $ref->setAccessible(true);

        $result = $ref->invoke($factory, 'phpclaw/extra/tools', ['existing']);

        self::assertSame(['existing'], $result);
    }

    public function test_register_config_guards_skips_missing_class(): void
    {
        GuardRegistry::reset();
        $config = [
            'guards' => [
                ['class' => 'NonExistentGuard123', 'priority' => 10],
            ],
        ];

        $factory = new EngineFactory([], $config, null, 'ps_');

        $ref = new \ReflectionMethod(EngineFactory::class, 'registerConfigGuards');
        $ref->setAccessible(true);

        $ref->invoke($factory);

        self::assertSame(0, GuardRegistry::count(), 'a class that does not exist must register nothing');
    }

    public function test_register_config_guards_skips_invalid_entries(): void
    {
        GuardRegistry::reset();
        $config = [
            'guards' => [
                'not-an-array',
                ['priority' => 5],
                ['class' => null],
            ],
        ];

        $factory = new EngineFactory([], $config, null, 'ps_');

        $ref = new \ReflectionMethod(EngineFactory::class, 'registerConfigGuards');
        $ref->setAccessible(true);

        $ref->invoke($factory);

        self::assertSame(0, GuardRegistry::count(), 'malformed entries must register nothing');
    }

    public function test_register_config_hooks_skips_invalid_entries(): void
    {
        HookRegistry::reset();
        $config = [
            'hooks' => [
                'not-an-array',
                ['event' => '', 'handler' => 'foo'],
                ['handler' => 'foo'],
            ],
        ];

        $factory = new EngineFactory([], $config, null, 'ps_');

        $ref = new \ReflectionMethod(EngineFactory::class, 'registerConfigHooks');
        $ref->setAccessible(true);

        $ref->invoke($factory);

        self::assertSame(0, HookRegistry::count(), 'malformed hook entries must register nothing');
    }

    public function test_boot_registries_with_cloud_key_empty(): void
    {
        $db = $this->createMock(PsDbInterface::class);
        $saved = ['cloud_key' => ''];
        $factory = new EngineFactory($saved, [], $db, 'ps_');
        $factory->bootRegistries();

        self::assertTrue(MemoryRegistry::has('ps_router'), 'an empty cloud key must not break registry boot');
    }

    public function test_boot_registries_with_cloud_signing_secret(): void
    {
        $db = $this->createMock(PsDbInterface::class);
        $saved = [
            'cloud_key' => '',
            'cloud_signing_secret' => 'my-hmac-secret',
        ];

        $factory = new EngineFactory($saved, [], $db, 'ps_');
        $factory->bootRegistries();

        self::assertTrue(MemoryRegistry::has('ps_router'), 'a cloud signing secret must not break registry boot');
    }

    public function test_boot_registries_with_cloud_disable_array(): void
    {
        $db = $this->createMock(PsDbInterface::class);
        $saved = [
            'cloud_key' => '',
            'cloud_disable' => ['tool.before', 'tool.after'],
        ];

        $factory = new EngineFactory($saved, [], $db, 'ps_');
        $factory->bootRegistries();

        self::assertTrue(MemoryRegistry::has('ps_router'), 'a cloud_disable array must not break registry boot');
    }

    public function test_build_conversation_driver_defaults_to_ps_setting(): void
    {
        $db = $this->createMock(PsDbInterface::class);
        $saved = [
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-test-key',
        ];

        $factory = new EngineFactory($saved, [], $db, 'ps_');
        $factory->bootRegistries();

        $engine = $factory->build();

        self::assertTrue(MemoryRegistry::has('ps_router'));
        self::assertInstanceOf(ClawInterface::class, $engine);
    }

    public function test_register_config_guards_registers_existing_guard_class(): void
    {
        GuardRegistry::reset();
        $config = [
            'guards' => [
                ['class' => MessageLengthGuard::class, 'priority' => 5],
            ],
        ];

        $factory = new EngineFactory([], $config, null, 'ps_');

        $ref = new \ReflectionMethod(EngineFactory::class, 'registerConfigGuards');
        $ref->setAccessible(true);

        $ref->invoke($factory);

        self::assertSame(1, GuardRegistry::count());
        self::assertTrue(GuardRegistry::hasClass(MessageLengthGuard::class));
    }

    public function test_register_config_hooks_registers_valid_hook_entry(): void
    {
        HookRegistry::reset();
        $handler = static function (array $ctx): void {};
        $config = [
            'hooks' => [
                ['event' => 'agent.before', 'handler' => $handler, 'priority' => 10],
            ],
        ];

        $factory = new EngineFactory([], $config, null, 'ps_');

        $ref = new \ReflectionMethod(EngineFactory::class, 'registerConfigHooks');
        $ref->setAccessible(true);

        $ref->invoke($factory);

        self::assertGreaterThan(0, HookRegistry::count(), 'a complete hook entry must register a listener');
    }

    public function test_boot_registries_store_messages_empty_string_defaults_true(): void
    {
        $db = $this->createMock(PsDbInterface::class);
        $saved = ['store_messages' => ''];
        $factory = new EngineFactory($saved, [], $db, 'ps_');
        $factory->bootRegistries();

        self::assertTrue(self::wiredStoreMessages());
    }

    public function test_boot_registries_cloud_disable_as_null(): void
    {
        $db = $this->createMock(PsDbInterface::class);
        $saved = ['cloud_key' => '', 'cloud_disable' => null];
        $factory = new EngineFactory($saved, [], $db, 'ps_');
        $factory->bootRegistries();

        self::assertTrue(MemoryRegistry::has('ps_router'), 'a null cloud_disable must not break registry boot');
    }

    public function test_register_extra_memory_via_reflection(): void
    {
        $factory = new EngineFactory([], [], null, 'ps_');

        $ref = new \ReflectionMethod(EngineFactory::class, 'registerExtraMemory');
        $ref->setAccessible(true);

        $before = count(MemoryRegistry::drivers());
        $ref->invoke($factory);

        self::assertSame($before, count(MemoryRegistry::drivers()), 'no listener is attached, so no extra memory driver may register');
    }

    public function test_register_extra_guards_via_reflection(): void
    {
        $factory = new EngineFactory([], [], null, 'ps_');

        $ref = new \ReflectionMethod(EngineFactory::class, 'registerExtraGuards');
        $ref->setAccessible(true);

        $before = GuardRegistry::count();
        $ref->invoke($factory);

        self::assertSame($before, GuardRegistry::count(), 'no listener is attached, so no extra guard may register');
    }

    public function test_register_extra_hooks_via_reflection(): void
    {
        $factory = new EngineFactory([], [], null, 'ps_');

        $ref = new \ReflectionMethod(EngineFactory::class, 'registerExtraHooks');
        $ref->setAccessible(true);

        $before = HookRegistry::count();
        $ref->invoke($factory);

        self::assertSame($before, HookRegistry::count(), 'no listener is attached, so no extra hook may register');
    }

    public function test_register_extra_skills_via_reflection(): void
    {
        $factory = new EngineFactory([], [], null, 'ps_');

        $ref = new \ReflectionMethod(EngineFactory::class, 'registerExtraSkills');
        $ref->setAccessible(true);

        $before = SkillRegistry::count();
        $ref->invoke($factory);

        self::assertSame($before, SkillRegistry::count(), 'no listener is attached, so no extra skill may register');
    }

    public function test_register_extra_providers_via_reflection(): void
    {
        $factory = new EngineFactory([], [], null, 'ps_');

        $ref = new \ReflectionMethod(EngineFactory::class, 'registerExtraProviders');
        $ref->setAccessible(true);

        $before = ProviderRegistry::names();
        $ref->invoke($factory);

        self::assertSame($before, ProviderRegistry::names(), 'no listener is attached, so no extra provider may register');
    }

    public function test_fire_extra_event_returns_bucket_on_hook_exception(): void
    {
        $factory = new EngineFactory([], [], null, 'ps_');

        $ref = new \ReflectionMethod(EngineFactory::class, 'fireExtraEvent');
        $ref->setAccessible(true);

        $result = $ref->invoke($factory, 'phpclaw/extra/tools', ['initial']);

        self::assertSame(['initial'], $result);
    }

    public function test_custom_provider_override_skips_non_https_url(): void
    {
        $factory = new EngineFactory([], [], null, 'ps_');

        $ref = new \ReflectionMethod(EngineFactory::class, 'customProviderOverride');
        $ref->setAccessible(true);

        $result = $ref->invoke($factory, 'custom', 'key', 'model', 'prompt');

        self::assertNull($result);
    }

    public function test_custom_provider_override_returns_provider_for_valid_url(): void
    {
        $db = $this->createMock(PsDbInterface::class);
        $factory = new EngineFactory(['base_url' => 'https://api.example.com/v1'], [], $db, 'ps_');

        $ref = new \ReflectionMethod(EngineFactory::class, 'customProviderOverride');
        $ref->setAccessible(true);

        $provider = $ref->invoke($factory, 'custom', 'sk-test', 'gpt-4', 'You are helpful.');

        self::assertInstanceOf(OpenAIProvider::class, $provider);
        self::assertSame('custom', $provider->name());
    }

    public function test_build_tools_returns_every_prestashop_and_core_tool(): void
    {
        $db = $this->createMock(PsDbInterface::class);
        $factory = new EngineFactory(['provider' => 'openai', 'model' => 'gpt-4o-mini'], [], $db, 'ps_');
        $factory->bootRegistries();

        $ref = new \ReflectionMethod(EngineFactory::class, 'buildTools');
        $ref->setAccessible(true);

        self::assertEqualsCanonicalizing([
            'ps_product', 'ps_order', 'ps_customer', 'ps_category', 'ps_manufacturer',
            'ps_cart', 'ps_stock', 'ps_coupon', 'ps_module', 'ps_report',
            'ps_config', 'ps_employee', 'database', 'ps_log',
            'code_search', 'project_info', 'file_read', 'http_request',
            'shell_exec', 'file_edit', 'file_write',
        ], array_map(static fn (ToolInterface $t): string => $t->name(), $ref->invoke($factory)));
    }

    public function test_a_saved_memory_driver_is_ignored_and_the_router_is_always_built(): void
    {
        $db = $this->createMock(PsDbInterface::class);

        $factory = new EngineFactory([
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-test-key',
            'store_messages' => '1',
            'memory_driver' => 'ps_setting',
        ], [], $db, 'ps_');
        $factory->bootRegistries();

        self::assertInstanceOf(PsRouterMemory::class, $factory->build()->memory());
    }
}

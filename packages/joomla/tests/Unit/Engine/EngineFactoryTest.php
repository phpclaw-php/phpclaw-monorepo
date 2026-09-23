<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Unit\Engine;

use Joomla\Registry\Registry;
use PhpClaw\Joomla\Component\Administrator\Engine\EngineBootstrapper;
use PhpClaw\Joomla\Component\Administrator\Engine\EngineFactory;
use PhpClaw\Joomla\Component\Administrator\Engine\PhpClawConfig;
use PhpClaw\Joomla\Component\Administrator\Engine\ToolBuilder;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Providers\OpenAIProvider;
use PhpClaw\Providers\ProviderCatalogue;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\ToolProfileResolver;
use PHPUnit\Framework\TestCase;

final class EngineFactoryTest extends TestCase
{
    public function test_phpclaw_config_from_registry_resolves_api_key(): void
    {
        $params = new Registry(['api_key' => 'param-api-key']);
        $config = PhpClawConfig::fromRegistry($params);

        $this->assertSame('param-api-key', $config->apiKey);
    }

    public function test_phpclaw_config_from_registry_falls_back_to_legacy_param(): void
    {
        $params = new Registry(['api_key' => '', 'anthropic_api_key' => 'legacy-anthropic']);
        $config = PhpClawConfig::fromRegistry($params);

        $this->assertSame('legacy-anthropic', $config->apiKey);
    }

    public function test_phpclaw_config_from_registry_single_param_overrides_legacy(): void
    {
        $params = new Registry(['api_key' => 'single', 'anthropic_api_key' => 'legacy']);
        $config = PhpClawConfig::fromRegistry($params);

        $this->assertSame('single', $config->apiKey);
    }

    public function test_phpclaw_config_from_registry_returns_empty_api_key_when_none_found(): void
    {
        $config = PhpClawConfig::fromRegistry(new Registry([]));

        $this->assertSame('', $config->apiKey);
    }

    public function test_phpclaw_config_from_registry_parses_shell_allowlist_csv(): void
    {
        $params = new Registry(['shell_allowlist' => 'ls,pwd,php']);
        $config = PhpClawConfig::fromRegistry($params);

        $this->assertSame(['ls', 'pwd', 'php'], $config->shellAllowlist);
    }

    public function test_phpclaw_config_from_registry_shell_allowlist_uses_default(): void
    {
        $config = PhpClawConfig::fromRegistry(new Registry([]));

        $this->assertContains('ls', $config->shellAllowlist);
        $this->assertNotContains('php', $config->shellAllowlist);
        $this->assertContains('whoami', $config->shellAllowlist);
    }

    public function test_phpclaw_config_from_registry_store_messages_defaults_true(): void
    {
        $config = PhpClawConfig::fromRegistry(new Registry([]));

        $this->assertTrue($config->storeMessages);
    }

    public function test_phpclaw_config_from_registry_max_iterations_default_is_20(): void
    {
        $config = PhpClawConfig::fromRegistry(new Registry([]));

        $this->assertSame(20, $config->maxIterations);
    }

    public function test_engine_bootstrapper_is_final_class(): void
    {
        $ref = new \ReflectionClass(EngineBootstrapper::class);

        $this->assertTrue($ref->isFinal());
    }

    public function test_tool_builder_is_final_class(): void
    {
        $ref = new \ReflectionClass(ToolBuilder::class);

        $this->assertTrue($ref->isFinal());
    }

    public function test_engine_factory_is_final_class(): void
    {
        $ref = new \ReflectionClass(EngineFactory::class);

        $this->assertTrue($ref->isFinal());
    }

    public function test_engine_bootstrapper_boot_method_is_public_instance(): void
    {
        $ref = new \ReflectionClass(EngineBootstrapper::class);
        $method = $ref->getMethod('boot');

        $this->assertTrue($method->isPublic());
        $this->assertFalse($method->isStatic());
    }

    public function test_tool_builder_build_method_is_public_instance(): void
    {
        $ref = new \ReflectionClass(ToolBuilder::class);
        $method = $ref->getMethod('build');

        $this->assertTrue($method->isPublic());
        $this->assertFalse($method->isStatic());
    }

    public function test_engine_factory_delegates_to_engine_bootstrapper_not_bootregistries(): void
    {
        $ref = new \ReflectionClass(EngineFactory::class);

        $this->assertFalse(
            $ref->hasMethod('bootRegistries'),
            'EngineFactory::bootRegistries() must not exist after refactor - delegation to EngineBootstrapper::boot()',
        );
    }

    public function test_engine_factory_delegates_to_tool_builder_not_buildtools(): void
    {
        $ref = new \ReflectionClass(EngineFactory::class);

        $this->assertFalse(
            $ref->hasMethod('buildTools'),
            'EngineFactory::buildTools() must not exist after refactor - delegation to ToolBuilder::build()',
        );
    }

    public function test_engine_bootstrapper_owns_memory_driver_registration(): void
    {
        $factoryRef = new \ReflectionClass(EngineFactory::class);
        $bootstrapperRef = new \ReflectionClass(EngineBootstrapper::class);

        $this->assertFalse($factoryRef->hasMethod('registerMemoryDrivers'));
        $this->assertTrue($bootstrapperRef->hasMethod('registerMemoryDrivers'));
    }

    public function test_tool_builder_owns_builtin_tools(): void
    {
        $factoryRef = new \ReflectionClass(EngineFactory::class);
        $toolBuilderRef = new \ReflectionClass(ToolBuilder::class);

        $this->assertFalse($factoryRef->hasMethod('builtinTools'));
        $this->assertTrue($toolBuilderRef->hasMethod('builtinTools'));
    }

    public function test_provider_catalogue_lists_all_presets_and_natives(): void
    {
        $keys = array_keys(ProviderCatalogue::all());

        foreach (['anthropic', 'gemini', 'openai', 'groq', 'deepseek', 'mistral', 'ollama', 'custom'] as $slug) {
            $this->assertContains($slug, $keys);
        }
    }

    public function test_provider_catalogue_presets_use_openai_provider_class(): void
    {
        $catalogue = ProviderCatalogue::all();

        $this->assertSame(OpenAIProvider::class, $catalogue['groq']['class']);
        $this->assertSame(OpenAIProvider::class, $catalogue['ollama']['class']);
    }

    public function test_custom_provider_override_builds_openai_provider_for_custom(): void
    {
        $config = new PhpClawConfig(
            provider: 'custom',
            model: 'MiniMax-M2',
            apiKey: 'sk-custom',
            baseUrl: 'https://api.minimax.io/v1/chat/completions',
        );
        $provider = $this->invokeCustomProviderOverride($config);

        $this->assertInstanceOf(OpenAIProvider::class, $provider);
        $this->assertSame('custom', $provider->name());
        $this->assertSame('https://api.minimax.io/v1/chat/completions', $provider->endpoint());
    }

    public function test_custom_provider_override_returns_null_for_non_custom(): void
    {
        $config = new PhpClawConfig(
            provider: 'groq',
            baseUrl: 'https://api.minimax.io/v1/chat/completions',
        );

        $this->assertNull($this->invokeCustomProviderOverride($config));
    }

    public function test_base_url_points_ollama_at_a_non_default_host(): void
    {
        $config = new PhpClawConfig(
            provider: 'ollama',
            model: 'qwen2.5:7b',
            baseUrl: 'https://ollama.internal.example/v1/chat/completions',
        );

        $provider = $this->invokeCustomProviderOverride($config);

        $this->assertInstanceOf(OpenAIProvider::class, $provider);
        $this->assertSame('ollama', $provider->name());
        $this->assertSame('https://ollama.internal.example/v1/chat/completions', $provider->endpoint());
    }

    public function test_ollama_without_a_base_url_keeps_the_preset_host(): void
    {
        $config = new PhpClawConfig(provider: 'ollama', model: 'qwen2.5:7b');

        $this->assertNull(
            $this->invokeCustomProviderOverride($config),
            'With no Base URL set, Ollama must fall through to the catalogue preset.',
        );
    }

    public function test_a_native_provider_is_never_overridden_by_base_url(): void
    {
        foreach (['anthropic', 'gemini'] as $slug) {
            $config = new PhpClawConfig(
                provider: $slug,
                baseUrl: 'https://someone-elses-endpoint.example/v1/chat/completions',
            );

            $this->assertNull(
                $this->invokeCustomProviderOverride($config),
                $slug.' speaks its own wire format and must not be swapped for an OpenAI client.',
            );
        }
    }

    public function test_custom_provider_override_returns_null_for_empty_base_url(): void
    {
        $config = new PhpClawConfig(provider: 'custom', baseUrl: '');

        $this->assertNull($this->invokeCustomProviderOverride($config));
    }

    public function test_custom_provider_override_returns_null_for_non_http_base_url(): void
    {
        $config = new PhpClawConfig(provider: 'custom', baseUrl: 'file:///etc/passwd');

        $this->assertNull($this->invokeCustomProviderOverride($config));
    }

    public function test_tool_groups_match_the_real_tools_builtin_tools_registers(): void
    {
        $groups = ToolBuilder::TOOL_GROUPS;

        self::assertSame(['group:content', 'group:admin', 'group:system'], array_keys($groups));
        self::assertSame(['joomla_articles', 'joomla_categories'], $groups['group:content']);
        self::assertSame(['joomla_users', 'joomla_extensions'], $groups['group:admin']);
        self::assertContains('joomla_database_query', $groups['group:system']);
        self::assertContains('joomla_zip_extension', $groups['group:system']);
        self::assertContains('shell_exec', $groups['group:system']);
    }

    public function test_tool_deny_group_content_removes_article_and_category_tools(): void
    {
        $tools = [
            $this->fakeTool('joomla_articles'),
            $this->fakeTool('joomla_categories'),
            $this->fakeTool('joomla_users'),
            $this->fakeTool('joomla_database_query'),
        ];

        $filtered = ToolProfileResolver::filter(
            $tools,
            deny: ['group:content'],
            groups: ToolBuilder::TOOL_GROUPS,
        );

        $names = array_map(static fn ($t) => $t->name(), $filtered);

        self::assertNotContains('joomla_articles', $names);
        self::assertNotContains('joomla_categories', $names);
        self::assertContains('joomla_users', $names);
        self::assertContains('joomla_database_query', $names);
    }

    private function fakeTool(string $name): object
    {
        return new class($name) implements ToolInterface
        {
            public function __construct(private readonly string $toolName) {}

            public function name(): string
            {
                return $this->toolName;
            }

            public function description(): string
            {
                return 'fake';
            }

            public function inputSchema(): array
            {
                return [];
            }

            public function execute(array $input): string
            {
                return '';
            }
        };
    }

    private function invokeCustomProviderOverride(PhpClawConfig $config): ?ProviderInterface
    {
        $ref = new \ReflectionClass(EngineFactory::class);
        $method = $ref->getMethod('customProviderOverride');

        return $method->invoke(null, $config);
    }
}

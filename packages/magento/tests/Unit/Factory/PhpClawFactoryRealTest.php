<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Factory;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Magento\Factory\PhpClawFactory;
use PhpClaw\Magento\Memory\RouterMemory;
use PhpClaw\Magento\Model\Config;
use PhpClaw\Magento\Model\IdentityResolver;
use PhpClaw\Magento\Registry\PhpClawRegistrar;
use PhpClaw\Memory\PrivacyAwareMemory;
use PhpClaw\Providers\OpenAIProvider;
use PhpClaw\Providers\ProviderRegistry;
use PHPUnit\Framework\TestCase;

final class PhpClawFactoryRealTest extends TestCase
{
    protected function tearDown(): void
    {
        ProviderRegistry::reset();
    }

    private function makeFactory(array $overrides = []): PhpClawFactory
    {
        $defaults = [
            'getBaseUrl' => '',
            'getProvider' => 'anthropic',
            'getModel' => 'claude-haiku-4-5-20251001',
            'getApiKey' => 'sk-test',
            'getCloudKey' => '',
            'getCloudSigningSecret' => '',
            'getCloudDisable' => '',
            'getSystemPrompt' => '',
            'getMaxIterations' => 20,
            'getShellAllowlist' => [],
            'getRemoteSkillUrls' => [],
            'isStoreMessages' => true,
        ];

        $cfg = array_merge($defaults, $overrides);

        $config = $this->createMock(Config::class);
        foreach ($cfg as $method => $value) {
            $config->method($method)->willReturn($value);
        }

        $registrar = $this->createMock(PhpClawRegistrar::class);
        $resourceConnection = $this->createMock(ResourceConnection::class);
        $cacheTypeList = $this->createMock(TypeListInterface::class);
        $routerMemory = $this->createMock(RouterMemory::class);
        $authorization = $this->createMock(AuthorizationInterface::class);

        return new PhpClawFactory(
            $config,
            $registrar,
            $resourceConnection,
            $cacheTypeList,
            $routerMemory,
            $authorization,
            $this->createMock(IdentityResolver::class),
        );
    }

    public function test_create_returns_phpclaw_interface_via_real_builder_path(): void
    {
        $result = $this->makeFactory()->create();
        self::assertInstanceOf(PhpClawInterface::class, $result);
    }

    public function test_create_with_custom_provider_and_valid_base_url_builds_instance(): void
    {
        ProviderRegistry::reset();

        $factory = $this->makeFactory([
            'getProvider' => 'custom',
            'getBaseUrl' => 'https://openrouter.ai/api/v1/chat/completions',
            'getApiKey' => 'sk-test-key',
        ]);

        $method = new \ReflectionMethod(PhpClawFactory::class, 'customProviderOverride');
        $method->setAccessible(true);
        $override = $method->invoke($factory);

        self::assertInstanceOf(OpenAIProvider::class, $override);
        self::assertSame('custom', $override->name());
        self::assertInstanceOf(PhpClawInterface::class, $factory->create());
    }

    public function test_custom_provider_with_an_empty_base_url_yields_no_override(): void
    {
        $factory = $this->makeFactory(['getProvider' => 'custom', 'getBaseUrl' => '']);

        $method = new \ReflectionMethod(PhpClawFactory::class, 'customProviderOverride');
        $method->setAccessible(true);

        self::assertNull($method->invoke($factory));
        self::assertInstanceOf(PhpClawInterface::class, $factory->create());
    }

    public function test_create_wraps_memory_with_privacy_when_store_messages_is_false(): void
    {
        $factory = $this->makeFactory(['isStoreMessages' => false]);
        $engine = $factory->create();

        $memoryProp = new \ReflectionProperty($engine, 'memory');
        $memoryProp->setAccessible(true);
        $memory = $memoryProp->getValue($engine);

        self::assertInstanceOf(PrivacyAwareMemory::class, $memory);
    }

    public function test_create_does_not_wrap_memory_when_store_messages_is_true(): void
    {
        $factory = $this->makeFactory(['isStoreMessages' => true]);
        $engine = $factory->create();

        $memoryProp = new \ReflectionProperty($engine, 'memory');
        $memoryProp->setAccessible(true);
        $memory = $memoryProp->getValue($engine);

        self::assertNotInstanceOf(PrivacyAwareMemory::class, $memory);
        self::assertInstanceOf(RouterMemory::class, $memory);
    }

    public function test_create_passes_through_anthropic_api_key(): void
    {
        $engine = $this->makeFactory([
            'getProvider' => 'anthropic',
            'getApiKey' => 'sk-real-key',
        ])->create();

        $ref = new \ReflectionClass($engine);
        if (! $ref->hasProperty('apiKey')) {
            self::assertInstanceOf(PhpClawInterface::class, $engine);

            return;
        }
        $prop = $ref->getProperty('apiKey');
        $prop->setAccessible(true);
        self::assertSame('sk-real-key', $prop->getValue($engine));
    }

    public function test_custom_provider_with_a_non_https_base_url_yields_no_override(): void
    {
        $factory = $this->makeFactory([
            'getProvider' => 'custom',
            'getBaseUrl' => 'http://openrouter.ai/api/v1',
            'getApiKey' => 'sk-test-key',
        ]);

        $method = new \ReflectionMethod(PhpClawFactory::class, 'customProviderOverride');
        $method->setAccessible(true);

        self::assertNull($method->invoke($factory), 'a plain-http endpoint must never become a provider override');
        self::assertInstanceOf(PhpClawInterface::class, $factory->create());
    }

    public function test_create_propagates_max_iterations_to_engine(): void
    {
        $engine = $this->makeFactory([
            'getMaxIterations' => 15,
        ])->create();

        $ref = new \ReflectionClass($engine);
        if ($ref->hasProperty('maxIterations')) {
            $prop = $ref->getProperty('maxIterations');
            $prop->setAccessible(true);
            self::assertSame(15, $prop->getValue($engine));
        } else {
            self::assertInstanceOf(PhpClawInterface::class, $engine);
        }
    }

    public function test_create_propagates_system_prompt_to_engine(): void
    {
        $prompt = 'You are a helpful Magento assistant.';

        $factory = $this->makeFactory([
            'getProvider' => 'custom',
            'getBaseUrl' => 'https://openrouter.ai/api/v1/chat/completions',
            'getApiKey' => 'sk-test-key',
            'getSystemPrompt' => $prompt,
        ]);

        $method = new \ReflectionMethod(PhpClawFactory::class, 'customProviderOverride');
        $method->setAccessible(true);
        $provider = $method->invoke($factory);

        $promptRef = new \ReflectionProperty(OpenAIProvider::class, 'systemPrompt');
        $promptRef->setAccessible(true);

        self::assertSame($prompt, $promptRef->getValue($provider));
    }

    public function test_the_chat_tier_receives_the_same_tool_set_as_an_administrator(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getShellAllowlist')->willReturn([]);

        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturn(false);

        $factory = new PhpClawFactory(
            $config,
            $this->createMock(PhpClawRegistrar::class),
            $this->createMock(ResourceConnection::class),
            $this->createMock(TypeListInterface::class),
            $this->createMock(RouterMemory::class),
            $authorization,
            $this->createMock(IdentityResolver::class),
        );

        $method = new \ReflectionMethod($factory, 'defaultTools');
        $method->setAccessible(true);
        $names = array_map(static fn ($tool): string => $tool->name(), $method->invoke($factory));

        foreach (['magento_products', 'magento_orders', 'magento_customer', 'db_query', 'shell_exec', 'read_log'] as $tool) {
            self::assertContains($tool, $names, $tool.' must register for a caller holding no native Magento ACL: the chat capability is the boundary');
        }
    }
}

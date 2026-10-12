<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Factory;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;
use PhpClaw\Magento\Factory\PhpClawFactory;
use PhpClaw\Magento\Memory\RouterMemory;
use PhpClaw\Magento\Model\Config;
use PhpClaw\Magento\Model\IdentityResolver;
use PhpClaw\Magento\Registry\PhpClawRegistrar;
use PhpClaw\Magento\Service\MagentoCache;
use PhpClaw\Magento\Tests\Unit\Support\ArrayAppCache;
use PhpClaw\Providers\OpenAIProvider;
use PhpClaw\Providers\ProviderRegistry;
use PHPUnit\Framework\TestCase;

final class PhpClawFactorySessionTest extends TestCase
{
    protected function tearDown(): void
    {
        ProviderRegistry::reset();
    }

    private function makeFactory(array $overrides = []): PhpClawFactory
    {
        $settings = array_merge([
            'getBaseUrl' => '',
            'getProvider' => 'anthropic',
            'getModel' => 'claude-haiku-4-5-20251001',
            'getApiKey' => 'sk-test-key',
            'getCloudKey' => '',
            'getCloudDisable' => '',
            'getSystemPrompt' => '',
            'getMaxIterations' => 20,
            'getShellAllowlist' => [],
            'getRemoteSkillUrls' => [],
            'getToolDeny' => [],
            'getFallbackProvider' => '',
            'isStoreMessages' => true,
        ], $overrides);

        $config = $this->createMock(Config::class);
        foreach ($settings as $method => $value) {
            $config->method($method)->willReturn($value);
        }

        return new PhpClawFactory(
            $config,
            $this->createMock(PhpClawRegistrar::class),
            $this->createMock(ResourceConnection::class),
            $this->createMock(TypeListInterface::class),
            $this->createMock(RouterMemory::class),
            $this->createMock(AuthorizationInterface::class),
            $this->createMock(IdentityResolver::class),
            new MagentoCache(new ArrayAppCache),
        );
    }

    private function customOverride(PhpClawFactory $factory, string $provider, string $model): ?OpenAIProvider
    {
        return (new \ReflectionMethod(PhpClawFactory::class, 'customProviderOverride'))->invoke($factory, $provider, $model);
    }

    public function test_create_for_session_uses_the_provider_and_model_given(): void
    {
        $config = $this->makeFactory()->createForSession(provider: 'openai', model: 'gpt-5-mini')->config();

        self::assertSame('openai', $config->providerName);
        self::assertSame('gpt-5-mini', $config->model);
    }

    public function test_create_for_session_without_overrides_uses_the_saved_settings(): void
    {
        $config = $this->makeFactory()->createForSession()->config();

        self::assertSame('anthropic', $config->providerName);
        self::assertSame('claude-haiku-4-5-20251001', $config->model);
    }

    public function test_create_for_session_with_only_a_model_keeps_the_saved_provider(): void
    {
        $config = $this->makeFactory()->createForSession(model: 'claude-sonnet-5-5')->config();

        self::assertSame('anthropic', $config->providerName);
        self::assertSame('claude-sonnet-5-5', $config->model);
    }

    public function test_create_still_uses_the_saved_settings(): void
    {
        $config = $this->makeFactory()->create()->config();

        self::assertSame('anthropic', $config->providerName);
        self::assertSame('claude-haiku-4-5-20251001', $config->model);
    }

    public function test_the_custom_base_url_is_dropped_when_the_session_moves_off_custom(): void
    {
        $factory = $this->makeFactory(['getProvider' => 'custom', 'getBaseUrl' => 'https://llm.example.com/v1']);

        self::assertNull($this->customOverride($factory, 'openai', 'gpt-5-mini'));
    }

    public function test_the_custom_base_url_uses_the_session_model(): void
    {
        $factory = $this->makeFactory(['getProvider' => 'custom', 'getBaseUrl' => 'https://llm.example.com/v1']);

        $override = $this->customOverride($factory, 'custom', 'my-model');

        self::assertInstanceOf(OpenAIProvider::class, $override);
        self::assertSame('my-model', $override->model());
    }
}

<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Factory;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;
use PhpClaw\Claw;
use PhpClaw\ClawConfig;
use PhpClaw\Magento\Factory\PhpClawFactory;
use PhpClaw\Magento\Memory\RouterMemory;
use PhpClaw\Magento\Model\Config;
use PhpClaw\Magento\Model\IdentityResolver;
use PhpClaw\Magento\Registry\PhpClawRegistrar;
use PhpClaw\Magento\Service\MagentoCache;
use PhpClaw\Magento\Tests\Unit\Support\ArrayAppCache;
use PhpClaw\Magento\Tests\Unit\Support\PinsProviderEnv;
use PhpClaw\Providers\ProviderRegistry;
use PHPUnit\Framework\TestCase;

final class PhpClawFactoryPrimitivesTest extends TestCase
{
    use PinsProviderEnv;

    private MagentoCache $store;

    protected function setUp(): void
    {
        $this->store = new MagentoCache(new ArrayAppCache);
    }

    protected function tearDown(): void
    {
        ProviderRegistry::reset();
    }

    public function test_agent_primitives_stay_off_by_default(): void
    {
        $config = $this->engineConfig([]);

        self::assertSame([], $config->fallbacks);
        self::assertSame(0, $config->requestsPerMinute);
        self::assertNull($config->rateLimitStore);
        self::assertNull($config->responseCache);
        self::assertSame(0, $config->maxTokenBudget);
    }

    public function test_a_fallback_with_the_main_tool_format_is_applied(): void
    {
        $config = $this->engineConfig(['getFallbackProvider' => 'groq', 'getFallbackModel' => 'llama-3.1-8b-instant', 'getFallbackApiKey' => 'gsk-key']);

        self::assertSame([['provider' => 'groq', 'model' => 'llama-3.1-8b-instant', 'apiKey' => 'gsk-key']], $config->fallbacks);
    }

    public function test_a_fallback_with_another_tool_format_is_skipped(): void
    {
        self::assertSame([], $this->engineConfig(['getFallbackProvider' => 'anthropic', 'getFallbackApiKey' => 'sk-ant'])->fallbacks);
    }

    public function test_a_custom_fallback_is_skipped(): void
    {
        self::assertSame([], $this->engineConfig(['getProvider' => 'openai', 'getApiKey' => 'sk', 'getFallbackProvider' => 'custom'])->fallbacks);
    }

    public function test_an_empty_main_provider_checks_the_fallback_against_the_auto_detected_one(): void
    {
        $applied = $this->withEnvProvider('anthropic', fn (): ClawConfig => $this->engineConfig(['getProvider' => '', 'getModel' => '', 'getFallbackProvider' => 'anthropic', 'getFallbackApiKey' => 'sk-ant']));
        $skipped = $this->withEnvProvider('anthropic', fn (): ClawConfig => $this->engineConfig(['getProvider' => '', 'getModel' => '', 'getFallbackProvider' => 'groq', 'getFallbackApiKey' => 'gsk-key']));

        self::assertSame([['provider' => 'anthropic', 'model' => '', 'apiKey' => 'sk-ant']], $applied->fallbacks);
        self::assertSame([], $skipped->fallbacks);
    }

    public function test_the_rate_limit_uses_the_magento_cache_store(): void
    {
        $config = $this->engineConfig(['getRateLimitRpm' => 30]);

        self::assertSame(30, $config->requestsPerMinute);
        self::assertSame($this->store, $config->rateLimitStore);
    }

    public function test_the_response_cache_uses_the_magento_cache_store_and_ttl(): void
    {
        $config = $this->engineConfig(['isResponseCache' => true, 'getResponseCacheTtl' => 600]);

        self::assertSame($this->store, $config->responseCache);
        self::assertSame(600, $config->responseCacheTtl);
    }

    public function test_a_disabled_response_cache_stays_off(): void
    {
        self::assertNull($this->engineConfig(['isResponseCache' => false, 'getResponseCacheTtl' => 600])->responseCache);
    }

    public function test_the_token_budget_is_applied(): void
    {
        self::assertSame(5000, $this->engineConfig(['getMaxTokenBudget' => 5000])->maxTokenBudget);
    }

    private function engineConfig(array $overrides): ClawConfig
    {
        $settings = $overrides + [
            'getBaseUrl' => '',
            'getProvider' => 'ollama',
            'getModel' => 'qwen2.5:7b',
            'getApiKey' => '',
            'getCloudKey' => '',
            'getCloudDisable' => '',
            'getSystemPrompt' => '',
            'getMaxIterations' => 10,
            'getShellAllowlist' => [],
            'getRemoteSkillUrls' => [],
            'isStoreMessages' => false,
            'getFallbackProvider' => '',
            'getFallbackModel' => '',
            'getFallbackApiKey' => '',
            'getRateLimitRpm' => 0,
            'isResponseCache' => false,
            'getResponseCacheTtl' => 3600,
            'getMaxTokenBudget' => 0,
        ];

        $config = $this->createMock(Config::class);
        foreach ($settings as $method => $value) {
            $config->method($method)->willReturn($value);
        }

        $factory = new PhpClawFactory(
            $config,
            $this->createMock(PhpClawRegistrar::class),
            $this->createMock(ResourceConnection::class),
            $this->createMock(TypeListInterface::class),
            $this->createMock(RouterMemory::class),
            $this->createMock(AuthorizationInterface::class),
            $this->createMock(IdentityResolver::class),
            $this->store,
        );

        $agent = $factory->create();
        self::assertInstanceOf(Claw::class, $agent);

        return $agent->config();
    }
}

<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\Engine;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\Exceptions\TokenBudgetExceededException;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Memory\MemoryRegistry;
use PhpClaw\WordPress\Engine\EngineFactory;
use PhpClaw\WordPress\Support\TransientCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EngineFactory::class)]
final class EngineFactoryAgentPrimitivesTest extends TestCase
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

    public function test_build_applies_no_agent_primitive_when_every_option_is_off(): void
    {
        $engine = EngineFactory::build(
            config: [],
            saved: ['provider' => 'ollama', 'model' => 'qwen2.5:7b'],
        );

        $config = $engine->config();

        self::assertSame([], $config->fallbacks);
        self::assertSame(0, $config->requestsPerMinute);
        self::assertNull($config->responseCache);
        self::assertSame(0, $config->maxTokenBudget);
    }

    public function test_build_applies_fallback_provider_model_and_key_to_config(): void
    {
        $engine = EngineFactory::build(
            config: [],
            saved: [
                'provider' => 'openai',
                'api_key' => 'sk-primary-test',
                'fallback_provider' => 'groq',
                'fallback_model' => 'llama-3.1-8b-instant',
                'fallback_api_key' => 'gk-fallback-key',
            ],
        );

        self::assertSame(
            [['provider' => 'groq', 'model' => 'llama-3.1-8b-instant', 'apiKey' => 'gk-fallback-key']],
            $engine->config()->fallbacks,
        );
    }

    public function test_build_skips_fallback_when_fallback_provider_is_blank(): void
    {
        $engine = EngineFactory::build(
            config: [],
            saved: ['provider' => 'openai', 'api_key' => 'sk-primary-test', 'fallback_provider' => ''],
        );

        self::assertSame([], $engine->config()->fallbacks);
    }

    public function test_build_applies_rate_limit_when_positive(): void
    {
        $engine = EngineFactory::build(
            config: [],
            saved: ['provider' => 'ollama', 'rate_limit_rpm' => 30],
        );

        self::assertSame(30, $engine->config()->requestsPerMinute);
    }

    public function test_build_skips_rate_limit_when_zero(): void
    {
        $engine = EngineFactory::build(
            config: [],
            saved: ['provider' => 'ollama', 'rate_limit_rpm' => 0],
        );

        self::assertSame(0, $engine->config()->requestsPerMinute);
        self::assertNull($engine->config()->rateLimitStore);
    }

    public function test_build_applies_a_shared_transient_store_when_rate_limit_is_positive(): void
    {
        $engine = EngineFactory::build(
            config: [],
            saved: ['provider' => 'ollama', 'rate_limit_rpm' => 30],
        );

        self::assertInstanceOf(TransientCache::class, $engine->config()->rateLimitStore);
    }

    public function test_build_applies_response_cache_with_configured_ttl(): void
    {
        $engine = EngineFactory::build(
            config: [],
            saved: ['provider' => 'ollama', 'response_cache' => '1', 'response_cache_ttl' => 120],
        );

        self::assertInstanceOf(TransientCache::class, $engine->config()->responseCache);
        self::assertSame(120, $engine->config()->responseCacheTtl);
    }

    public function test_build_applies_response_cache_default_ttl_when_ttl_missing(): void
    {
        $engine = EngineFactory::build(
            config: [],
            saved: ['provider' => 'ollama', 'response_cache' => '1'],
        );

        self::assertInstanceOf(TransientCache::class, $engine->config()->responseCache);
        self::assertSame(3600, $engine->config()->responseCacheTtl);
    }

    public function test_build_skips_response_cache_when_off(): void
    {
        $engine = EngineFactory::build(
            config: [],
            saved: ['provider' => 'ollama', 'response_cache' => '0'],
        );

        self::assertNull($engine->config()->responseCache);
    }

    public function test_build_applies_max_token_budget_when_positive(): void
    {
        $engine = EngineFactory::build(
            config: [],
            saved: ['provider' => 'ollama', 'max_token_budget' => 1],
        );

        self::assertSame(1, $engine->config()->maxTokenBudget);
    }

    public function test_build_skips_max_token_budget_when_zero(): void
    {
        $engine = EngineFactory::build(
            config: [],
            saved: ['provider' => 'ollama', 'max_token_budget' => 0],
        );

        self::assertSame(0, $engine->config()->maxTokenBudget);
    }

    public function test_max_token_budget_stops_the_run_before_any_provider_call(): void
    {
        $engine = EngineFactory::build(
            config: [],
            saved: ['provider' => 'ollama', 'model' => 'qwen2.5:7b', 'max_token_budget' => 1],
        );

        $this->expectException(TokenBudgetExceededException::class);

        $engine->send('a real prompt that costs more than one token');
    }

    public function test_max_tools_per_turn_from_config_overrides_the_profile(): void
    {
        $engine = EngineFactory::build(config: [], saved: ['provider' => 'ollama', 'model' => 'qwen2.5:7b', 'max_tools_per_turn' => 3]);

        self::assertSame(3, $engine->config()->maxToolsPerTurn);
    }

    public function test_minus_one_from_config_removes_the_cap(): void
    {
        $engine = EngineFactory::build(config: [], saved: ['provider' => 'ollama', 'model' => 'qwen2.5:7b', 'max_tools_per_turn' => -1]);

        self::assertSame(-1, $engine->config()->maxToolsPerTurn);
    }

    public function test_zero_keeps_the_profile_limit(): void
    {
        $engine = EngineFactory::build(config: [], saved: ['provider' => 'ollama', 'model' => 'qwen2.5:7b', 'max_tools_per_turn' => 0]);

        self::assertSame(5, $engine->config()->maxToolsPerTurn);
    }

    public function test_a_missing_max_tools_key_keeps_the_profile_limit(): void
    {
        $engine = EngineFactory::build(config: [], saved: ['provider' => 'ollama', 'model' => 'qwen2.5:7b']);

        self::assertSame(5, $engine->config()->maxToolsPerTurn);
    }

    public function test_always_include_tools_from_config_reach_the_engine(): void
    {
        $engine = EngineFactory::build(config: [], saved: ['provider' => 'ollama', 'always_include_tools' => ['wp_query', 'wp_users']]);

        self::assertSame(['wp_query', 'wp_users'], $engine->config()->alwaysIncludeTools);
    }

    public function test_a_missing_always_include_key_means_an_empty_list(): void
    {
        $engine = EngineFactory::build(config: [], saved: ['provider' => 'ollama']);

        self::assertSame([], $engine->config()->alwaysIncludeTools);
    }

    public function test_always_include_and_tool_deny_on_the_same_name_keeps_it_denied(): void
    {
        $engine = EngineFactory::build(config: ['tool_deny' => ['wp_query']], saved: ['provider' => 'ollama', 'always_include_tools' => ['wp_query']]);
        $names = array_map(static fn ($tool): string => $tool->name(), $engine->config()->tools);

        self::assertContains('wp_users', $names, 'precondition: wp_users is a built-in tool that is not denied, so the tool list is not empty');
        self::assertNotContains('wp_query', $names);
    }
}

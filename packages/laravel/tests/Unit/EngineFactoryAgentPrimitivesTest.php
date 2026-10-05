<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Orchestra\Testbench\TestCase;
use PhpClaw\Exceptions\TokenBudgetExceededException;
use PhpClaw\Laravel\Engine\EngineFactory;
use PhpClaw\Laravel\PhpClawServiceProvider;
use PhpClaw\Skills\SkillRegistry;

final class EngineFactoryAgentPrimitivesTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    protected function setUp(): void
    {
        SkillRegistry::reset();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        SkillRegistry::reset();
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('phpclaw.api_key', 'test-key');
        $app['config']->set('phpclaw.provider', 'anthropic');
        $app['config']->set('phpclaw.memory_driver', 'array');
    }

    public function test_fallback_is_off_by_default(): void
    {
        self::assertSame([], EngineFactory::build($this->app)->config()->fallbacks);
    }

    public function test_compatible_fallback_provider_is_applied(): void
    {
        config([
            'phpclaw.provider' => 'openai',
            'phpclaw.fallback_provider' => 'deepseek',
            'phpclaw.fallback_model' => 'deepseek-chat',
            'phpclaw.fallback_api_key' => 'fb-key',
        ]);

        self::assertSame(
            [['provider' => 'deepseek', 'model' => 'deepseek-chat', 'apiKey' => 'fb-key']],
            EngineFactory::build($this->app)->config()->fallbacks,
        );
    }

    public function test_custom_fallback_provider_is_skipped(): void
    {
        config(['phpclaw.fallback_provider' => 'custom']);

        self::assertSame([], EngineFactory::build($this->app)->config()->fallbacks);
    }

    public function test_incompatible_tool_format_fallback_provider_is_skipped(): void
    {
        config(['phpclaw.provider' => 'anthropic', 'phpclaw.fallback_provider' => 'openai']);

        self::assertSame([], EngineFactory::build($this->app)->config()->fallbacks);
    }

    public function test_auto_detected_primary_skips_a_fallback_with_another_tool_format(): void
    {
        $claw = $this->withOnlyEnv(['OPENAI_API_KEY' => 'sk-openai'], function () {
            config(['phpclaw.provider' => '', 'phpclaw.fallback_provider' => 'anthropic', 'phpclaw.fallback_api_key' => 'sk-ant-key']);

            return EngineFactory::build($this->app);
        });

        self::assertSame('openai', $claw->config()->providerName);
        self::assertSame([], $claw->config()->fallbacks);
    }

    public function test_auto_detected_primary_applies_a_fallback_with_the_same_tool_format(): void
    {
        $claw = $this->withOnlyEnv(['OPENAI_API_KEY' => 'sk-openai'], function () {
            config(['phpclaw.provider' => '', 'phpclaw.fallback_provider' => 'groq', 'phpclaw.fallback_api_key' => 'gsk-key']);

            return EngineFactory::build($this->app);
        });

        self::assertSame(
            [['provider' => 'groq', 'model' => '', 'apiKey' => 'gsk-key']],
            $claw->config()->fallbacks,
        );
    }

    public function test_rate_limit_is_off_by_default(): void
    {
        $config = EngineFactory::build($this->app)->config();

        self::assertSame(0, $config->requestsPerMinute);
        self::assertNull($config->rateLimitStore);
    }

    public function test_rate_limit_is_applied_with_the_cache_store(): void
    {
        config(['phpclaw.rate_limit_rpm' => 30]);

        $config = EngineFactory::build($this->app)->config();

        self::assertSame(30, $config->requestsPerMinute);
        self::assertInstanceOf(CacheRepository::class, $config->rateLimitStore);
    }

    public function test_rate_limit_above_600_is_clamped_to_600(): void
    {
        config(['phpclaw.rate_limit_rpm' => 1000]);

        self::assertSame(600, EngineFactory::build($this->app)->config()->requestsPerMinute);
    }

    public function test_negative_rate_limit_is_clamped_to_zero_and_disabled(): void
    {
        config(['phpclaw.rate_limit_rpm' => -5]);

        $config = EngineFactory::build($this->app)->config();

        self::assertSame(0, $config->requestsPerMinute);
        self::assertNull($config->rateLimitStore);
    }

    public function test_response_cache_is_off_by_default(): void
    {
        self::assertNull(EngineFactory::build($this->app)->config()->responseCache);
    }

    public function test_response_cache_is_applied_with_the_cache_store(): void
    {
        config(['phpclaw.response_cache' => true]);

        $config = EngineFactory::build($this->app)->config();

        self::assertInstanceOf(CacheRepository::class, $config->responseCache);
        self::assertSame(3600, $config->responseCacheTtl);
    }

    public function test_response_cache_ttl_below_60_is_clamped_to_60(): void
    {
        config(['phpclaw.response_cache' => true, 'phpclaw.response_cache_ttl' => 10]);

        self::assertSame(60, EngineFactory::build($this->app)->config()->responseCacheTtl);
    }

    public function test_response_cache_ttl_above_86400_is_clamped_to_86400(): void
    {
        config(['phpclaw.response_cache' => true, 'phpclaw.response_cache_ttl' => 999_999]);

        self::assertSame(86_400, EngineFactory::build($this->app)->config()->responseCacheTtl);
    }

    public function test_max_token_budget_is_off_by_default(): void
    {
        self::assertSame(0, EngineFactory::build($this->app)->config()->maxTokenBudget);
    }

    public function test_max_token_budget_is_applied(): void
    {
        config(['phpclaw.max_token_budget' => 5000]);

        self::assertSame(5000, EngineFactory::build($this->app)->config()->maxTokenBudget);
    }

    public function test_max_token_budget_of_one_throws_before_any_provider_call(): void
    {
        config(['phpclaw.max_token_budget' => 1]);

        $claw = EngineFactory::build($this->app);

        $this->expectException(TokenBudgetExceededException::class);

        $claw->send('this message is long enough to exceed a token budget of one estimated token');
    }

    private function withOnlyEnv(array $values, callable $callback): mixed
    {
        $names = ['PHPCLAW_PROVIDER', 'ANTHROPIC_API_KEY', 'OPENAI_API_KEY', 'GROQ_API_KEY', 'GEMINI_API_KEY', 'MISTRAL_API_KEY', 'DEEPSEEK_API_KEY', 'OLLAMA_HOST'];
        $saved = [];

        foreach ($names as $name) {
            $saved[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }

        foreach ($values as $name => $value) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
        }

        try {
            return $callback();
        } finally {
            foreach ($saved as $name => [$env, $envArray, $server]) {
                $env === false ? putenv($name) : putenv("{$name}={$env}");
                unset($_ENV[$name], $_SERVER[$name]);

                if ($envArray !== null) {
                    $_ENV[$name] = $envArray;
                }

                if ($server !== null) {
                    $_SERVER[$name] = $server;
                }
            }
        }
    }
}

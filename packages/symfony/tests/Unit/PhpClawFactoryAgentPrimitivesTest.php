<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit;

use PhpClaw\Exceptions\TokenBudgetExceededException;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Symfony\PhpClawFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

final class PhpClawFactoryAgentPrimitivesTest extends TestCase
{
    public function test_fallback_is_off_by_default(): void
    {
        self::assertSame([], $this->makeFactory()->create()->config()->fallbacks);
    }

    public function test_compatible_fallback_provider_is_applied(): void
    {
        $engine = $this->makeFactory(
            fallbackProvider: 'deepseek',
            fallbackModel: 'deepseek-chat',
            fallbackApiKey: 'fb-key',
        )->create();

        self::assertSame(
            [['provider' => 'deepseek', 'model' => 'deepseek-chat', 'apiKey' => 'fb-key']],
            $engine->config()->fallbacks,
        );
    }

    public function test_custom_fallback_provider_is_skipped(): void
    {
        $engine = $this->makeFactory(fallbackProvider: 'custom')->create();

        self::assertSame([], $engine->config()->fallbacks);
    }

    public function test_incompatible_tool_format_fallback_provider_is_skipped(): void
    {
        $engine = $this->makeFactory(fallbackProvider: 'openai')->create();

        self::assertSame([], $engine->config()->fallbacks);
    }

    public function test_auto_detected_primary_skips_a_fallback_with_another_tool_format(): void
    {
        $engine = $this->withOnlyEnv(
            ['OPENAI_API_KEY' => 'sk-openai'],
            fn () => $this->makeFactory(fallbackProvider: 'anthropic', fallbackApiKey: 'sk-ant-key', provider: '')->create(),
        );

        self::assertSame('openai', $engine->config()->providerName);
        self::assertSame([], $engine->config()->fallbacks);
    }

    public function test_auto_detected_primary_applies_a_fallback_with_the_same_tool_format(): void
    {
        $engine = $this->withOnlyEnv(
            ['OPENAI_API_KEY' => 'sk-openai'],
            fn () => $this->makeFactory(fallbackProvider: 'groq', fallbackApiKey: 'gsk-key', provider: '')->create(),
        );

        self::assertSame(
            [['provider' => 'groq', 'model' => '', 'apiKey' => 'gsk-key']],
            $engine->config()->fallbacks,
        );
    }

    public function test_rate_limit_is_off_by_default(): void
    {
        $config = $this->makeFactory()->create()->config();

        self::assertSame(0, $config->requestsPerMinute);
        self::assertNull($config->rateLimitStore);
    }

    public function test_rate_limit_is_applied_with_the_cache_store(): void
    {
        $config = $this->makeFactory(rateLimitRpm: 30, cachePool: new ArrayAdapter)->create()->config();

        self::assertSame(30, $config->requestsPerMinute);
        self::assertInstanceOf(Psr16Cache::class, $config->rateLimitStore);
    }

    public function test_rate_limit_above_600_is_clamped_to_600(): void
    {
        $config = $this->makeFactory(rateLimitRpm: 1000, cachePool: new ArrayAdapter)->create()->config();

        self::assertSame(600, $config->requestsPerMinute);
    }

    public function test_negative_rate_limit_is_clamped_to_zero_and_disabled(): void
    {
        $config = $this->makeFactory(rateLimitRpm: -5, cachePool: new ArrayAdapter)->create()->config();

        self::assertSame(0, $config->requestsPerMinute);
        self::assertNull($config->rateLimitStore);
    }

    public function test_rate_limit_with_no_cache_pool_is_not_applied(): void
    {
        $config = $this->makeFactory(rateLimitRpm: 30)->create()->config();

        self::assertSame(0, $config->requestsPerMinute);
        self::assertNull($config->rateLimitStore);
    }

    public function test_response_cache_is_off_by_default(): void
    {
        self::assertNull($this->makeFactory()->create()->config()->responseCache);
    }

    public function test_response_cache_is_applied_with_the_cache_store(): void
    {
        $config = $this->makeFactory(responseCache: true, cachePool: new ArrayAdapter)->create()->config();

        self::assertInstanceOf(Psr16Cache::class, $config->responseCache);
        self::assertSame(3600, $config->responseCacheTtl);
    }

    public function test_response_cache_ttl_below_60_is_clamped_to_60(): void
    {
        $config = $this->makeFactory(
            responseCache: true,
            responseCacheTtl: 10,
            cachePool: new ArrayAdapter,
        )->create()->config();

        self::assertSame(60, $config->responseCacheTtl);
    }

    public function test_response_cache_ttl_above_86400_is_clamped_to_86400(): void
    {
        $config = $this->makeFactory(
            responseCache: true,
            responseCacheTtl: 999_999,
            cachePool: new ArrayAdapter,
        )->create()->config();

        self::assertSame(86_400, $config->responseCacheTtl);
    }

    public function test_response_cache_with_no_cache_pool_is_not_applied(): void
    {
        $config = $this->makeFactory(responseCache: true)->create()->config();

        self::assertNull($config->responseCache);
    }

    public function test_max_token_budget_is_off_by_default(): void
    {
        self::assertSame(0, $this->makeFactory()->create()->config()->maxTokenBudget);
    }

    public function test_max_token_budget_is_applied(): void
    {
        self::assertSame(5000, $this->makeFactory(maxTokenBudget: 5000)->create()->config()->maxTokenBudget);
    }

    public function test_max_token_budget_of_one_throws_before_any_provider_call(): void
    {
        $engine = $this->makeFactory(maxTokenBudget: 1)->create();

        $this->expectException(TokenBudgetExceededException::class);

        $engine->send('this message is long enough to exceed a token budget of one estimated token');
    }

    private function makeFactory(
        string $fallbackProvider = '',
        string $fallbackModel = '',
        string $fallbackApiKey = '',
        int $rateLimitRpm = 0,
        bool $responseCache = false,
        int $responseCacheTtl = 3600,
        int $maxTokenBudget = 0,
        ?ArrayAdapter $cachePool = null,
        string $provider = 'anthropic',
    ): PhpClawFactory {
        return new PhpClawFactory(
            apiKey: 'test-key',
            provider: $provider,
            model: '',
            storeMessages: false,
            maxIterations: 10,
            shellAllowlist: ['ls'],
            tools: [],
            memory: new ArrayMemory,
            workspaceRoot: sys_get_temp_dir(),
            systemPrompt: '',
            maxTokens: 0,
            promptCache: false,
            thinkingBudget: 0,
            fallbackProvider: $fallbackProvider,
            fallbackModel: $fallbackModel,
            fallbackApiKey: $fallbackApiKey,
            rateLimitRpm: $rateLimitRpm,
            responseCache: $responseCache,
            responseCacheTtl: $responseCacheTtl,
            maxTokenBudget: $maxTokenBudget,
            cachePool: $cachePool,
        );
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

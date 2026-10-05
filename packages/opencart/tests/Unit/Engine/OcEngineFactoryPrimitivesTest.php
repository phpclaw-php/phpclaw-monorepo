<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tests\Unit\Engine;

use PhpClaw\Claw;
use PhpClaw\ClawConfig;
use PhpClaw\OpenCart\Engine\OcCache;
use PhpClaw\OpenCart\Engine\OcEngineFactory;
use PHPUnit\Framework\TestCase;

final class OcEngineFactoryPrimitivesTest extends TestCase
{
    public function test_agent_primitives_stay_off_by_default(): void
    {
        $config = $this->configAfterPrimitives(['provider' => 'ollama']);

        self::assertSame([], $config->fallbacks);
        self::assertSame(0, $config->requestsPerMinute);
        self::assertNull($config->responseCache);
        self::assertSame(0, $config->maxTokenBudget);
    }

    public function test_a_fallback_with_the_primary_tool_format_is_applied(): void
    {
        $config = $this->configAfterPrimitives(['provider' => 'ollama', 'fallback_provider' => 'groq', 'fallback_model' => 'llama-3.1-8b-instant', 'fallback_api_key' => 'gsk-key']);

        self::assertSame([['provider' => 'groq', 'model' => 'llama-3.1-8b-instant', 'apiKey' => 'gsk-key']], $config->fallbacks);
    }

    public function test_a_fallback_with_another_tool_format_is_skipped(): void
    {
        $config = $this->configAfterPrimitives(['provider' => 'ollama', 'fallback_provider' => 'anthropic', 'fallback_api_key' => 'sk-ant']);

        self::assertSame([], $config->fallbacks);
    }

    public function test_a_custom_fallback_is_skipped(): void
    {
        $config = $this->configAfterPrimitives(['provider' => 'openai', 'fallback_provider' => 'custom']);

        self::assertSame([], $config->fallbacks);
    }

    public function test_an_empty_primary_resolves_to_the_auto_detected_provider(): void
    {
        $env = getenv('PHPCLAW_PROVIDER');
        putenv('PHPCLAW_PROVIDER=openai');

        try {
            $applied = $this->configAfterPrimitives(['provider' => '', 'fallback_provider' => 'groq', 'fallback_api_key' => 'gsk-key']);
            $skipped = $this->configAfterPrimitives(['provider' => '', 'fallback_provider' => 'anthropic', 'fallback_api_key' => 'sk-ant']);
        } finally {
            $env === false ? putenv('PHPCLAW_PROVIDER') : putenv("PHPCLAW_PROVIDER={$env}");
        }

        self::assertSame([['provider' => 'groq', 'model' => '', 'apiKey' => 'gsk-key']], $applied->fallbacks);
        self::assertSame([], $skipped->fallbacks);
    }

    public function test_the_rate_limit_uses_the_opencart_store(): void
    {
        $config = $this->configAfterPrimitives(['provider' => 'ollama', 'rate_limit_rpm' => '30']);

        self::assertSame(30, $config->requestsPerMinute);
        self::assertInstanceOf(OcCache::class, $config->rateLimitStore);
    }

    public function test_the_response_cache_uses_the_opencart_store_and_ttl(): void
    {
        $config = $this->configAfterPrimitives(['provider' => 'ollama', 'response_cache' => '1', 'response_cache_ttl' => '600']);

        self::assertInstanceOf(OcCache::class, $config->responseCache);
        self::assertSame(600, $config->responseCacheTtl);
    }

    public function test_an_unticked_response_cache_stays_off(): void
    {
        $config = $this->configAfterPrimitives(['provider' => 'ollama', 'response_cache' => '0', 'response_cache_ttl' => '600']);

        self::assertNull($config->responseCache);
    }

    public function test_the_token_budget_is_applied(): void
    {
        $config = $this->configAfterPrimitives(['provider' => 'ollama', 'max_token_budget' => '5000']);

        self::assertSame(5000, $config->maxTokenBudget);
    }

    private function configAfterPrimitives(array $saved): ClawConfig
    {
        $builder = Claw::builder()->provider((string) $saved['provider'])->model('qwen2.5:7b')->apiKey('test-key');
        (new \ReflectionMethod(OcEngineFactory::class, 'applyAgentPrimitives'))->invoke(new OcEngineFactory, $builder, $saved);

        return $builder->build()->config();
    }
}

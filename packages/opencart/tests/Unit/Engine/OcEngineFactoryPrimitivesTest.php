<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tests\Unit\Engine;

use PhpClaw\Claw;
use PhpClaw\ClawConfig;
use PhpClaw\OpenCart\Engine\OcCache;
use PhpClaw\OpenCart\Engine\OcEngineFactory;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\ToolProfileResolver;
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

    public function test_max_tools_per_turn_from_config_overrides_the_profile(): void
    {
        self::assertSame(3, $this->configAfterToolRouting(['max_tools_per_turn' => '3'])->maxToolsPerTurn);
    }

    public function test_minus_one_from_config_removes_the_cap(): void
    {
        self::assertSame(-1, $this->configAfterToolRouting(['max_tools_per_turn' => '-1'])->maxToolsPerTurn);
    }

    public function test_zero_keeps_the_profile_limit(): void
    {
        self::assertSame(5, $this->configAfterToolRouting(['max_tools_per_turn' => '0'])->maxToolsPerTurn);
    }

    public function test_a_missing_max_tools_key_keeps_the_profile_limit(): void
    {
        self::assertSame(5, $this->configAfterToolRouting([])->maxToolsPerTurn);
    }

    public function test_always_include_tools_from_config_reach_the_engine(): void
    {
        self::assertSame(['oc_product', 'oc_order'], $this->configAfterToolRouting(['always_include_tools' => ['oc_product', 'oc_order']])->alwaysIncludeTools);
    }

    public function test_a_missing_always_include_key_means_an_empty_list(): void
    {
        self::assertSame([], $this->configAfterToolRouting([])->alwaysIncludeTools);
    }

    public function test_always_include_and_tool_deny_on_the_same_name_keeps_it_denied(): void
    {
        $tools = ToolProfileResolver::filter([$this->fakeTool('oc_product'), $this->fakeTool('oc_order')], deny: ['oc_product']);
        $config = $this->configAfterToolRouting(['always_include_tools' => ['oc_product']], $tools);
        $names = array_map(static fn ($tool): string => $tool->name(), $config->tools);

        self::assertContains('oc_order', $names, 'precondition: oc_order is not denied, so the tool list is not empty');
        self::assertNotContains('oc_product', $names);
    }

    private function configAfterToolRouting(array $saved, array $tools = []): ClawConfig
    {
        $builder = Claw::builder()->provider('ollama')->model('qwen2.5:7b')->apiKey('test-key')->tools($tools);
        (new \ReflectionMethod(OcEngineFactory::class, 'applyToolRouting'))->invoke(new OcEngineFactory, $builder, $saved, 'ollama', 'qwen2.5:7b');

        return $builder->build()->config();
    }

    private function fakeTool(string $name): ToolInterface
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
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $input): string
            {
                return '';
            }
        };
    }
}

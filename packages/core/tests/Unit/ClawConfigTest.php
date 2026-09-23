<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit;

use PhpClaw\ClawConfig;
use PhpClaw\Config\LoopConfig;
use PhpClaw\Config\ProviderConfig;
use PhpClaw\Config\RuntimeConfig;
use PhpClaw\Config\SkillConfig;
use PhpClaw\Config\ToolConfig;
use PhpClaw\Exceptions\AdapterException;
use PhpClaw\Providers\AnthropicProvider;
use PhpClaw\Providers\OpenAIProvider;
use PHPUnit\Framework\TestCase;

final class ClawConfigTest extends TestCase
{
    private array $originalEnv = [];

    protected function setUp(): void
    {
        foreach ([
            'ANTHROPIC_API_KEY',
            'OPENAI_API_KEY',
            'GROQ_API_KEY',
            'GEMINI_API_KEY',
            'MISTRAL_API_KEY',
            'DEEPSEEK_API_KEY',
            'OLLAMA_HOST',
            'OPENAI_BASE_URL',
            'PHPCLAW_PROVIDER',
            'PHPCLAW_MODEL',
        ] as $key) {
            $this->originalEnv[$key] = $_ENV[$key] ?? '';
            unset($_ENV[$key]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            if ($value === '') {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
    }

    public function test_explicit_api_key_is_used(): void
    {
        $config = new ClawConfig(provider: new ProviderConfig(apiKey: 'sk-test-key', provider: 'openai'));
        $this->assertSame('sk-test-key', $config->apiKey);
    }

    public function test_explicit_provider_is_lowercased(): void
    {
        $config = new ClawConfig(provider: new ProviderConfig(apiKey: 'key', provider: 'OpenAI'));
        $this->assertSame('openai', $config->providerName);
    }

    public function test_explicit_model_is_stored(): void
    {
        $config = new ClawConfig(provider: new ProviderConfig(apiKey: 'key', provider: 'openai', model: 'gpt-4o'));
        $this->assertSame('gpt-4o', $config->model);
    }

    public function test_store_messages_defaults_to_true(): void
    {
        $config = new ClawConfig(provider: new ProviderConfig(apiKey: 'key', provider: 'openai'));
        $this->assertTrue($config->storeMessages);
    }

    public function test_store_messages_can_be_set_false(): void
    {
        $config = new ClawConfig(
            provider: new ProviderConfig(apiKey: 'key', provider: 'openai'),
            flags: new RuntimeConfig(storeMessages: false),
        );
        $this->assertFalse($config->storeMessages);
    }

    public function test_max_iterations_defaults_to_20(): void
    {
        $config = new ClawConfig(provider: new ProviderConfig(apiKey: 'key', provider: 'openai'));
        $this->assertSame(20, $config->maxIterations);
    }

    public function test_max_iterations_can_be_overridden(): void
    {
        $config = new ClawConfig(
            provider: new ProviderConfig(apiKey: 'key', provider: 'openai'),
            limits: new LoopConfig(maxIterations: 5),
        );
        $this->assertSame(5, $config->maxIterations);
    }

    public function test_invalid_max_iterations_falls_back_to_20(): void
    {
        $config = new ClawConfig(
            provider: new ProviderConfig(apiKey: 'key', provider: 'openai'),
            limits: new LoopConfig(maxIterations: 0),
        );
        $this->assertSame(20, $config->maxIterations);
    }

    public function test_shell_allowlist_has_defaults(): void
    {
        $config = new ClawConfig(provider: new ProviderConfig(apiKey: 'key', provider: 'openai'));
        $this->assertContains('ls', $config->shellAllowlist);
        $this->assertContains('pwd', $config->shellAllowlist);
        $this->assertNotEmpty($config->shellAllowlist);
    }

    public function test_shell_allowlist_can_be_overridden(): void
    {
        $config = new ClawConfig(
            provider: new ProviderConfig(apiKey: 'key', provider: 'openai'),
            tools: new ToolConfig(shellAllowlist: ['ls', 'pwd']),
        );
        $this->assertSame(['ls', 'pwd'], $config->shellAllowlist);
    }

    public function test_detects_anthropic_from_env(): void
    {
        $_ENV['ANTHROPIC_API_KEY'] = 'sk-ant-test';
        $config = new ClawConfig;
        $this->assertSame('anthropic', $config->providerName);
        $this->assertSame('sk-ant-test', $config->apiKey);
    }

    public function test_detects_openai_from_env_when_anthropic_absent(): void
    {
        $_ENV['OPENAI_API_KEY'] = 'sk-openai-test';
        $config = new ClawConfig;
        $this->assertSame('openai', $config->providerName);
        $this->assertSame('sk-openai-test', $config->apiKey);
    }

    public function test_detects_groq_from_env_when_anthropic_and_openai_absent(): void
    {
        $_ENV['GROQ_API_KEY'] = 'gsk-groq-test';
        $config = new ClawConfig;
        $this->assertSame('groq', $config->providerName);
        $this->assertSame('gsk-groq-test', $config->apiKey);
    }

    public function test_anthropic_takes_priority_over_openai(): void
    {
        $_ENV['ANTHROPIC_API_KEY'] = 'sk-ant';
        $_ENV['OPENAI_API_KEY'] = 'sk-oai';
        $config = new ClawConfig;
        $this->assertSame('anthropic', $config->providerName);
    }

    public function test_phpclaw_provider_env_overrides_auto_detection(): void
    {
        $_ENV['ANTHROPIC_API_KEY'] = 'sk-ant';
        $_ENV['PHPCLAW_PROVIDER'] = 'groq';
        $_ENV['GROQ_API_KEY'] = 'gsk-groq';

        $config = new ClawConfig;
        $this->assertSame('groq', $config->providerName);
    }

    public function test_phpclaw_model_env_sets_model(): void
    {
        $_ENV['ANTHROPIC_API_KEY'] = 'sk-ant';
        $_ENV['PHPCLAW_MODEL'] = 'claude-opus-4-5';

        $config = new ClawConfig;
        $this->assertSame('claude-opus-4-5', $config->model);
    }

    public function test_build_provider_returns_anthropic_provider(): void
    {
        $config = new ClawConfig(provider: new ProviderConfig(apiKey: 'sk-ant-test', provider: 'anthropic'));
        $provider = $config->buildProvider();
        $this->assertInstanceOf(AnthropicProvider::class, $provider);
    }

    public function test_build_provider_returns_openai_provider(): void
    {
        $config = new ClawConfig(provider: new ProviderConfig(apiKey: 'sk-oai-test', provider: 'openai'));
        $provider = $config->buildProvider();
        $this->assertInstanceOf(OpenAIProvider::class, $provider);
    }

    public function test_build_provider_returns_groq_as_openai_engine_with_groq_endpoint(): void
    {
        $config = new ClawConfig(provider: new ProviderConfig(apiKey: 'gsk-test', provider: 'groq'));
        $provider = $config->buildProvider();

        $this->assertInstanceOf(OpenAIProvider::class, $provider);
        $this->assertSame('groq', $provider->name());
        $this->assertSame('https://api.groq.com/openai/v1/chat/completions', $provider->endpoint());
    }

    public function test_build_provider_returns_deepseek_as_openai_engine_with_deepseek_endpoint(): void
    {
        $config = new ClawConfig(provider: new ProviderConfig(apiKey: 'sk-deepseek', provider: 'deepseek'));
        $provider = $config->buildProvider();

        $this->assertInstanceOf(OpenAIProvider::class, $provider);
        $this->assertSame('deepseek', $provider->name());
        $this->assertSame('https://api.deepseek.com/v1/chat/completions', $provider->endpoint());
    }

    public function test_detects_deepseek_from_env_when_higher_priority_keys_absent(): void
    {
        $_ENV['DEEPSEEK_API_KEY'] = 'sk-deepseek-test';
        $config = new ClawConfig;
        $this->assertSame('deepseek', $config->providerName);
        $this->assertSame('sk-deepseek-test', $config->apiKey);
    }

    public function test_build_custom_provider_reads_openai_base_url(): void
    {
        $_ENV['OPENAI_BASE_URL'] = 'https://my-proxy.example.com/v1/chat/completions';
        $config = new ClawConfig(provider: new ProviderConfig(apiKey: 'sk-custom', provider: 'custom'));
        $provider = $config->buildProvider();

        $this->assertInstanceOf(OpenAIProvider::class, $provider);
        $this->assertSame('custom', $provider->name());
        $this->assertSame('https://my-proxy.example.com/v1/chat/completions', $provider->endpoint());
    }

    public function test_build_custom_provider_throws_without_base_url(): void
    {
        $config = new ClawConfig(provider: new ProviderConfig(apiKey: 'sk-custom', provider: 'custom'));

        $this->expectException(AdapterException::class);
        $config->buildProvider();
    }

    public function test_build_provider_throws_when_no_api_key(): void
    {
        $config = new ClawConfig(provider: new ProviderConfig(apiKey: '', provider: 'openai'));

        $this->expectException(AdapterException::class);
        $config->buildProvider();
    }

    public function test_build_provider_throws_for_unknown_provider(): void
    {
        $config = new ClawConfig(provider: new ProviderConfig(apiKey: 'some-key', provider: 'unknown_llm'));

        $this->expectException(AdapterException::class);
        $config->buildProvider();
    }

    public function test_build_provider_uses_overridden_model(): void
    {
        $config = new ClawConfig(provider: new ProviderConfig(apiKey: 'sk-ant', provider: 'anthropic', model: 'claude-opus-4-8'));
        $provider = $config->buildProvider();
        $this->assertSame('claude-opus-4-8', $provider->model());
    }

    public function test_detects_gemini_from_env_when_higher_priority_keys_absent(): void
    {
        $_ENV['GEMINI_API_KEY'] = 'AIza-test-key';
        $config = new ClawConfig;
        $this->assertSame('gemini', $config->providerName);
        $this->assertSame('AIza-test-key', $config->apiKey);
    }

    public function test_detects_mistral_from_env_when_higher_priority_keys_absent(): void
    {
        $_ENV['MISTRAL_API_KEY'] = 'msk-test-key';
        $config = new ClawConfig;
        $this->assertSame('mistral', $config->providerName);
        $this->assertSame('msk-test-key', $config->apiKey);
    }

    public function test_detects_ollama_from_env_when_all_api_key_vars_absent(): void
    {
        $_ENV['OLLAMA_HOST'] = 'http://localhost:11434';
        $config = new ClawConfig;
        $this->assertSame('ollama', $config->providerName);
        $this->assertSame('', $config->apiKey);
    }

    public function test_anthropic_takes_priority_over_gemini(): void
    {
        $_ENV['ANTHROPIC_API_KEY'] = 'sk-ant';
        $_ENV['GEMINI_API_KEY'] = 'AIza-test';
        $config = new ClawConfig;
        $this->assertSame('anthropic', $config->providerName);
    }

    public function test_system_prompt_defaults_to_empty_string(): void
    {
        $config = new ClawConfig(provider: new ProviderConfig(apiKey: 'key', provider: 'openai'));
        $this->assertSame('', $config->systemPrompt);
    }

    public function test_system_prompt_can_be_set(): void
    {
        $config = new ClawConfig(provider: new ProviderConfig(apiKey: 'key', provider: 'openai', systemPrompt: 'You are a DevOps expert.'));
        $this->assertSame('You are a DevOps expert.', $config->systemPrompt);
    }

    public function test_max_tokens_defaults_to_zero(): void
    {
        $config = new ClawConfig(provider: new ProviderConfig(apiKey: 'key', provider: 'openai'));
        $this->assertSame(0, $config->maxTokens);
    }

    public function test_max_tokens_can_be_set(): void
    {
        $config = new ClawConfig(provider: new ProviderConfig(apiKey: 'key', provider: 'openai', maxTokens: 2000));
        $this->assertSame(2000, $config->maxTokens);
    }

    public function test_max_tokens_negative_becomes_zero(): void
    {
        $config = new ClawConfig(provider: new ProviderConfig(apiKey: 'key', provider: 'openai', maxTokens: -1));
        $this->assertSame(0, $config->maxTokens);
    }

    public function test_prompt_cache_defaults_to_true(): void
    {
        $config = new ClawConfig(provider: new ProviderConfig(apiKey: 'key', provider: 'anthropic'));
        $this->assertTrue($config->promptCache);
    }

    public function test_prompt_cache_can_be_set_true(): void
    {
        $config = new ClawConfig(provider: new ProviderConfig(apiKey: 'key', provider: 'anthropic', promptCache: true));
        $this->assertTrue($config->promptCache);
    }

    public function test_thinking_budget_defaults_to_zero(): void
    {
        $config = new ClawConfig(provider: new ProviderConfig(apiKey: 'key', provider: 'anthropic'));
        $this->assertSame(0, $config->thinkingBudget);
    }

    public function test_thinking_budget_can_be_set(): void
    {
        $config = new ClawConfig(provider: new ProviderConfig(apiKey: 'key', provider: 'anthropic', thinkingBudget: 8000));
        $this->assertSame(8000, $config->thinkingBudget);
    }

    public function test_thinking_budget_negative_becomes_zero(): void
    {
        $config = new ClawConfig(provider: new ProviderConfig(apiKey: 'key', provider: 'anthropic', thinkingBudget: -500));
        $this->assertSame(0, $config->thinkingBudget);
    }

    public function test_build_provider_passes_system_prompt_to_anthropic(): void
    {
        $config = new ClawConfig(
            provider: new ProviderConfig(
                apiKey: 'sk-ant',
                provider: 'anthropic',
                systemPrompt: 'You are helpful.',
            ),
        );
        $provider = $config->buildProvider();
        $this->assertInstanceOf(AnthropicProvider::class, $provider);
        $this->assertSame('You are helpful.', $config->systemPrompt);
    }

    public function test_build_provider_passes_system_prompt_to_openai(): void
    {
        $config = new ClawConfig(
            provider: new ProviderConfig(
                apiKey: 'sk-oai',
                provider: 'openai',
                systemPrompt: 'You are a PHP expert.',
            ),
        );
        $provider = $config->buildProvider();
        $this->assertInstanceOf(OpenAIProvider::class, $provider);
        $this->assertSame('You are a PHP expert.', $config->systemPrompt);
    }

    public function test_build_provider_builds_ollama_without_api_key(): void
    {
        $config = new ClawConfig(provider: new ProviderConfig(apiKey: '', provider: 'ollama'));
        $provider = $config->buildProvider();
        $this->assertInstanceOf(OpenAIProvider::class, $provider);
        $this->assertSame('ollama', $provider->name());
    }

    public function test_build_ollama_honours_ollama_host_override(): void
    {
        $_ENV['OLLAMA_HOST'] = 'http://remote-ollama:11434';
        $config = new ClawConfig(provider: new ProviderConfig(apiKey: '', provider: 'ollama'));
        $provider = $config->buildProvider();

        $this->assertInstanceOf(OpenAIProvider::class, $provider);
        $this->assertSame('http://remote-ollama:11434/v1/chat/completions', $provider->endpoint());
    }

    public function test_upgrade_knobs_have_expected_defaults(): void
    {
        $config = new ClawConfig(provider: new ProviderConfig(apiKey: 'x', provider: 'anthropic'));

        $this->assertFalse($config->allowPhpWrite);
        $this->assertSame(3, $config->skillMatchLimit);
        $this->assertSame(0, $config->maxToolsPerTurn);
        $this->assertSame([], $config->remoteSkillUrls);
        $this->assertSame([], $config->remoteToolProfileUrls);
        $this->assertNull($config->approvalGate);
        $this->assertSame(0, $config->maxHistoryTokens);
    }

    public function test_skill_match_limit_clamps_to_at_least_one(): void
    {
        $config = new ClawConfig(
            provider: new ProviderConfig(apiKey: 'x', provider: 'anthropic'),
            skills: new SkillConfig(skillMatchLimit: 0),
        );

        $this->assertSame(1, $config->skillMatchLimit);
    }

    public function test_php_and_composer_stay_out_of_default_shell_allowlist(): void
    {
        $this->assertNotContains('php', ClawConfig::DEFAULT_SHELL_ALLOWLIST);
        $this->assertNotContains('composer', ClawConfig::DEFAULT_SHELL_ALLOWLIST);
    }
}

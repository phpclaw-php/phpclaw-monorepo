<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Unit\Engine;

use Joomla\Registry\Registry;
use PhpClaw\Joomla\Component\Administrator\Engine\PhpClawConfig;
use PHPUnit\Framework\TestCase;

final class PhpClawConfigTest extends TestCase
{
    public function test_from_registry_maps_all_scalar_fields(): void
    {
        $params = new Registry([
            'provider' => 'anthropic',
            'model' => 'claude-3-haiku',
            'api_key' => 'sk-test-123',
            'base_url' => 'https://api.example.com/v1/chat/completions',
            'store_messages' => 1,
            'system_prompt' => 'You are helpful.',
            'max_iterations' => 15,
            'cloud_key' => 'cloud-abc',
            'cloud_signing_secret' => 'signing-xyz',
        ]);

        $config = PhpClawConfig::fromRegistry($params);

        $this->assertSame('anthropic', $config->provider);
        $this->assertSame('claude-3-haiku', $config->model);
        $this->assertSame('sk-test-123', $config->apiKey);
        $this->assertSame('https://api.example.com/v1/chat/completions', $config->baseUrl);
        $this->assertTrue($config->storeMessages);
        $this->assertSame('You are helpful.', $config->systemPrompt);
        $this->assertSame(15, $config->maxIterations);
        $this->assertSame('cloud-abc', $config->cloudKey);
        $this->assertSame('signing-xyz', $config->cloudSigningSecret);
    }

    public function test_from_registry_parses_cloud_signing_secret(): void
    {
        $params = new Registry(['cloud_signing_secret' => 'my-hmac-secret']);
        $config = PhpClawConfig::fromRegistry($params);
        $this->assertSame('my-hmac-secret', $config->cloudSigningSecret);
    }

    public function test_from_registry_defaults_cloud_signing_secret_to_empty_string(): void
    {
        $config = PhpClawConfig::fromRegistry(new Registry);
        $this->assertSame('', $config->cloudSigningSecret);
    }

    public function test_from_registry_parses_shell_allowlist_csv(): void
    {
        $params = new Registry(['shell_allowlist' => 'ls,pwd, php , composer']);
        $config = PhpClawConfig::fromRegistry($params);
        $this->assertSame(['ls', 'pwd', 'php', 'composer'], $config->shellAllowlist);
    }

    public function test_from_registry_parses_tool_deny_csv(): void
    {
        $params = new Registry(['tool_deny' => 'shell_exec, http_request']);
        $config = PhpClawConfig::fromRegistry($params);
        $this->assertSame(['shell_exec', 'http_request'], $config->toolDeny);
    }

    public function test_from_registry_defaults_tool_deny_to_empty_array(): void
    {
        $params = new Registry([]);
        $config = PhpClawConfig::fromRegistry($params);
        $this->assertSame([], $config->toolDeny);
    }

    public function test_from_registry_parses_cloud_disable_csv(): void
    {
        $params = new Registry(['cloud_disable' => 'hooks,tools']);
        $config = PhpClawConfig::fromRegistry($params);
        $this->assertSame(['hooks', 'tools'], $config->cloudDisable);
    }

    public function test_from_registry_parses_cloud_disable_array(): void
    {
        $params = new Registry(['cloud_disable' => ['hooks', 'tools']]);
        $config = PhpClawConfig::fromRegistry($params);
        $this->assertSame(['hooks', 'tools'], $config->cloudDisable);
    }

    public function test_from_registry_falls_back_to_legacy_api_key(): void
    {
        $params = new Registry(['anthropic_api_key' => 'legacy-key-456']);
        $config = PhpClawConfig::fromRegistry($params);
        $this->assertSame('legacy-key-456', $config->apiKey);
    }

    public function test_from_registry_prefers_api_key_over_legacy(): void
    {
        $params = new Registry([
            'api_key' => 'new-key',
            'anthropic_api_key' => 'old-key',
        ]);
        $config = PhpClawConfig::fromRegistry($params);
        $this->assertSame('new-key', $config->apiKey);
    }

    public function test_from_registry_clamps_max_iterations_minimum(): void
    {
        $params = new Registry(['max_iterations' => 0]);
        $config = PhpClawConfig::fromRegistry($params);
        $this->assertSame(1, $config->maxIterations);
    }

    public function test_from_registry_uses_defaults_for_missing_fields(): void
    {
        $config = PhpClawConfig::fromRegistry(new Registry);

        $this->assertSame('', $config->provider);
        $this->assertSame('', $config->apiKey);
        $this->assertTrue($config->storeMessages);
        $this->assertSame(20, $config->maxIterations);
        $this->assertSame([], $config->cloudDisable);
        $this->assertNotEmpty($config->shellAllowlist);
    }

    public function test_from_registry_resolves_openai_legacy_key(): void
    {
        $params = new Registry(['openai_api_key' => 'openai-legacy-key']);
        $config = PhpClawConfig::fromRegistry($params);
        $this->assertSame('openai-legacy-key', $config->apiKey);
    }

    public function test_from_registry_resolves_groq_legacy_key(): void
    {
        $params = new Registry(['groq_api_key' => 'groq-legacy-key']);
        $config = PhpClawConfig::fromRegistry($params);
        $this->assertSame('groq-legacy-key', $config->apiKey);
    }

    public function test_from_registry_resolves_gemini_legacy_key(): void
    {
        $params = new Registry(['gemini_api_key' => 'gemini-legacy-key']);
        $config = PhpClawConfig::fromRegistry($params);
        $this->assertSame('gemini-legacy-key', $config->apiKey);
    }

    public function test_from_registry_resolves_mistral_legacy_key(): void
    {
        $params = new Registry(['mistral_api_key' => 'mistral-legacy-key']);
        $config = PhpClawConfig::fromRegistry($params);
        $this->assertSame('mistral-legacy-key', $config->apiKey);
    }

    public function test_from_registry_resolves_deepseek_legacy_key(): void
    {
        $params = new Registry(['deepseek_api_key' => 'deepseek-legacy-key']);
        $config = PhpClawConfig::fromRegistry($params);
        $this->assertSame('deepseek-legacy-key', $config->apiKey);
    }

    public function test_from_registry_returns_empty_string_when_no_key_present(): void
    {
        $params = new Registry(['provider' => 'ollama']);
        $config = PhpClawConfig::fromRegistry($params);
        $this->assertSame('', $config->apiKey);
    }

    public function test_is_allowed_provider_url_accepts_https(): void
    {
        $this->assertTrue(PhpClawConfig::isAllowedProviderUrl('https://api.example.com/v1'));
    }

    public function test_is_allowed_provider_url_accepts_http_loopback(): void
    {
        $this->assertTrue(PhpClawConfig::isAllowedProviderUrl('http://localhost:11434/v1'));
        $this->assertTrue(PhpClawConfig::isAllowedProviderUrl('http://127.0.0.1:11434/v1'));
    }

    public function test_is_allowed_provider_url_rejects_external_http(): void
    {
        $this->assertFalse(PhpClawConfig::isAllowedProviderUrl('http://api.evil.example/v1'));
    }

    public function test_is_allowed_provider_url_rejects_non_http_scheme(): void
    {
        $this->assertFalse(PhpClawConfig::isAllowedProviderUrl('ftp://example.com/x'));
    }
}

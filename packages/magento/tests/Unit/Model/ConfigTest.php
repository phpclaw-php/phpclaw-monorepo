<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use PhpClaw\ClawConfig;
use PhpClaw\Config\ToolConfig;
use PhpClaw\Magento\Model\Config;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    private ScopeConfigInterface&MockObject $scopeConfig;

    private EncryptorInterface&MockObject $encryptor;

    private Config $config;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->encryptor = $this->createMock(EncryptorInterface::class);
        $this->config = new Config($this->scopeConfig, $this->encryptor);
    }

    public function test_get_api_key_plaintext_passes_through(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('phpclaw/general/api_key')
            ->willReturn('sk-test');
        $this->encryptor->expects(self::never())->method('decrypt');

        self::assertSame('sk-test', $this->config->getApiKey());
    }

    public function test_get_api_key_decrypts_ciphertext(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('phpclaw/general/api_key')
            ->willReturn('0:3:abc==');
        $this->encryptor->expects(self::once())
            ->method('decrypt')
            ->with('0:3:abc==')
            ->willReturn('sk-real');

        self::assertSame('sk-real', $this->config->getApiKey());
    }

    public function test_get_api_key_empty_returns_empty_without_decrypt(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);
        $this->encryptor->expects(self::never())->method('decrypt');

        self::assertSame('', $this->config->getApiKey());
    }

    public function test_get_cloud_key_decrypts_ciphertext(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('phpclaw/general/cloud_key')
            ->willReturn('0:3:xyz==');
        $this->encryptor->expects(self::once())
            ->method('decrypt')
            ->with('0:3:xyz==')
            ->willReturn('123456');

        self::assertSame('123456', $this->config->getCloudKey());
    }

    public function test_get_cloud_key_plaintext_passes_through(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('phpclaw/general/cloud_key')
            ->willReturn('plain-key');
        $this->encryptor->expects(self::never())->method('decrypt');

        self::assertSame('plain-key', $this->config->getCloudKey());
    }

    public function test_get_cloud_signing_secret_decrypts_ciphertext(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('phpclaw/general/cloud_signing_secret')
            ->willReturn('0:3:sec==');
        $this->encryptor->expects(self::once())
            ->method('decrypt')
            ->with('0:3:sec==')
            ->willReturn('plaintext-secret');

        self::assertSame('plaintext-secret', $this->config->getCloudSigningSecret());
    }

    public function test_get_cloud_signing_secret_plaintext_passes_through(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('phpclaw/general/cloud_signing_secret')
            ->willReturn('raw-secret');
        $this->encryptor->expects(self::never())->method('decrypt');

        self::assertSame('raw-secret', $this->config->getCloudSigningSecret());
    }

    public function test_get_cloud_signing_secret_empty_returns_empty_without_decrypt(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('phpclaw/general/cloud_signing_secret')
            ->willReturn(null);
        $this->encryptor->expects(self::never())->method('decrypt');

        self::assertSame('', $this->config->getCloudSigningSecret());
    }

    public function test_get_base_url(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('phpclaw/general/base_url')
            ->willReturn('https://openrouter.ai/api/v1/chat/completions');

        self::assertSame('https://openrouter.ai/api/v1/chat/completions', $this->config->getBaseUrl());
    }

    public function test_get_provider_returns_empty_by_default(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);

        self::assertSame('', $this->config->getProvider());
    }

    public function test_get_model_returns_string(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('phpclaw/general/model')
            ->willReturn('claude-haiku-4-5-20251001');

        self::assertSame('claude-haiku-4-5-20251001', $this->config->getModel());
    }

    public function test_is_store_messages_false_by_default(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('0');

        self::assertFalse($this->config->isStoreMessages());
    }

    public function test_is_store_messages_true_when_enabled(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('1');

        self::assertTrue($this->config->isStoreMessages());
    }

    public function test_get_max_iterations_returns_int(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('phpclaw/general/max_iterations')
            ->willReturn('15');

        self::assertSame(15, $this->config->getMaxIterations());
    }

    public function test_get_max_iterations_minimum_is_one(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('0');

        self::assertSame(1, $this->config->getMaxIterations());
    }

    public function test_get_shell_allowlist_splits_csv(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('phpclaw/security/shell_allowlist')
            ->willReturn('ls, pwd, df');

        self::assertSame(['ls', 'pwd', 'df'], $this->config->getShellAllowlist());
    }

    public function test_get_shell_allowlist_filters_empty_entries(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('phpclaw/security/shell_allowlist')
            ->willReturn('ls,,pwd');

        self::assertSame(['ls', 'pwd'], $this->config->getShellAllowlist());
    }

    public function test_get_tool_deny_splits_csv(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('phpclaw/security/tool_deny')
            ->willReturn('shell_exec, http_request');

        self::assertSame(['shell_exec', 'http_request'], $this->config->getToolDeny());
    }

    public function test_get_tool_deny_defaults_to_empty_array(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('phpclaw/security/tool_deny')
            ->willReturn('');

        self::assertSame([], $this->config->getToolDeny());
    }

    public function test_get_max_iterations_inherits_core_default_when_unset(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('');

        self::assertSame(ClawConfig::DEFAULT_MAX_ITERATIONS, $this->config->getMaxIterations());
    }

    public function test_get_shell_allowlist_inherits_core_default_when_unset(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('');

        self::assertSame(ToolConfig::DEFAULT_SHELL_ALLOWLIST, $this->config->getShellAllowlist());
    }

    public function test_get_remote_skill_urls_returns_empty_when_not_set(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('phpclaw/general/remote_skill_urls')
            ->willReturn('');

        self::assertSame([], $this->config->getRemoteSkillUrls());
    }

    public function test_get_remote_skill_urls_parses_csv(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('phpclaw/general/remote_skill_urls')
            ->willReturn('https://example.com/a.md,https://example.com/b.json');

        self::assertSame(
            ['https://example.com/a.md', 'https://example.com/b.json'],
            $this->config->getRemoteSkillUrls(),
        );
    }

    public function test_get_remote_skill_urls_trims_whitespace(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('phpclaw/general/remote_skill_urls')
            ->willReturn('https://a.com/s.md , https://b.com/t.json');

        self::assertSame(
            ['https://a.com/s.md', 'https://b.com/t.json'],
            $this->config->getRemoteSkillUrls(),
        );
    }

    public function test_get_memory_driver_returns_router(): void
    {
        self::assertSame('router', $this->config->getMemoryDriver());
    }
}

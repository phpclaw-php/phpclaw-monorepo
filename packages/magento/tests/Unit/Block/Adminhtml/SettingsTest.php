<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Block\Adminhtml;

use Magento\Backend\Block\Template\Context;
use PhpClaw\Magento\Block\Adminhtml\Settings;
use PhpClaw\Magento\Model\Config;
use PhpClaw\Magento\Model\Config\Source\Provider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase
{
    private Context&MockObject $context;

    private Config&MockObject $config;

    private Provider&MockObject $provider;

    private Settings $block;

    protected function setUp(): void
    {
        $this->context = $this->createMock(Context::class);
        $this->config = $this->createMock(Config::class);
        $this->provider = $this->createMock(Provider::class);

        $this->block = new Settings($this->context, $this->config, $this->provider);
    }

    public function test_get_values_returns_all_expected_keys(): void
    {
        $this->config->method('getProvider')->willReturn('anthropic');
        $this->config->method('getModel')->willReturn('claude-3-5');
        $this->config->method('getApiKey')->willReturn('');
        $this->config->method('getBaseUrl')->willReturn('');
        $this->config->method('getSystemPrompt')->willReturn('');
        $this->config->method('isStoreMessages')->willReturn(true);
        $this->config->method('getMaxIterations')->willReturn(20);
        $this->config->method('getCloudKey')->willReturn('');
        $this->config->method('getCloudSigningSecret')->willReturn('');
        $this->config->method('getCloudDisable')->willReturn('0');
        $this->config->method('getRemoteSkillUrls')->willReturn([]);

        self::assertSame([
            'provider', 'model', 'api_key', 'base_url', 'system_prompt',
            'store_messages', 'max_iterations', 'cloud_key',
            'cloud_signing_secret', 'cloud_disable', 'remote_skill_urls',
        ], array_keys($this->block->getValues()));
    }

    public function test_get_values_returns_provider_from_config(): void
    {
        $this->config->method('getProvider')->willReturn('openai');
        $this->config->method('getModel')->willReturn('gpt-4o');
        $this->config->method('getApiKey')->willReturn('');
        $this->config->method('getBaseUrl')->willReturn('');
        $this->config->method('getSystemPrompt')->willReturn('');
        $this->config->method('isStoreMessages')->willReturn(false);
        $this->config->method('getMaxIterations')->willReturn(10);
        $this->config->method('getCloudKey')->willReturn('');
        $this->config->method('getCloudSigningSecret')->willReturn('');
        $this->config->method('getCloudDisable')->willReturn('');
        $this->config->method('getRemoteSkillUrls')->willReturn([]);

        $values = $this->block->getValues();

        self::assertSame('openai', $values['provider']);
        self::assertSame('gpt-4o', $values['model']);
    }

    public function test_get_values_masks_non_empty_api_key(): void
    {
        $this->config->method('getApiKey')->willReturn('plaintext-key');
        $this->config->method('getCloudKey')->willReturn('');
        $this->config->method('getCloudSigningSecret')->willReturn('');
        $this->config->method('getProvider')->willReturn('');
        $this->config->method('getModel')->willReturn('');
        $this->config->method('getBaseUrl')->willReturn('');
        $this->config->method('getSystemPrompt')->willReturn('');
        $this->config->method('isStoreMessages')->willReturn(true);
        $this->config->method('getMaxIterations')->willReturn(20);
        $this->config->method('getCloudDisable')->willReturn('');
        $this->config->method('getRemoteSkillUrls')->willReturn([]);

        $values = $this->block->getValues();

        self::assertSame('__phpclaw_secret_set__', $values['api_key']);
    }

    public function test_get_values_returns_empty_api_key_when_not_set(): void
    {
        $this->config->method('getApiKey')->willReturn('');
        $this->config->method('getCloudKey')->willReturn('');
        $this->config->method('getCloudSigningSecret')->willReturn('');
        $this->config->method('getProvider')->willReturn('');
        $this->config->method('getModel')->willReturn('');
        $this->config->method('getBaseUrl')->willReturn('');
        $this->config->method('getSystemPrompt')->willReturn('');
        $this->config->method('isStoreMessages')->willReturn(true);
        $this->config->method('getMaxIterations')->willReturn(20);
        $this->config->method('getCloudDisable')->willReturn('');
        $this->config->method('getRemoteSkillUrls')->willReturn([]);

        $values = $this->block->getValues();

        self::assertSame('', $values['api_key']);
    }

    public function test_get_provider_options_delegates_to_source_model(): void
    {
        $expected = [['value' => 'anthropic', 'label' => 'Anthropic (Claude)']];
        $this->provider->method('toOptionArray')->willReturn($expected);

        self::assertSame($expected, $this->block->getProviderOptions());
    }

    public function test_get_save_url_contains_settings_save(): void
    {
        self::assertStringContainsString('phpclaw/settings/save', $this->block->getSaveUrl());
    }

    public function test_get_test_connection_url_contains_test_connection(): void
    {
        self::assertStringContainsString('phpclaw/settings/testConnection', $this->block->getTestConnectionUrl());
    }

    public function test_has_api_key_true_when_api_key_non_empty(): void
    {
        $this->config->method('getApiKey')->willReturn('some-value');

        self::assertTrue($this->block->hasApiKey());
    }

    public function test_has_api_key_false_when_api_key_empty(): void
    {
        $this->config->method('getApiKey')->willReturn('');

        self::assertFalse($this->block->hasApiKey());
    }

    public function test_has_cloud_key_true_when_cloud_key_non_empty(): void
    {
        $this->config->method('getCloudKey')->willReturn('cloud-value');

        self::assertTrue($this->block->hasCloudKey());
    }

    public function test_has_cloud_key_false_when_cloud_key_empty(): void
    {
        $this->config->method('getCloudKey')->willReturn('');

        self::assertFalse($this->block->hasCloudKey());
    }

    public function test_get_values_masks_non_empty_cloud_signing_secret(): void
    {
        $this->config->method('getApiKey')->willReturn('');
        $this->config->method('getCloudKey')->willReturn('');
        $this->config->method('getCloudSigningSecret')->willReturn('my-signing-secret');
        $this->config->method('getProvider')->willReturn('');
        $this->config->method('getModel')->willReturn('');
        $this->config->method('getBaseUrl')->willReturn('');
        $this->config->method('getSystemPrompt')->willReturn('');
        $this->config->method('isStoreMessages')->willReturn(true);
        $this->config->method('getMaxIterations')->willReturn(20);
        $this->config->method('getCloudDisable')->willReturn('');
        $this->config->method('getRemoteSkillUrls')->willReturn([]);

        $values = $this->block->getValues();

        self::assertSame('__phpclaw_secret_set__', $values['cloud_signing_secret']);
    }

    public function test_get_values_returns_empty_cloud_signing_secret_when_not_set(): void
    {
        $this->config->method('getApiKey')->willReturn('');
        $this->config->method('getCloudKey')->willReturn('');
        $this->config->method('getCloudSigningSecret')->willReturn('');
        $this->config->method('getProvider')->willReturn('');
        $this->config->method('getModel')->willReturn('');
        $this->config->method('getBaseUrl')->willReturn('');
        $this->config->method('getSystemPrompt')->willReturn('');
        $this->config->method('isStoreMessages')->willReturn(true);
        $this->config->method('getMaxIterations')->willReturn(20);
        $this->config->method('getCloudDisable')->willReturn('');
        $this->config->method('getRemoteSkillUrls')->willReturn([]);

        $values = $this->block->getValues();

        self::assertSame('', $values['cloud_signing_secret']);
    }

    public function test_has_cloud_signing_secret_true_when_secret_non_empty(): void
    {
        $this->config->method('getCloudSigningSecret')->willReturn('some-secret');

        self::assertTrue($this->block->hasCloudSigningSecret());
    }

    public function test_has_cloud_signing_secret_false_when_secret_empty(): void
    {
        $this->config->method('getCloudSigningSecret')->willReturn('');

        self::assertFalse($this->block->hasCloudSigningSecret());
    }

    public function test_get_values_includes_remote_skill_urls_as_csv(): void
    {
        $this->config->method('getApiKey')->willReturn('');
        $this->config->method('getCloudKey')->willReturn('');
        $this->config->method('getCloudSigningSecret')->willReturn('');
        $this->config->method('getProvider')->willReturn('');
        $this->config->method('getModel')->willReturn('');
        $this->config->method('getBaseUrl')->willReturn('');
        $this->config->method('getSystemPrompt')->willReturn('');
        $this->config->method('isStoreMessages')->willReturn(true);
        $this->config->method('getMaxIterations')->willReturn(20);
        $this->config->method('getCloudDisable')->willReturn('');
        $this->config->method('getRemoteSkillUrls')->willReturn([
            'https://example.com/a.md',
            'https://example.com/b.json',
        ]);

        $values = $this->block->getValues();

        self::assertSame('https://example.com/a.md,https://example.com/b.json', $values['remote_skill_urls']);
    }

    public function test_store_messages_enabled_reports_the_config_flag(): void
    {
        $this->config->method('isStoreMessages')->willReturn(true);

        self::assertTrue($this->block->storeMessagesEnabled());
    }

    public function test_store_messages_enabled_reports_false_when_storage_is_off(): void
    {
        $this->config->method('isStoreMessages')->willReturn(false);

        self::assertFalse($this->block->storeMessagesEnabled());
    }
}

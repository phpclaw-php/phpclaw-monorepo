<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use PhpClaw\ClawConfig;
use PhpClaw\Cloud\CloudManager;
use PhpClaw\Magento\Model\Config;
use PhpClaw\Magento\Model\Config\Source\Provider;
use PhpClaw\Tools\ToolRegistry;

/**
 * Block for the phpClaw Settings page.
 */
// non-final: Magento interceptor required
class Settings extends Template
{
    private const PROVIDER_CUSTOM = 'custom';

    private const FALLBACK_OFF_LABEL = 'Off';

    private const PROVIDER_SELECT_LABEL = 'Select a provider';

    /**
     * Bind the block context and settings reader this block renders the form from.
     *
     * @param  Context  $context  Magento block context.
     * @param  Config  $config  phpClaw settings config accessor.
     * @param  Provider  $providerSource  Provider option source for the settings form.
     * @param  array<string, mixed>  $data  Optional block data passed from layout XML.
     * @return void
     */
    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly Provider $providerSource,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Return all saved configuration values keyed by field name for the settings template.
     *
     * @return array{provider: string, model: string, api_key: string, base_url: string, system_prompt: string, store_messages: bool, max_iterations: int, cloud_key: string, cloud_signing_secret: string, cloud_disable: string, remote_skill_urls: string, fallback_provider: string, fallback_model: string, fallback_api_key: string, rate_limit_rpm: int, response_cache: bool, response_cache_ttl: int, max_token_budget: int}
     */
    public function getValues(): array
    {
        return [
            'provider' => $this->config->getProvider(),
            'model' => $this->config->getModel(),
            'api_key' => $this->maskSecret($this->config->getApiKey()),
            'base_url' => $this->config->getBaseUrl(),
            'system_prompt' => $this->config->getSystemPrompt(),
            'store_messages' => $this->config->isStoreMessages(),
            'max_iterations' => $this->config->getMaxIterations(),
            'cloud_key' => $this->maskSecret($this->config->getCloudKey()),
            'cloud_signing_secret' => $this->maskSecret($this->config->getCloudSigningSecret()),
            'cloud_disable' => $this->config->getCloudDisable(),
            'remote_skill_urls' => implode(',', $this->config->getRemoteSkillUrls()),
            'fallback_provider' => $this->config->getFallbackProvider(),
            'fallback_model' => $this->config->getFallbackModel(),
            'fallback_api_key' => $this->maskSecret($this->config->getFallbackApiKey()),
            'rate_limit_rpm' => $this->config->getRateLimitRpm(),
            'response_cache' => $this->config->isResponseCache(),
            'response_cache_ttl' => $this->config->getResponseCacheTtl(),
            'max_token_budget' => $this->config->getMaxTokenBudget(),
        ];
    }

    /**
     * Fallback dropdown options: Off, then each provider sharing the saved main provider's tool format, never Custom.
     *
     * @return array<string, string> Option label keyed by provider slug, Off keyed by ''.
     */
    public function getFallbackProviderOptions(): array
    {
        $main = $this->config->getProvider();
        $format = ToolRegistry::toolFormat($main !== '' ? $main : (new ClawConfig)->providerName);
        $options = ['' => self::FALLBACK_OFF_LABEL];

        foreach ($this->getFallbackScriptData()['providers'] as $slug => $label) {
            if (ToolRegistry::toolFormat($slug) === $format) {
                $options[$slug] = $label;
            }
        }

        return $options;
    }

    /**
     * Data the settings script uses to rebuild the fallback dropdown when the main provider changes.
     *
     * @return array{offLabel: string, providers: array<string, string>, formats: array<string, string>, autoFormat: string}
     */
    public function getFallbackScriptData(): array
    {
        $providers = [];
        $formats = [];

        foreach ($this->providerSource->toOptionArray() as $option) {
            $slug = (string) $option['value'];
            if ($slug === '') {
                continue;
            }
            $formats[$slug] = ToolRegistry::toolFormat($slug);
            if ($slug !== self::PROVIDER_CUSTOM) {
                $providers[$slug] = (string) $option['label'];
            }
        }

        return [
            'offLabel' => self::FALLBACK_OFF_LABEL,
            'providers' => $providers,
            'formats' => $formats,
            'autoFormat' => ToolRegistry::toolFormat((new ClawConfig)->providerName),
        ];
    }

    /**
     * Provider option list for the settings select: an empty "Select a provider" choice, which leaves the provider
     * for core to auto-detect, then every option from the provider source model.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function getProviderOptions(): array
    {
        return [['value' => '', 'label' => self::PROVIDER_SELECT_LABEL], ...$this->providerSource->toOptionArray()];
    }

    /**
     * Admin URL for the settings save POST endpoint.
     *
     * @return string
     */
    public function getSaveUrl(): string
    {
        return $this->getUrl('phpclaw/settings/save');
    }

    /**
     * Admin URL for the AJAX test-connection endpoint.
     *
     * @return string
     */
    public function getTestConnectionUrl(): string
    {
        return $this->getUrl('phpclaw/settings/testConnection');
    }

    /**
     * Whether an API key has already been saved (used to show/hide the key placeholder in the template).
     *
     * @return bool
     */
    public function hasApiKey(): bool
    {
        return $this->config->getApiKey() !== '';
    }

    /**
     * Whether a fallback API key has already been saved (used to show the keep-blank placeholder in the template).
     *
     * @return bool
     */
    public function hasFallbackApiKey(): bool
    {
        return $this->config->getFallbackApiKey() !== '';
    }

    /**
     * Whether a Cloud key has already been saved (used to show/hide the key placeholder in the template).
     *
     * @return bool
     */
    public function hasCloudKey(): bool
    {
        return $this->config->getCloudKey() !== '';
    }

    /**
     * Whether a Cloud signing secret has already been saved (used to show/hide the placeholder in the template).
     *
     * @return bool
     */
    public function hasCloudSigningSecret(): bool
    {
        return $this->config->getCloudSigningSecret() !== '';
    }

    /**
     * Report whether message storage is on, which is what gates the cloud fields on this page.
     *
     * @return bool
     */
    public function storeMessagesEnabled(): bool
    {
        return $this->config->isStoreMessages();
    }

    /**
     * Whether the optional phpclaw/phpclaw-cloud package is installed.
     *
     * @return bool
     */
    public function isCloudAvailable(): bool
    {
        return class_exists(CloudManager::class);
    }

    /**
     * Return a fixed placeholder when a secret is stored, empty string otherwise.
     *
     * @param  string  $decrypted  Already-decrypted secret value from Config.
     * @return string '__phpclaw_secret_set__' when non-empty, '' otherwise.
     */
    private function maskSecret(string $decrypted): string
    {
        return $decrypted !== '' ? '__phpclaw_secret_set__' : '';
    }
}

<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use PhpClaw\Cloud\CloudManager;
use PhpClaw\Magento\Model\Config;
use PhpClaw\Magento\Model\Config\Source\Provider;

/**
 * Block for the phpClaw Settings page.
 */
// non-final: Magento interceptor required
class Settings extends Template
{
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
     * @return array{provider: string, model: string, api_key: string, base_url: string, system_prompt: string, store_messages: bool, max_iterations: int, cloud_key: string, cloud_signing_secret: string, cloud_disable: string, remote_skill_urls: string}
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
        ];
    }

    /**
     * Provider option list for the select element in the settings template.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function getProviderOptions(): array
    {
        return $this->providerSource->toOptionArray();
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

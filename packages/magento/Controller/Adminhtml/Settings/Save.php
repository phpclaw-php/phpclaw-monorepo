<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Controller\Adminhtml\Settings;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Encryption\EncryptorInterface;
use PhpClaw\ClawConfig;
use PhpClaw\Magento\Model\Config;
use PhpClaw\Magento\Service\CsvList;
use PhpClaw\Magento\Service\RemoteSkillUrlFilter;
use PhpClaw\Tools\ToolRegistry;

/**
 * Admin POST handler that persists phpClaw settings to the Magento config store.
 */
// non-final: Magento interceptor required
class Save extends Action
{
    public const ADMIN_RESOURCE = 'PhpClaw_Magento::phpclaw_settings';

    private const FIELDS = [
        'provider', 'model', 'api_key', 'base_url',
        'system_prompt', 'store_messages', 'max_iterations',
        'cloud_key', 'cloud_signing_secret', 'cloud_disable',
        'remote_skill_urls',
        'fallback_provider', 'fallback_model', 'fallback_api_key',
        'rate_limit_rpm', 'response_cache', 'response_cache_ttl', 'max_token_budget',
        'max_tools_per_turn', 'always_include_tools',
    ];

    private const CHECKBOX_FIELDS = ['store_messages', 'response_cache'];

    private const ENCRYPTED_FIELDS = ['api_key', 'cloud_key', 'cloud_signing_secret', 'fallback_api_key'];

    private const PROVIDER_CUSTOM = 'custom';

    private const FALLBACK_MISMATCH = 'Fallback provider must use the same tool format as the primary provider, and cannot be Custom.';

    private const SECRET_PLACEHOLDER = '__phpclaw_secret_set__';

    /**
     * Bind the action context and config writer this handler persists settings through.
     *
     * @param  Context  $context  Magento admin action context.
     * @param  WriterInterface  $configWriter  Config writer for persisting phpclaw/general/* values.
     * @param  EncryptorInterface  $encryptor  Magento encryptor used to encrypt api_key and cloud_key before save.
     * @param  RedirectFactory  $redirectFactory  Factory for creating redirect result instances.
     * @param  TypeListInterface  $cacheTypeList  Cache type list used to flush the config cache after save.
     * @param  Config  $config  Settings reader, for the saved main provider when the form does not post one.
     * @return void
     */
    public function __construct(
        Context $context,
        private readonly WriterInterface $configWriter,
        private readonly EncryptorInterface $encryptor,
        private readonly RedirectFactory $redirectFactory,
        private readonly TypeListInterface $cacheTypeList,
        private readonly Config $config,
    ) {
        parent::__construct($context);
    }

    /**
     * Save the allowlisted settings and redirect; an incompatible or Custom fallback saves nothing and shows an error.
     *
     * @return Redirect Redirect response pointing back to the settings index page.
     */
    public function execute(): Redirect
    {
        $request = $this->getRequest();
        $redirect = $this->redirectFactory->create()->setPath('phpclaw/settings/index');

        if (! $this->isFallbackCompatible($request)) {
            $this->messageManager->addErrorMessage((string) __(self::FALLBACK_MISMATCH));

            return $redirect;
        }

        foreach (self::FIELDS as $field) {
            if (! $request->has($field) && ! in_array($field, self::CHECKBOX_FIELDS, true)) {
                continue;
            }

            $value = $this->submittedValue($request, $field);

            if (in_array($field, self::ENCRYPTED_FIELDS, true)) {
                if ($value === '' || $value === self::SECRET_PLACEHOLDER) {
                    continue;
                }
                $value = $this->encryptor->encrypt($value);
            }

            $this->configWriter->save('phpclaw/general/'.$field, $value);
        }

        $this->cacheTypeList->cleanType('config');

        $this->messageManager->addSuccessMessage((string) __('phpClaw settings saved.'));

        return $redirect;
    }

    /**
     * The value to store for one field, normalised by type: checkbox, clamped number, filtered list or trimmed text.
     *
     * @param  RequestInterface  $request  The settings POST request.
     * @param  string  $field  Field name from FIELDS.
     * @return string
     */
    private function submittedValue(RequestInterface $request, string $field): string
    {
        $raw = trim((string) $request->getParam($field, ''));

        return match ($field) {
            'store_messages', 'response_cache' => $request->getParam($field) ? '1' : '0',
            'remote_skill_urls' => RemoteSkillUrlFilter::filter($raw),
            'system_prompt' => mb_substr($raw, 0, 8000),
            'rate_limit_rpm' => (string) Config::clampRateLimitRpm($raw),
            'response_cache_ttl' => (string) Config::clampResponseCacheTtl($raw),
            'max_token_budget' => (string) Config::clampMaxTokenBudget($raw),
            'max_tools_per_turn' => (string) Config::clampMaxToolsPerTurn($raw),
            'always_include_tools' => implode(',', CsvList::parse($raw)),
            default => $raw,
        };
    }

    /**
     * Whether the submitted fallback is off, or is not Custom and shares the posted or saved main provider's format.
     *
     * @param  RequestInterface  $request  The settings POST request.
     * @return bool
     */
    private function isFallbackCompatible(RequestInterface $request): bool
    {
        $fallback = trim((string) $request->getParam('fallback_provider', ''));

        if ($fallback === '') {
            return true;
        }

        if ($fallback === self::PROVIDER_CUSTOM) {
            return false;
        }

        $main = $request->has('provider') ? trim((string) $request->getParam('provider', '')) : $this->config->getProvider();

        return ToolRegistry::toolFormat($fallback) === ToolRegistry::toolFormat($main !== '' ? $main : (new ClawConfig)->providerName);
    }
}

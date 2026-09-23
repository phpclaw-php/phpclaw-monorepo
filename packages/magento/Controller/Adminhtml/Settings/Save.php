<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Controller\Adminhtml\Settings;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Encryption\EncryptorInterface;
use PhpClaw\Magento\Service\RemoteSkillUrlFilter;

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
    ];

    private const ENCRYPTED_FIELDS = ['api_key', 'cloud_key', 'cloud_signing_secret'];

    private const SECRET_PLACEHOLDER = '__phpclaw_secret_set__';

    /**
     * Bind the action context and config writer this handler persists settings through.
     *
     * @param  Context  $context  Magento admin action context.
     * @param  WriterInterface  $configWriter  Config writer for persisting phpclaw/general/* values.
     * @param  EncryptorInterface  $encryptor  Magento encryptor used to encrypt api_key and cloud_key before save.
     * @param  RedirectFactory  $redirectFactory  Factory for creating redirect result instances.
     * @param  TypeListInterface  $cacheTypeList  Cache type list used to flush the config cache after save.
     * @return void
     */
    public function __construct(
        Context $context,
        private readonly WriterInterface $configWriter,
        private readonly EncryptorInterface $encryptor,
        private readonly RedirectFactory $redirectFactory,
        private readonly TypeListInterface $cacheTypeList,
    ) {
        parent::__construct($context);
    }

    /**
     * Persist allowlisted phpClaw settings from the POST request and redirect.
     *
     * @return Redirect Redirect response pointing back to the settings index page.
     */
    public function execute(): Redirect
    {
        $request = $this->getRequest();

        foreach (self::FIELDS as $field) {
            if (! $request->has($field) && $field !== 'store_messages') {
                continue;
            }

            if ($field === 'store_messages') {
                $value = $request->getParam($field) ? '1' : '0';
            } elseif ($field === 'remote_skill_urls') {
                $value = RemoteSkillUrlFilter::filter(trim((string) $request->getParam($field, '')));
            } elseif ($field === 'system_prompt') {
                $value = mb_substr(trim((string) $request->getParam($field, '')), 0, 8000);
            } else {
                $value = trim((string) $request->getParam($field, ''));
            }

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

        return $this->redirectFactory->create()->setPath('phpclaw/settings/index');
    }
}

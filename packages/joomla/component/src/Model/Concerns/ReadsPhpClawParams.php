<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Component\Administrator\Model\Concerns;

use Joomla\CMS\Factory;
use PhpClaw\Joomla\Component\Administrator\Engine\EngineFactory;

/**
 * Shared plugin-parameter and acting-identity reads for phpClaw admin models.
 */
trait ReadsPhpClawParams
{
    /**
     * Whether the store_messages setting is enabled.
     *
     * @return bool
     */
    public function isStoreMessages(): bool
    {
        return (bool) EngineFactory::getPluginParams()->get('store_messages', true);
    }

    /**
     * Whether a cloud key is configured.
     *
     * @return bool
     */
    public function hasCloudKey(): bool
    {
        return (string) EngineFactory::getPluginParams()->get('cloud_key', '') !== '';
    }

    /**
     * Return true if the acting user holds phpclaw.chat.manageall on com_phpclaw.
     *
     * @return bool
     */
    private function canManageAll(): bool
    {
        try {
            return (bool) Factory::getApplication()
                ->getIdentity()
                ->authorise('phpclaw.chat.manageall', 'com_phpclaw');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Resolve the acting user's id, 0 if unavailable.
     *
     * @return int
     */
    private function resolveActingUserId(): int
    {
        try {
            return (int) Factory::getApplication()->getIdentity()->id;
        } catch (\Throwable) {
            return 0;
        }
    }
}

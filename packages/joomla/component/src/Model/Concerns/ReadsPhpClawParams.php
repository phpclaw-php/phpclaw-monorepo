<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Component\Administrator\Model\Concerns;

use PhpClaw\Joomla\Component\Administrator\Engine\EngineFactory;

/**
 * Shared plugin-parameter reads for phpClaw admin models.
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
        $params = EngineFactory::getPluginParams();
        $key = (string) $params->get('cloud_key', '');

        return $key !== '';
    }
}

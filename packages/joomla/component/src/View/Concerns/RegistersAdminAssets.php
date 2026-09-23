<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Component\Administrator\View\Concerns;

use Joomla\CMS\Factory;

/**
 * Shared Web Asset Manager registration for the four phpClaw admin views, replacing the
 * four per-view copies of the stylesheet handle and path with one declaration.
 */
trait RegistersAdminAssets
{
    /**
     * Register and enqueue the shared phpClaw admin stylesheet. The handle and path are inline
     * because trait constants need PHP 8.2 and this package supports 8.1.
     *
     * @return void
     */
    private function enqueueAdminStyle(): void
    {
        Factory::getApplication()
            ->getDocument()
            ->getWebAssetManager()
            ->registerAndUseStyle('com_phpclaw.admin', 'com_phpclaw/phpclaw-admin.css');
    }
}

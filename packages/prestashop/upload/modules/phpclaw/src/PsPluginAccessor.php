<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop;

use PhpClaw\PrestaShop\Db\PsDbAdapter;

/**
 * Shared Plugin singleton accessor for phpClaw admin and front controllers.
 */
trait PsPluginAccessor
{
    private ?PsDbAdapter $pluginAccessorDb = null;

    /**
     * Return (or boot) the Plugin singleton using the native PS database handle.
     *
     * @return Plugin
     */
    protected function getPlugin(): Plugin
    {
        return Plugin::getInstance(
            $this->getDb(),
            _DB_PREFIX_,
            PsIdentityResolver::actingEmployeeId(),
            PsIdentityResolver::manageAll(),
        );
    }

    /**
     * Build (or reuse) a PsDbAdapter wrapping the native PS database singleton.
     *
     * @return PsDbAdapter
     */
    protected function getDb(): PsDbAdapter
    {
        return $this->pluginAccessorDb ??= new PsDbAdapter(\Db::getInstance());
    }
}

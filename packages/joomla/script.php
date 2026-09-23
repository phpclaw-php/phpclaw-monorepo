<?php

declare(strict_types=1);

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or exit;

return new class implements InstallerScriptInterface
{
    /**
     * Run before the install, update or uninstall routine.
     *
     * @param  string  $type  The routine being run.
     * @param  InstallerAdapter  $adapter  The adapter running this script.
     * @return bool
     */
    public function preflight(string $type, InstallerAdapter $adapter): bool
    {
        return true;
    }

    /**
     * Run the install routine.
     *
     * @param  InstallerAdapter  $adapter  The adapter running this script.
     * @return bool
     */
    public function install(InstallerAdapter $adapter): bool
    {
        return true;
    }

    /**
     * Run the update routine.
     *
     * @param  InstallerAdapter  $adapter  The adapter running this script.
     * @return bool
     */
    public function update(InstallerAdapter $adapter): bool
    {
        return true;
    }

    /**
     * Run the uninstall routine.
     *
     * @param  InstallerAdapter  $adapter  The adapter running this script.
     * @return bool
     */
    public function uninstall(InstallerAdapter $adapter): bool
    {
        return true;
    }

    /**
     * Run after the install, update or uninstall routine.
     *
     * @param  string  $type  The routine that was run.
     * @param  InstallerAdapter  $adapter  The adapter running this script.
     * @return bool
     */
    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        if ($type === 'install' || $type === 'update' || $type === 'discover_install') {
            $this->enableBundledPlugins();
        }

        return true;
    }

    /**
     * Enable the two plugins this package ships, which Joomla stores disabled on install.
     *
     * @return void
     */
    private function enableBundledPlugins(): void
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        foreach (['system', 'webservices'] as $folder) {
            $query = $db->getQuery(true)
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('enabled').' = 1')
                ->where($db->quoteName('type').' = '.$db->quote('plugin'))
                ->where($db->quoteName('folder').' = '.$db->quote($folder))
                ->where($db->quoteName('element').' = '.$db->quote('phpclaw'));

            $db->setQuery($query);
            $db->execute();
        }
    }
};

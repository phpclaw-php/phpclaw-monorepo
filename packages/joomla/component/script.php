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
            $this->seedAclRules();
        }

        return true;
    }

    /**
     * Grant the default phpClaw permissions to the Manager, Administrator and Super Users groups.
     *
     * @return void
     */
    private function seedAclRules(): void
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $wanted = ['Manager', 'Administrator', 'Super Users'];

        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'title']))
            ->from($db->quoteName('#__usergroups'))
            ->where('LOWER('.$db->quoteName('title').') IN ('.implode(',', array_map(
                fn (string $g) => $db->quote(strtolower($g)),
                $wanted
            )).')');
        $db->setQuery($query);
        $rows = $db->loadObjectList();

        $byLowerTitle = [];
        foreach ($rows as $row) {
            $byLowerTitle[strtolower((string) $row->title)] = (int) $row->id;
        }

        $groupIds = [];
        $unresolved = [];
        foreach ($wanted as $name) {
            if (isset($byLowerTitle[strtolower($name)])) {
                $groupIds[$name] = $byLowerTitle[strtolower($name)];
            } else {
                $unresolved[] = $name;
            }
        }

        if ($unresolved !== []) {
            error_log(
                'phpClaw install: no user group matched '.implode(', ', $unresolved)
                .'. Grant the phpclaw.chat.* permissions manually under System > Global Configuration > phpClaw > Permissions.'
            );
        }

        $query = $db->getQuery(true)
            ->select($db->quoteName('rules'))
            ->from($db->quoteName('#__assets'))
            ->where($db->quoteName('name').' = '.$db->quote('com_phpclaw'));
        $db->setQuery($query);
        $rulesJson = $db->loadResult();

        $rules = [];

        if ($rulesJson !== null && $rulesJson !== '') {
            $decoded = json_decode($rulesJson, true);

            if (is_array($decoded)) {
                $rules = $decoded;
            }
        }

        $defaults = [
            'core.manage' => ['Manager'],
            'phpclaw.chat.use' => ['Manager', 'Administrator', 'Super Users'],
            'phpclaw.chat.manageall' => ['Administrator', 'Super Users'],
        ];

        $changed = false;

        foreach ($defaults as $action => $targetGroups) {
            if (! isset($rules[$action]) || ! is_array($rules[$action])) {
                $rules[$action] = [];
            }

            foreach ($targetGroups as $groupName) {
                if (! isset($groupIds[$groupName])) {
                    continue;
                }

                $gid = $groupIds[$groupName];

                if (! array_key_exists($gid, $rules[$action])) {
                    $rules[$action][$gid] = 1;
                    $changed = true;
                }
            }
        }

        if (! $changed) {
            return;
        }

        $query = $db->getQuery(true)
            ->update($db->quoteName('#__assets'))
            ->set($db->quoteName('rules').' = '.$db->quote(json_encode($rules)))
            ->where($db->quoteName('name').' = '.$db->quote('com_phpclaw'));
        $db->setQuery($query);
        $db->execute();
    }
};

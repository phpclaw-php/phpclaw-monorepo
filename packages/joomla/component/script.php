<?php

declare(strict_types=1);

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or exit;

return new class implements InstallerScriptInterface
{
    private const COMPONENT_ASSET = 'com_phpclaw';

    private const GROUP_TITLES = ['Manager', 'Administrator', 'Super Users'];

    private const DEFAULT_RULES = [
        'core.manage' => ['Manager'],
        'phpclaw.chat.use' => ['Manager', 'Administrator', 'Super Users'],
        'phpclaw.chat.manageall' => ['Administrator', 'Super Users'],
    ];

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
        $groupIds = $this->resolveGroupIds($db);
        [$rules, $changed] = self::withDefaultRules($this->loadRules($db), $groupIds);

        if (! $changed) {
            return;
        }

        $query = $db->getQuery(true)
            ->update($db->quoteName('#__assets'))
            ->set($db->quoteName('rules').' = '.$db->quote(json_encode($rules)))
            ->where($db->quoteName('name').' = '.$db->quote(self::COMPONENT_ASSET));
        $db->setQuery($query);
        $db->execute();
    }

    /**
     * Map each wanted group title to its id, matching titles case-insensitively, and log the titles no group carries.
     *
     * @param  DatabaseInterface  $db  Joomla database connection.
     * @return array<string, int> Group id keyed by wanted title.
     */
    private function resolveGroupIds(DatabaseInterface $db): array
    {
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'title']))
            ->from($db->quoteName('#__usergroups'))
            ->where('LOWER('.$db->quoteName('title').') IN ('.implode(',', array_map(
                fn (string $title) => $db->quote(strtolower($title)),
                self::GROUP_TITLES
            )).')');
        $db->setQuery($query);

        $byLowerTitle = [];
        foreach ($db->loadObjectList() as $row) {
            $byLowerTitle[strtolower((string) $row->title)] = (int) $row->id;
        }

        $groupIds = [];
        $unresolved = [];
        foreach (self::GROUP_TITLES as $title) {
            if (isset($byLowerTitle[strtolower($title)])) {
                $groupIds[$title] = $byLowerTitle[strtolower($title)];

                continue;
            }

            $unresolved[] = $title;
        }

        if ($unresolved !== []) {
            error_log(
                'phpClaw install: no user group matched '.implode(', ', $unresolved)
                .'. Grant the phpclaw.chat.* permissions manually under System > Global Configuration > phpClaw > Permissions.'
            );
        }

        return $groupIds;
    }

    /**
     * Read the component asset's stored rules, or an empty set when none are stored or they cannot be decoded.
     *
     * @param  DatabaseInterface  $db  Joomla database connection.
     * @return array<string, mixed>
     */
    private function loadRules(DatabaseInterface $db): array
    {
        $query = $db->getQuery(true)
            ->select($db->quoteName('rules'))
            ->from($db->quoteName('#__assets'))
            ->where($db->quoteName('name').' = '.$db->quote(self::COMPONENT_ASSET));
        $db->setQuery($query);
        $rulesJson = $db->loadResult();

        if ($rulesJson === null || $rulesJson === '') {
            return [];
        }

        $decoded = json_decode($rulesJson, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Add every default grant a resolved group lacks, keeping any rule a group already has.
     *
     * @param  array<string, mixed>  $rules  Stored rules.
     * @param  array<string, int>  $groupIds  Group id keyed by title.
     * @return array{0: array<string, mixed>, 1: bool} The rules, and whether a grant was added.
     */
    private static function withDefaultRules(array $rules, array $groupIds): array
    {
        $changed = false;

        foreach (self::DEFAULT_RULES as $action => $targetGroups) {
            if (! isset($rules[$action]) || ! is_array($rules[$action])) {
                $rules[$action] = [];
            }

            foreach ($targetGroups as $groupName) {
                $groupId = $groupIds[$groupName] ?? null;

                if ($groupId === null || array_key_exists($groupId, $rules[$action])) {
                    continue;
                }

                $rules[$action][$groupId] = 1;
                $changed = true;
            }
        }

        return [$rules, $changed];
    }
};

<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Data patch that grants phpClaw ACL resources to admin roles with full backend access.
 */
// non-final: Magento interceptor required
class GrantPhpClawAclToAdmins implements DataPatchInterface
{
    private const RESOURCES = [
        'PhpClaw_Magento::phpclaw',
        'PhpClaw_Magento::phpclaw_settings',
        'PhpClaw_Magento::phpclaw_chat',
        'PhpClaw_Magento::phpclaw_analytics',
        'PhpClaw_Magento::phpclaw_guide',
        'PhpClaw_Magento::phpclaw_about',
    ];

    /**
     * Bind the setup helper this patch writes ACL rules through.
     *
     * @param  ModuleDataSetupInterface  $moduleDataSetup  Provides the DB connection and table prefix.
     * @return void
     */
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
    ) {}

    /**
     * Insert allow rules for all phpClaw ACL resources into qualifying admin roles.
     *
     * @return self
     */
    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $ruleTable = $this->moduleDataSetup->getTable('authorization_rule');

        $adminRoles = $connection->fetchAll(
            'SELECT DISTINCT role_id FROM '.$ruleTable
            .' WHERE resource_id = ? AND permission = ?',
            ['Magento_Backend::all', 'allow'],
        );

        foreach ($adminRoles as $row) {
            $roleId = (int) $row['role_id'];

            foreach (self::RESOURCES as $resourceId) {
                $exists = (int) $connection->fetchOne(
                    'SELECT COUNT(*) FROM '.$ruleTable
                    .' WHERE role_id = ? AND resource_id = ?',
                    [$roleId, $resourceId],
                );

                if ($exists > 0) {
                    continue;
                }

                $connection->insert($ruleTable, [
                    'role_id' => $roleId,
                    'resource_id' => $resourceId,
                    'permission' => 'allow',
                ]);
            }
        }

        return $this;
    }

    /**
     * Return the list of patch classes this patch depends on.
     *
     * @return array<class-string>
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * Return any alias class names for this patch (for patch history de-duplication).
     *
     * @return array<string>
     */
    public function getAliases(): array
    {
        return [];
    }
}

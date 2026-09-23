<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Setup;

use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;

/**
 * Uninstall handler: drops phpClaw tables and removes config on module uninstall.
 */
// non-final: Magento interceptor required
class Uninstall implements UninstallInterface
{
    /**
     * Drop the phpClaw tables and remove its configuration rows.
     *
     * @param  SchemaSetupInterface  $setup  Schema setup helper providing connection and table-name resolution.
     * @param  ModuleContextInterface  $context  Module context providing version info (unused here).
     * @return void
     */
    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context): void
    {
        $setup->startSetup();

        $connection = $setup->getConnection();

        foreach (['phpclaw_messages', 'phpclaw_conversations', 'phpclaw_memory'] as $table) {
            if ($connection->isTableExists($setup->getTable($table))) {
                $connection->dropTable($setup->getTable($table));
            }
        }

        $connection->delete(
            $setup->getTable('core_config_data'),
            "path LIKE 'phpclaw/%'"
        );

        $setup->endSetup();
    }
}

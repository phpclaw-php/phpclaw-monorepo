<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the phpclaw_conversations owner column and its index when the table was created without them.
 */
final class Version20240101000002 extends AbstractMigration
{
    private const TABLE = 'phpclaw_conversations';

    /**
     * Returns a human-readable description of this migration.
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'Add the owner column to phpclaw_conversations on installs created without it.';
    }

    /**
     * Applies the migration, adding user_id and its owner index only when the column is missing.
     *
     * @param  Schema  $schema
     * @return void
     */
    public function up(Schema $schema): void
    {
        if (! $schema->hasTable(self::TABLE)) {
            return;
        }

        $conversations = $schema->getTable(self::TABLE);

        if ($conversations->hasColumn('user_id')) {
            return;
        }

        $conversations->addColumn('user_id', 'string', ['length' => 180, 'default' => '']);
        $conversations->addIndex(['user_id', 'namespace', 'updated_at'], 'idx_phpclaw_conversations_user_ns_updated');
    }
}

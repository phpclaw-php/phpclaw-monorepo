<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the three phpClaw tables: phpclaw_conversations, phpclaw_messages, phpclaw_memory.
 */
final class Version20240101000001 extends AbstractMigration
{
    /**
     * Returns a human-readable description of this migration.
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'Create phpClaw tables: conversations, messages, memory.';
    }

    /**
     * Applies the migration, creating all three phpClaw tables.
     *
     * @param  Schema  $schema
     * @return void
     */
    public function up(Schema $schema): void
    {
        $conversations = $schema->createTable('phpclaw_conversations');
        $conversations->addColumn('id', 'string', ['length' => 26, 'fixed' => true]);
        $conversations->addColumn('namespace', 'string', ['length' => 100, 'default' => 'default']);
        $conversations->addColumn('user_id', 'string', ['length' => 180, 'default' => '']);
        $conversations->addColumn('title', 'string', ['length' => 255, 'notnull' => false]);
        $conversations->addColumn('metadata', 'json', ['notnull' => false]);
        $conversations->addColumn('created_at', 'datetime', ['notnull' => true]);
        $conversations->addColumn('updated_at', 'datetime', ['notnull' => true]);
        $conversations->setPrimaryKey(['id']);
        $conversations->addIndex(['namespace', 'created_at'], 'idx_phpclaw_conversations_ns_created');
        $conversations->addIndex(['user_id', 'namespace', 'updated_at'], 'idx_phpclaw_conversations_user_ns_updated');

        $messages = $schema->createTable('phpclaw_messages');
        $messages->addColumn('id', 'string', ['length' => 26, 'fixed' => true]);
        $messages->addColumn('conversation_id', 'string', ['length' => 26, 'fixed' => true]);
        $messages->addColumn('role', 'string', ['length' => 20]);
        $messages->addColumn('content', 'text', ['notnull' => false]);
        $messages->addColumn('tool_name', 'string', ['length' => 255, 'notnull' => false]);
        $messages->addColumn('tool_input', 'text', ['notnull' => false]);
        $messages->addColumn('created_at', 'datetime', ['notnull' => true]);
        $messages->setPrimaryKey(['id']);
        $messages->addIndex(['conversation_id', 'created_at'], 'idx_phpclaw_messages_conv_created');
        $messages->addForeignKeyConstraint(
            'phpclaw_conversations',
            ['conversation_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_phpclaw_messages_conversation',
        );

        $memory = $schema->createTable('phpclaw_memory');
        $memory->addColumn('id', 'string', ['length' => 26, 'fixed' => true]);
        $memory->addColumn('namespace', 'string', ['length' => 100, 'default' => 'default']);
        $memory->addColumn('lookup_key', 'string', ['length' => 255]);
        $memory->addColumn('value', 'text');
        $memory->addColumn('expires_at', 'datetime', ['notnull' => false]);
        $memory->addColumn('created_at', 'datetime', ['notnull' => true]);
        $memory->addColumn('updated_at', 'datetime', ['notnull' => true]);
        $memory->setPrimaryKey(['id']);
        $memory->addUniqueIndex(['namespace', 'lookup_key'], 'uq_namespace_key');
        $memory->addIndex(['namespace', 'expires_at'], 'idx_phpclaw_memory_ns_expires');
    }

    /**
     * Reverts the migration, dropping all three phpClaw tables.
     *
     * @param  Schema  $schema
     * @return void
     */
    public function down(Schema $schema): void
    {
        $schema->dropTable('phpclaw_messages');
        $schema->dropTable('phpclaw_conversations');
        $schema->dropTable('phpclaw_memory');
    }
}

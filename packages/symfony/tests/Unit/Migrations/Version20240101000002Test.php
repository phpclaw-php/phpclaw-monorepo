<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\Schema;
use PhpClaw\Symfony\Migrations\Version20240101000001;
use PhpClaw\Symfony\Migrations\Version20240101000002;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class Version20240101000002Test extends TestCase
{
    public function test_description_names_the_owner_column_it_adds(): void
    {
        self::assertSame(
            'Add the owner column to phpclaw_conversations on installs created without it.',
            $this->migration()->getDescription(),
        );
    }

    public function test_up_adds_the_owner_column_and_index_to_a_table_created_without_them(): void
    {
        $schema = $this->legacySchema();

        $this->migration()->up($schema);

        $conversations = $schema->getTable('phpclaw_conversations');
        self::assertTrue($conversations->hasColumn('user_id'));
        self::assertSame(180, $conversations->getColumn('user_id')->getLength());
        self::assertSame('', $conversations->getColumn('user_id')->getDefault());
        self::assertTrue($conversations->getColumn('user_id')->getNotnull());
        self::assertSame(
            ['user_id', 'namespace', 'updated_at'],
            $conversations->getIndex('idx_phpclaw_conversations_user_ns_updated')->getColumns(),
        );
    }

    public function test_up_leaves_a_table_that_already_has_the_owner_column_unchanged(): void
    {
        $schema = new Schema;
        $this->firstMigration()->up($schema);
        $before = clone $schema;

        $this->migration()->up($schema);

        self::assertTrue((new Comparator)->compareSchemas($before, $schema)->isEmpty());
    }

    public function test_up_does_nothing_when_the_conversations_table_is_missing(): void
    {
        $schema = new Schema;

        $this->migration()->up($schema);

        self::assertSame([], $schema->getTableNames());
    }

    public function test_upgrading_a_legacy_database_keeps_its_rows_and_accepts_an_owned_conversation(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $platform = $connection->getDatabasePlatform();
        $legacy = $this->legacySchema();

        foreach ($legacy->toSql($platform) as $statement) {
            $connection->executeStatement($statement);
        }
        $connection->insert('phpclaw_conversations', [
            'id' => '01HLLLLLLLLLLLLLLLLLLLLLLL',
            'namespace' => 'conversations',
            'title' => null,
            'metadata' => null,
            'created_at' => '2026-07-06 00:34:26',
            'updated_at' => '2026-07-06 00:34:26',
        ]);

        $upgraded = clone $legacy;
        $this->migration()->up($upgraded);
        $diff = (new Comparator)->compareSchemas($legacy, $upgraded);
        foreach ($platform->getAlterSchemaSQL($diff) as $statement) {
            $connection->executeStatement($statement);
        }

        $connection->insert('phpclaw_conversations', [
            'id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ',
            'namespace' => 'conversations',
            'user_id' => 'alice@example.com',
            'title' => null,
            'metadata' => null,
            'created_at' => '2026-09-30 10:00:00',
            'updated_at' => '2026-09-30 10:00:00',
        ]);

        self::assertSame(
            ['01HLLLLLLLLLLLLLLLLLLLLLLL' => '', '01HZZZZZZZZZZZZZZZZZZZZZZZ' => 'alice@example.com'],
            $connection->fetchAllKeyValue('SELECT id, user_id FROM phpclaw_conversations ORDER BY id'),
        );
    }

    private function legacySchema(): Schema
    {
        $schema = new Schema;
        $this->firstMigration()->up($schema);
        $conversations = $schema->getTable('phpclaw_conversations');
        $conversations->dropIndex('idx_phpclaw_conversations_user_ns_updated');
        $conversations->dropColumn('user_id');

        return $schema;
    }

    private function migration(): Version20240101000002
    {
        return new Version20240101000002(
            $this->createMock(Connection::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    private function firstMigration(): Version20240101000001
    {
        return new Version20240101000001(
            $this->createMock(Connection::class),
            $this->createMock(LoggerInterface::class),
        );
    }
}

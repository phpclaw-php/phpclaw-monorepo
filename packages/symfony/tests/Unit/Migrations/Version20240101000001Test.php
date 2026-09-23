<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use PhpClaw\Symfony\Migrations\Version20240101000001;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class Version20240101000001Test extends TestCase
{
    public function test_description_names_the_three_tables_it_creates(): void
    {
        self::assertSame(
            'Create phpClaw tables: conversations, messages, memory.',
            $this->migration()->getDescription(),
        );
    }

    public function test_up_creates_exactly_the_three_phpclaw_tables(): void
    {
        $names = $this->schemaAfterUp()->getTableNames();
        sort($names);

        self::assertSame(
            ['phpclaw_conversations', 'phpclaw_memory', 'phpclaw_messages'],
            array_map(static fn (string $n): string => str_replace('public.', '', $n), $names),
        );
    }

    public function test_messages_cascade_from_their_conversation(): void
    {
        $messages = $this->schemaAfterUp()->getTable('phpclaw_messages');
        $keys = $messages->getForeignKeys();

        self::assertCount(1, $keys);

        $key = array_values($keys)[0];

        self::assertSame(['conversation_id'], $key->getLocalColumns());
        self::assertSame(['id'], $key->getForeignColumns());
        self::assertSame('phpclaw_conversations', $key->getForeignTableName());
        self::assertSame('CASCADE', $key->getOption('onDelete'));
    }

    public function test_conversations_carry_an_owner_column_indexed_for_scoped_reads(): void
    {
        $conversations = $this->schemaAfterUp()->getTable('phpclaw_conversations');

        self::assertTrue($conversations->hasColumn('user_id'));
        self::assertTrue($conversations->hasIndex('idx_phpclaw_conversations_user_ns_updated'));
        self::assertSame(
            ['user_id', 'namespace', 'updated_at'],
            $conversations->getIndex('idx_phpclaw_conversations_user_ns_updated')->getColumns(),
        );
    }

    public function test_memory_keys_are_unique_per_namespace(): void
    {
        $memory = $this->schemaAfterUp()->getTable('phpclaw_memory');

        self::assertTrue($memory->getIndex('uq_namespace_key')->isUnique());
        self::assertSame(['namespace', 'lookup_key'], $memory->getIndex('uq_namespace_key')->getColumns());
    }

    public function test_down_drops_every_table_up_created(): void
    {
        $schema = $this->schemaAfterUp();
        $this->migration()->down($schema);

        self::assertSame([], $schema->getTableNames());
    }

    #[DataProvider('platforms')]
    public function test_up_generates_ddl_for_every_supported_platform(AbstractPlatform $platform): void
    {
        $statements = $this->schemaAfterUp()->toSql($platform);

        self::assertNotSame([], $statements);

        $created = 0;
        foreach ($statements as $sql) {
            if (str_contains($sql, 'CREATE TABLE')) {
                $created++;
            }
        }

        self::assertSame(3, $created);
    }

    public function test_the_generated_ddl_runs_and_accepts_a_conversation_with_a_message(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach ($this->schemaAfterUp()->toSql($connection->getDatabasePlatform()) as $statement) {
            $connection->executeStatement($statement);
        }

        $connection->insert('phpclaw_conversations', [
            'id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ',
            'namespace' => 'conversations',
            'user_id' => 'alice@example.com',
            'title' => null,
            'metadata' => null,
            'created_at' => '2026-09-13 10:00:00',
            'updated_at' => '2026-09-13 10:00:00',
        ]);
        $connection->insert('phpclaw_messages', [
            'id' => '01HMMMMMMMMMMMMMMMMMMMMMMM',
            'conversation_id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ',
            'role' => 'user',
            'content' => 'hello',
            'tool_name' => null,
            'tool_input' => null,
            'created_at' => '2026-09-13 10:00:00',
        ]);

        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM phpclaw_messages'));
        self::assertSame(
            'alice@example.com',
            $connection->fetchOne('SELECT user_id FROM phpclaw_conversations'),
        );
    }

    public static function platforms(): array
    {
        return [
            'sqlite' => [new SqlitePlatform],
            'mysql' => [new MySQL80Platform],
            'postgresql' => [new PostgreSQLPlatform],
        ];
    }

    private function migration(): Version20240101000001
    {
        return new Version20240101000001(
            $this->createMock(Connection::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    private function schemaAfterUp(): Schema
    {
        $schema = new Schema;
        $this->migration()->up($schema);

        return $schema;
    }
}

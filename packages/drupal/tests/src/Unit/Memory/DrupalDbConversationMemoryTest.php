<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Unit\Memory;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Delete;
use Drupal\Core\Database\Query\Insert;
use Drupal\Core\Database\Query\Update;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Database\Transaction;
use PhpClaw\Drupal\Exceptions\ConversationAccessDeniedException;
use PhpClaw\Drupal\Memory\DrupalDbConversationMemory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class DrupalDbConversationMemoryTest extends TestCase
{
    private Connection $db;

    private DrupalDbConversationMemory $memory;

    private Transaction $transaction;

    protected function setUp(): void
    {
        $this->db = $this->createMock(Connection::class);
        $this->transaction = new class extends Transaction
        {
            public $rolledBack = false;

            public function __construct() {}

            public function __destruct() {}

            public function rollBack()
            {
                $this->rolledBack = true;
            }
        };
        $this->db->method('startTransaction')->willReturn($this->transaction);

        $config = $this->createMock(ImmutableConfig::class);
        $config->method('get')->with('store_messages')->willReturn(true);
        $configFactory = $this->createMock(ConfigFactoryInterface::class);
        $configFactory->method('get')->with('phpclaw.settings')->willReturn($config);

        $this->memory = new DrupalDbConversationMemory($this->db, $configFactory, null, 7);

        $logger = new NullLogger;
        $loggerFactory = new class($logger)
        {
            public function __construct(private readonly NullLogger $logger) {}

            public function get(string $channel): NullLogger
            {
                return $this->logger;
            }
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturnCallback(static fn (string $id) => match ($id) {
            'logger.factory' => $loggerFactory,
            default => throw new \InvalidArgumentException("Service {$id} not registered in test container."),
        });

        \Drupal::setContainer($container);
    }

    protected function tearDown(): void
    {
        \Drupal::unsetContainer();
    }

    private function buildQueryStmt(mixed $fetchAssocReturn, mixed $fetchFieldReturn = false, array $fetchColReturn = []): StatementInterface
    {
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchAssoc')->willReturn($fetchAssocReturn);
        $stmt->method('fetchField')->willReturn($fetchFieldReturn);
        $stmt->method('fetchCol')->willReturn($fetchColReturn);

        return $stmt;
    }

    private function buildInsertMock(): Insert
    {
        $insert = $this->createMock(Insert::class);
        $insert->method('fields')->willReturnSelf();
        $insert->method('execute')->willReturn('1');

        return $insert;
    }

    private function buildDeleteMock(): Delete
    {
        $delete = $this->createMock(Delete::class);
        $delete->method('condition')->willReturnSelf();
        $delete->method('execute')->willReturn(0);

        return $delete;
    }

    private function buildUpdateMock(): Update
    {
        $update = $this->createMock(Update::class);
        $update->method('fields')->willReturnSelf();
        $update->method('condition')->willReturnSelf();
        $update->method('execute')->willReturn(1);

        return $update;
    }

    public function test_get_returns_null_when_conversation_not_found(): void
    {
        $stmt = $this->buildQueryStmt(false);
        $this->db->method('query')->willReturn($stmt);

        $result = $this->memory->get('nonexistent-id', 'default');

        $this->assertNull($result);
    }

    public function test_get_returns_conversation_data_when_found(): void
    {
        $convRow = [
            'id' => 'conv-01',
            'namespace' => 'default',
            'user_id' => 7,
            'title' => 'Hello',
            'metadata' => '{"key":"val"}',
            'created_at' => '2024-01-01 00:00:00',
            'updated_at' => '2024-01-01 01:00:00',
        ];

        $msgStmt = $this->createMock(StatementInterface::class);
        $msgStmt->method('fetchAssoc')->willReturn(false);

        $convStmt = $this->createMock(StatementInterface::class);
        $convStmt->method('fetchAssoc')->willReturn($convRow);

        $this->db->expects($this->exactly(2))
            ->method('query')
            ->willReturnOnConsecutiveCalls($convStmt, $msgStmt);

        $result = $this->memory->get('conv-01', 'default');

        $this->assertIsArray($result);
        $this->assertSame('conv-01', $result['id']);
        $this->assertSame('Hello', $result['title']);
        $this->assertSame(['key' => 'val'], $result['metadata']);
        $this->assertSame([], $result['history']);
    }

    public function test_get_appends_messages_to_history(): void
    {
        $convRow = [
            'id' => 'conv-02',
            'namespace' => 'default',
            'user_id' => 7,
            'title' => 'Chat',
            'metadata' => null,
            'created_at' => '2024-01-01 00:00:00',
            'updated_at' => '2024-01-01 01:00:00',
        ];

        $msgRow1 = ['role' => 'user',      'content' => 'Hello',    'tool_name' => null,   'tool_input' => null];
        $msgRow2 = ['role' => 'assistant', 'content' => 'Hi there', 'tool_name' => null,   'tool_input' => null];

        $msgStmt = $this->createMock(StatementInterface::class);
        $msgStmt->method('fetchAssoc')->willReturnOnConsecutiveCalls($msgRow1, $msgRow2, false);

        $convStmt = $this->createMock(StatementInterface::class);
        $convStmt->method('fetchAssoc')->willReturn($convRow);

        $this->db->expects($this->exactly(2))
            ->method('query')
            ->willReturnOnConsecutiveCalls($convStmt, $msgStmt);

        $result = $this->memory->get('conv-02', 'default');

        $this->assertCount(2, $result['history']);
        $this->assertSame('user', $result['history'][0]['role']);
        $this->assertSame('Hello', $result['history'][0]['content']);
        $this->assertSame('assistant', $result['history'][1]['role']);
    }

    public function test_get_includes_tool_fields_when_tool_name_set(): void
    {
        $convRow = [
            'id' => 'conv-03', 'namespace' => 'default', 'user_id' => 7, 'title' => null,
            'metadata' => null, 'created_at' => '2024-01-01', 'updated_at' => '2024-01-01',
        ];

        $msgRow = [
            'role' => 'tool',
            'content' => 'result',
            'tool_name' => 'drupal_config',
            'tool_input' => '{"config_name":"system.site"}',
        ];

        $msgStmt = $this->createMock(StatementInterface::class);
        $msgStmt->method('fetchAssoc')->willReturnOnConsecutiveCalls($msgRow, false);

        $convStmt = $this->createMock(StatementInterface::class);
        $convStmt->method('fetchAssoc')->willReturn($convRow);

        $this->db->method('query')->willReturnOnConsecutiveCalls($convStmt, $msgStmt);

        $result = $this->memory->get('conv-03', 'default');

        $this->assertArrayHasKey('tool_name', $result['history'][0]);
        $this->assertSame('drupal_config', $result['history'][0]['tool_name']);
        $this->assertSame(['config_name' => 'system.site'], $result['history'][0]['tool_input']);
    }

    public function test_get_returns_null_on_db_exception(): void
    {
        $this->db->method('query')->willThrowException(new \RuntimeException('db error'));

        $result = $this->memory->get('id', 'default');

        $this->assertNull($result);
    }

    public function test_set_inserts_new_conversation(): void
    {
        $existsStmt = $this->buildQueryStmt(false, false);
        $this->db->method('query')->willReturn($existsStmt);

        $insert = $this->buildInsertMock();

        $this->db->expects($this->atLeastOnce())->method('insert')->willReturn($insert);
        $this->db->expects($this->never())->method('delete');

        $this->memory->set('new-id', [
            'title' => 'New Chat',
            'history' => [],
            'metadata' => ['source' => 'test'],
        ], 'default');
    }

    public function test_set_rolls_back_on_mid_write_failure(): void
    {
        $existsStmt = $this->buildQueryStmt(false, false);
        $this->db->method('query')->willReturn($existsStmt);
        $this->db->method('insert')->willThrowException(new \RuntimeException('mid-write failure'));

        $this->memory->set('conv-fail', [
            'title' => 'X',
            'history' => [['role' => 'user', 'content' => 'hi']],
        ], 'default');

        $this->assertTrue($this->transaction->rolledBack, 'set() must roll back the transaction on a mid-write failure.');
    }

    public function test_set_updates_existing_conversation(): void
    {
        $existsStmt = $this->buildQueryStmt(['user_id' => 7], '1');
        $titleStmt = $this->buildQueryStmt(false, null);
        $idsStmt = $this->buildQueryStmt(false, false);

        $this->db->expects($this->exactly(3))
            ->method('query')
            ->willReturnOnConsecutiveCalls($existsStmt, $titleStmt, $idsStmt);

        $update = $this->buildUpdateMock();

        $this->db->expects($this->once())->method('update')->willReturn($update);
        $this->db->expects($this->never())->method('delete');
        $this->db->expects($this->never())->method('insert');

        $this->memory->set('existing-id', [
            'title' => 'Updated Chat',
            'history' => [],
        ], 'default');
    }

    public function test_set_skips_title_update_when_title_already_set(): void
    {
        $existsStmt = $this->buildQueryStmt(['user_id' => 7], '1');
        $titleStmt = $this->buildQueryStmt(false, 'Existing Title');
        $idsStmt = $this->buildQueryStmt(false, false);

        $this->db->expects($this->exactly(3))
            ->method('query')
            ->willReturnOnConsecutiveCalls($existsStmt, $titleStmt, $idsStmt);

        $update = $this->createMock(Update::class);
        $capturedFields = null;
        $update->method('fields')->willReturnCallback(function (array $fields) use ($update, &$capturedFields) {
            $capturedFields = $fields;

            return $update;
        });
        $update->method('condition')->willReturnSelf();
        $update->method('execute')->willReturn(1);

        $delete = $this->buildDeleteMock();
        $this->db->method('update')->willReturn($update);
        $this->db->method('delete')->willReturn($delete);

        $this->memory->set('existing-id', ['title' => 'New Title', 'history' => []], 'default');

        $this->assertArrayNotHasKey('title', $capturedFields ?? []);
    }

    public function test_set_ignores_non_array_value(): void
    {
        $this->db->expects($this->never())->method('query');
        $this->db->expects($this->never())->method('insert');
        $this->db->expects($this->never())->method('update');

        $this->memory->set('id', 'not-an-array', 'default');
    }

    public function test_set_inserts_user_and_assistant_messages(): void
    {
        $existsStmt = $this->buildQueryStmt(false, false);
        $this->db->method('query')->willReturn($existsStmt);

        $insertCalls = 0;
        $insert = $this->createMock(Insert::class);
        $insert->method('fields')->willReturnSelf();
        $insert->method('execute')->willReturnCallback(static function () use (&$insertCalls): string {
            $insertCalls++;

            return (string) $insertCalls;
        });

        $delete = $this->buildDeleteMock();
        $this->db->method('insert')->willReturn($insert);
        $this->db->method('delete')->willReturn($delete);

        $this->memory->set('conv-id', [
            'title' => 'Multi msg',
            'history' => [
                ['role' => 'user',      'content' => 'Hello'],
                ['role' => 'assistant', 'content' => 'Hi'],
            ],
        ], 'default');

        $this->assertSame(3, $insertCalls);
    }

    public function test_set_inserts_tool_batch_as_single_row(): void
    {
        $existsStmt = $this->buildQueryStmt(false, false);
        $this->db->method('query')->willReturn($existsStmt);

        $insertCalls = 0;
        $insert = $this->createMock(Insert::class);
        $insert->method('fields')->willReturnSelf();
        $insert->method('execute')->willReturnCallback(static function () use (&$insertCalls): string {
            $insertCalls++;

            return (string) $insertCalls;
        });

        $delete = $this->buildDeleteMock();
        $this->db->method('insert')->willReturn($insert);
        $this->db->method('delete')->willReturn($delete);

        $this->memory->set('conv-id', [
            'title' => 'Batch',
            'history' => [
                [
                    'role' => 'tool_batch',
                    'batch_calls' => [['tool_name' => 'drupal_config', 'tool_input' => ['config_name' => 'system.site']]],
                    'batch_results' => ['result'],
                ],
            ],
        ], 'default');

        $this->assertSame(2, $insertCalls);
    }

    public function test_set_skips_empty_tool_batch(): void
    {
        $existsStmt = $this->buildQueryStmt(false, false);
        $this->db->method('query')->willReturn($existsStmt);

        $insertCalls = 0;
        $insert = $this->createMock(Insert::class);
        $insert->method('fields')->willReturnSelf();
        $insert->method('execute')->willReturnCallback(static function () use (&$insertCalls): string {
            $insertCalls++;

            return (string) $insertCalls;
        });

        $delete = $this->buildDeleteMock();
        $this->db->method('insert')->willReturn($insert);
        $this->db->method('delete')->willReturn($delete);

        $this->memory->set('conv-id', [
            'title' => 'Empty batch',
            'history' => [
                ['role' => 'tool_batch', 'batch_calls' => [], 'batch_results' => []],
            ],
        ], 'default');

        $this->assertSame(1, $insertCalls);
    }

    public function test_set_skips_unknown_roles(): void
    {
        $existsStmt = $this->buildQueryStmt(false, false);
        $this->db->method('query')->willReturn($existsStmt);

        $insertCalls = 0;
        $insert = $this->createMock(Insert::class);
        $insert->method('fields')->willReturnSelf();
        $insert->method('execute')->willReturnCallback(static function () use (&$insertCalls): string {
            $insertCalls++;

            return (string) $insertCalls;
        });

        $delete = $this->buildDeleteMock();
        $this->db->method('insert')->willReturn($insert);
        $this->db->method('delete')->willReturn($delete);

        $this->memory->set('conv-id', [
            'title' => 'Skip system',
            'history' => [
                ['role' => 'system', 'content' => 'You are a bot'],
            ],
        ], 'default');

        $this->assertSame(1, $insertCalls);
    }

    public function test_set_extracts_title_from_first_user_message_when_no_title(): void
    {
        $existsStmt = $this->buildQueryStmt(false, false);

        $allInsertedFields = [];
        $insert = $this->createMock(Insert::class);
        $insert->method('fields')->willReturnCallback(function (array $fields) use ($insert, &$allInsertedFields): Insert {
            $allInsertedFields[] = $fields;

            return $insert;
        });
        $insert->method('execute')->willReturn('1');

        $delete = $this->buildDeleteMock();
        $this->db->method('query')->willReturn($existsStmt);
        $this->db->method('insert')->willReturn($insert);
        $this->db->method('delete')->willReturn($delete);

        $this->memory->set('conv-id', [
            'history' => [
                ['role' => 'user', 'content' => 'Tell me about Drupal'],
            ],
        ], 'default');

        $convFields = null;
        foreach ($allInsertedFields as $fields) {
            if (isset($fields['namespace']) && ! isset($fields['conversation_id'])) {
                $convFields = $fields;
                break;
            }
        }

        $this->assertNotNull($convFields, 'Conversation INSERT was not found');
        $this->assertSame('Tell me about Drupal', $convFields['title']);
    }

    public function test_forget_deletes_messages_and_conversation(): void
    {
        $ownerStmt = $this->buildQueryStmt(['user_id' => 7]);
        $this->db->expects($this->once())->method('query')->willReturn($ownerStmt);

        $delete = $this->buildDeleteMock();

        $this->db->expects($this->exactly(2))
            ->method('delete')
            ->willReturn($delete);

        $this->memory->forget('conv-id', 'default');
    }

    public function test_forget_denies_another_users_conversation(): void
    {
        $ownerStmt = $this->buildQueryStmt(['user_id' => 99]);
        $this->db->expects($this->once())->method('query')->willReturn($ownerStmt);
        $this->db->expects($this->never())->method('delete');

        $this->expectException(ConversationAccessDeniedException::class);

        $this->memory->forget('conv-id', 'default');
    }

    public function test_get_denies_another_users_conversation(): void
    {
        $row = [
            'id' => 'conv-99',
            'namespace' => 'default',
            'user_id' => 99,
            'title' => 'Not yours',
            'metadata' => '[]',
            'created_at' => 1700000000,
            'updated_at' => 1700000000,
        ];
        $this->db->method('query')->willReturn($this->buildQueryStmt($row));

        $this->expectException(ConversationAccessDeniedException::class);

        $this->memory->get('conv-99', 'default');
    }

    public function test_set_refuses_to_write_another_users_conversation(): void
    {
        $this->db->method('query')->willReturn($this->buildQueryStmt(['user_id' => 99]));
        $this->db->expects($this->never())->method('update');
        $this->db->expects($this->never())->method('insert');

        $this->memory->set('conv-99', ['history' => []], 'conversations');

        self::assertTrue($this->transaction->rolledBack, 'a cross-user write must roll back and persist nothing');
    }

    public function test_manage_all_reads_another_users_conversation(): void
    {
        $config = $this->createMock(ImmutableConfig::class);
        $config->method('get')->with('store_messages')->willReturn(true);
        $configFactory = $this->createMock(ConfigFactoryInterface::class);
        $configFactory->method('get')->with('phpclaw.settings')->willReturn($config);

        $memory = new DrupalDbConversationMemory($this->db, $configFactory, null, 7, true);

        $row = [
            'id' => 'conv-99',
            'namespace' => 'default',
            'user_id' => 99,
            'title' => 'Someone else',
            'metadata' => '[]',
            'created_at' => 1700000000,
            'updated_at' => 1700000000,
        ];
        $convStmt = $this->createMock(StatementInterface::class);
        $convStmt->method('fetchAssoc')->willReturn($row);

        $messageStmt = $this->createMock(StatementInterface::class);
        $messageStmt->method('fetchAssoc')->willReturn(false);

        $this->db->method('query')->willReturnOnConsecutiveCalls($convStmt, $messageStmt);

        $result = $memory->get('conv-99', 'default');

        self::assertIsArray($result);
        self::assertSame('Someone else', $result['title']);
    }

    public function test_manage_all_forgets_another_users_conversation(): void
    {
        $config = $this->createMock(ImmutableConfig::class);
        $config->method('get')->with('store_messages')->willReturn(true);
        $configFactory = $this->createMock(ConfigFactoryInterface::class);
        $configFactory->method('get')->with('phpclaw.settings')->willReturn($config);

        $memory = new DrupalDbConversationMemory($this->db, $configFactory, null, 7, true);

        $this->db->method('query')->willReturn($this->buildQueryStmt(['user_id' => 99]));

        $delete = $this->createMock(Delete::class);
        $delete->method('condition')->willReturnSelf();
        $delete->method('execute')->willReturn(1);
        $this->db->expects($this->atLeastOnce())->method('delete')->willReturn($delete);

        $memory->forget('conv-99', 'default');
    }

    public function test_forget_swallows_db_exceptions(): void
    {
        $this->db->method('delete')->willThrowException(new \RuntimeException('gone'));

        $this->memory->forget('id', 'default');

        self::assertNull($this->memory->get('id', 'default'), 'a failed delete must leave nothing readable');
    }

    public function test_flush_deletes_all_conversations_in_namespace(): void
    {
        $ids = ['id-1', 'id-2'];
        $idStmt = $this->createMock(StatementInterface::class);
        $idStmt->method('fetchCol')->willReturn($ids);

        $this->db->expects($this->once())->method('query')->willReturn($idStmt);

        $delete = $this->buildDeleteMock();
        $this->db->expects($this->exactly(2))->method('delete')->willReturn($delete);

        $this->memory->flush('default');
    }

    public function test_flush_skips_message_delete_when_no_conversations(): void
    {
        $idStmt = $this->createMock(StatementInterface::class);
        $idStmt->method('fetchCol')->willReturn([]);

        $this->db->expects($this->once())->method('query')->willReturn($idStmt);

        $this->db->expects($this->never())->method('delete');

        $this->memory->flush('default');
    }

    public function test_flush_swallows_db_exceptions(): void
    {
        $this->db->method('query')->willThrowException(new \RuntimeException('gone'));

        $this->memory->flush('default');

        self::assertSame([], $this->memory->all('default'), 'a failed flush must leave nothing readable');
    }

    public function test_all_returns_keyed_array_of_conversations(): void
    {
        $rows = [
            ['id' => 'a1', 'title' => 'Chat A', 'metadata' => null, 'created_at' => '2024-01-01', 'updated_at' => '2024-01-02'],
            ['id' => 'b2', 'title' => 'Chat B', 'metadata' => null, 'created_at' => '2024-01-03', 'updated_at' => '2024-01-04'],
        ];

        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchAssoc')->willReturnOnConsecutiveCalls(...array_merge($rows, [false]));

        $this->db->method('query')->willReturn($stmt);

        $result = $this->memory->all('default');

        $this->assertCount(2, $result);
        $this->assertArrayHasKey('a1', $result);
        $this->assertArrayHasKey('b2', $result);
        $this->assertSame('Chat A', $result['a1']['title']);
    }

    public function test_all_returns_empty_array_when_no_conversations(): void
    {
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchAssoc')->willReturn(false);

        $this->db->method('query')->willReturn($stmt);

        $result = $this->memory->all('default');

        $this->assertSame([], $result);
    }

    public function test_all_returns_empty_array_on_db_exception(): void
    {
        $this->db->method('query')->willThrowException(new \RuntimeException('gone'));

        $result = $this->memory->all('default');

        $this->assertSame([], $result);
    }

    public function test_has_returns_true_when_conversation_exists(): void
    {
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchField')->willReturn('1');

        $this->db->method('query')->willReturn($stmt);

        $this->assertTrue($this->memory->has('cid', 'default'));
    }

    public function test_has_returns_false_when_conversation_missing(): void
    {
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchField')->willReturn(false);

        $this->db->method('query')->willReturn($stmt);

        $this->assertFalse($this->memory->has('nonexistent', 'default'));
    }

    public function test_has_returns_false_on_db_exception(): void
    {
        $this->db->method('query')->willThrowException(new \RuntimeException('gone'));

        $this->assertFalse($this->memory->has('cid', 'default'));
    }

    public function test_set_handles_db_exception_gracefully(): void
    {
        $this->db->method('query')->willThrowException(new \RuntimeException('db error'));

        $this->memory->set('id', ['history' => [], 'title' => 'x'], 'default');

        self::assertNull($this->memory->get('conv-x', 'conversations'), 'a failed write must store nothing');
    }

    public function test_set_truncates_long_title_from_user_message(): void
    {
        $existsStmt = $this->buildQueryStmt(false, false);

        $allInsertedFields = [];
        $insert = $this->createMock(Insert::class);
        $insert->method('fields')->willReturnCallback(function (array $fields) use ($insert, &$allInsertedFields): Insert {
            $allInsertedFields[] = $fields;

            return $insert;
        });
        $insert->method('execute')->willReturn('1');

        $delete = $this->buildDeleteMock();
        $this->db->method('query')->willReturn($existsStmt);
        $this->db->method('insert')->willReturn($insert);
        $this->db->method('delete')->willReturn($delete);

        $longContent = str_repeat('A', 120);
        $this->memory->set('conv-long', [
            'history' => [
                ['role' => 'user', 'content' => $longContent],
            ],
        ], 'default');

        $convFields = null;
        foreach ($allInsertedFields as $fields) {
            if (isset($fields['namespace']) && ! isset($fields['conversation_id'])) {
                $convFields = $fields;
                break;
            }
        }

        $this->assertNotNull($convFields, 'Conversation INSERT was not found');
        $this->assertLessThanOrEqual(80, mb_strlen((string) ($convFields['title'] ?? '')));
    }

    public function test_set_preserves_explicit_created_at_when_provided(): void
    {
        $existsStmt = $this->buildQueryStmt(false, false);

        $allInsertedFields = [];
        $insert = $this->createMock(Insert::class);
        $insert->method('fields')->willReturnCallback(function (array $fields) use ($insert, &$allInsertedFields): Insert {
            $allInsertedFields[] = $fields;

            return $insert;
        });
        $insert->method('execute')->willReturn('1');

        $delete = $this->buildDeleteMock();
        $this->db->method('query')->willReturn($existsStmt);
        $this->db->method('insert')->willReturn($insert);
        $this->db->method('delete')->willReturn($delete);

        $this->memory->set('conv-ts', [
            'history' => [],
            'title' => 'Timestamped',
            'created_at' => '2024-06-01 10:00:00',
        ], 'default');

        $convFields = null;
        foreach ($allInsertedFields as $fields) {
            if (isset($fields['namespace']) && ! isset($fields['conversation_id'])) {
                $convFields = $fields;
                break;
            }
        }

        $this->assertNotNull($convFields);
        $this->assertIsInt($convFields['created_at']);
        $this->assertSame((int) strtotime('2024-06-01 10:00:00'), $convFields['created_at']);
    }

    public function test_all_sorts_by_updated_at_descending(): void
    {
        $rows = [
            ['id' => 'z1', 'title' => 'Latest', 'metadata' => null, 'created_at' => '2024-01-03', 'updated_at' => '2024-01-04'],
            ['id' => 'a1', 'title' => 'Oldest', 'metadata' => null, 'created_at' => '2024-01-01', 'updated_at' => '2024-01-02'],
        ];

        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchAssoc')->willReturnOnConsecutiveCalls(...array_merge($rows, [false]));

        $this->db->method('query')->willReturn($stmt);

        $result = $this->memory->all('default');

        $this->assertCount(2, $result);
        $ids = array_keys($result);
        $this->assertSame('z1', $ids[0], 'Most recently updated should be first');
        $this->assertSame('a1', $ids[1]);
    }

    public function test_flush_with_namespace_deletes_all_conversations_and_messages(): void
    {
        $ids = ['conv-a', 'conv-b', 'conv-c'];
        $idStmt = $this->createMock(StatementInterface::class);
        $idStmt->method('fetchCol')->willReturn($ids);

        $this->db->expects($this->once())->method('query')->willReturn($idStmt);

        $delete = $this->buildDeleteMock();
        $this->db->expects($this->exactly(2))->method('delete')->willReturn($delete);

        $this->memory->flush('custom-namespace');
    }

    public function test_set_returns_null_title_when_no_user_message_with_content(): void
    {
        $this->setupConversationNotFound();
        $inserted = [];
        $insert = $this->createMock(Insert::class);
        $insert->method('fields')->willReturnCallback(
            function (array $fields) use (&$inserted, $insert): Insert {
                $inserted[] = $fields;

                return $insert;
            },
        );
        $insert->method('execute')->willReturn(1);
        $this->db->method('insert')->willReturn($insert);
        $update = $this->createMock(Update::class);
        $update->method('fields')->willReturnSelf();
        $update->method('condition')->willReturnSelf();
        $update->method('execute')->willReturn(1);
        $this->db->method('update')->willReturn($update);

        $this->memory->set('conv-no-user', [
            'history' => [
                ['role' => 'assistant', 'content' => 'Hello!'],
            ],
        ], 'conversations');

        $conversationRow = null;
        foreach ($inserted as $fields) {
            if (array_key_exists('title', $fields)) {
                $conversationRow = $fields;
                break;
            }
        }

        self::assertNotNull($conversationRow, 'the conversation row must be inserted');
        self::assertNull($conversationRow['title'], 'no usable user message means the title stays null');
    }

    public function test_set_skips_user_message_with_empty_content(): void
    {
        $this->setupConversationNotFound();
        $inserted = [];
        $insert = $this->createMock(Insert::class);
        $insert->method('fields')->willReturnCallback(
            function (array $fields) use (&$inserted, $insert): Insert {
                $inserted[] = $fields;

                return $insert;
            },
        );
        $insert->method('execute')->willReturn(1);
        $this->db->method('insert')->willReturn($insert);
        $update = $this->createMock(Update::class);
        $update->method('fields')->willReturnSelf();
        $update->method('condition')->willReturnSelf();
        $update->method('execute')->willReturn(1);
        $this->db->method('update')->willReturn($update);

        $this->memory->set('conv-empty-user', [
            'history' => [
                ['role' => 'user', 'content' => '   '],
            ],
        ], 'conversations');

        $conversationRow = null;
        foreach ($inserted as $fields) {
            if (array_key_exists('title', $fields)) {
                $conversationRow = $fields;
                break;
            }
        }

        self::assertNotNull($conversationRow, 'the conversation row must be inserted');
        self::assertNull($conversationRow['title'], 'a blank user message must not become the title');
    }

    private function setupConversationNotFound(): void
    {
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchField')->willReturn(false);
        $stmt->method('fetchAssoc')->willReturn(false);
        $this->db->method('query')->willReturn($stmt);
    }
}

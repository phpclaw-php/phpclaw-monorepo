<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Memory;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PhpClaw\Magento\Exception\ConversationAccessDeniedException;
use PhpClaw\Magento\Memory\ConversationMemory;
use PhpClaw\Magento\Model\Config;
use PhpClaw\Magento\Model\IdentityResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ConversationMemoryTest extends TestCase
{
    private AdapterInterface&MockObject $connection;

    private ResourceConnection&MockObject $resourceConnection;

    private Config&MockObject $config;

    private IdentityResolver&MockObject $identity;

    private ConversationMemory $memory;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);

        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        $this->resourceConnection->method('getTableName')
            ->willReturnCallback(static fn (string $name) => $name);

        $this->config = $this->createMock(Config::class);
        $this->config->method('isStoreMessages')->willReturn(true);

        $this->identity = $this->createMock(IdentityResolver::class);
        $this->identity->method('actingUserId')->willReturn(0);
        $this->identity->method('manageAll')->willReturn(false);

        $this->memory = new ConversationMemory($this->resourceConnection, $this->config, $this->identity);
    }

    private function scopedMemory(int $actingUserId, bool $manageAll): ConversationMemory
    {
        $identity = $this->createMock(IdentityResolver::class);
        $identity->method('actingUserId')->willReturn($actingUserId);
        $identity->method('manageAll')->willReturn($manageAll);

        return new ConversationMemory($this->resourceConnection, $this->config, $identity);
    }

    public function test_get_returns_null_when_conversation_not_found(): void
    {
        $this->connection->method('fetchRow')->willReturn(false);

        self::assertNull($this->memory->get('nonexistent', 'default'));
    }

    public function test_get_returns_messages_when_conversation_exists(): void
    {
        $this->connection->method('fetchRow')->willReturn(['id' => '01abc', 'created_at' => '2026-01-01 00:00:00']);
        $this->connection->method('fetchAll')->willReturn([
            ['role' => 'user', 'content' => 'Hello', 'tool_name' => null, 'tool_input' => null],
            ['role' => 'assistant', 'content' => 'Hi!', 'tool_name' => null, 'tool_input' => null],
        ]);

        $result = $this->memory->get('01abc', 'default');

        self::assertIsArray($result);
        self::assertArrayHasKey('history', $result);
        self::assertCount(2, $result['history']);
        self::assertSame('user', $result['history'][0]['role']);
        self::assertSame('Hello', $result['history'][0]['content']);
        self::assertSame('assistant', $result['history'][1]['role']);
    }

    public function test_get_includes_tool_name_when_present(): void
    {
        $this->connection->method('fetchRow')->willReturn(['id' => '01abc', 'created_at' => '2026-01-01 00:00:00']);
        $this->connection->method('fetchAll')->willReturn([
            ['role' => 'tool', 'content' => null, 'tool_name' => 'ShellTool', 'tool_input' => '{"cmd":"ls"}'],
        ]);

        $result = $this->memory->get('01abc');

        self::assertSame('ShellTool', $result['history'][0]['tool_name']);
        self::assertSame(['cmd' => 'ls'], $result['history'][0]['tool_input']);
    }

    public function test_get_omits_tool_input_when_null(): void
    {
        $this->connection->method('fetchRow')->willReturn(['id' => '01abc', 'created_at' => '2026-01-01 00:00:00']);
        $this->connection->method('fetchAll')->willReturn([
            ['role' => 'user', 'content' => 'Hi', 'tool_name' => null, 'tool_input' => null],
        ]);

        $result = $this->memory->get('01abc');

        self::assertNull($result['history'][0]['tool_name']);
        self::assertArrayNotHasKey('tool_input', $result['history'][0]);
    }

    public function test_set_upserts_conversation_row(): void
    {
        $this->connection->expects(self::atLeastOnce())->method('insertOnDuplicate');
        $this->connection->expects(self::once())->method('delete');

        $this->memory->set('conv01', ['id' => 'conv01', 'history' => [['role' => 'user', 'content' => 'Hello']]]);
    }

    public function test_set_extracts_title_from_first_user_message(): void
    {
        $capturedConvData = [];
        $this->connection->method('insertOnDuplicate')
            ->willReturnCallback(function (string $table, array $data) use (&$capturedConvData): void {
                if ($table === 'phpclaw_conversations') {
                    $capturedConvData = $data;
                }
            });
        $this->connection->method('delete');
        $this->connection->method('insert');

        $this->memory->set('conv01', [
            'id' => 'conv01',
            'history' => [['role' => 'user', 'content' => 'What is the weather today?']],
        ]);

        self::assertSame('What is the weather today?', $capturedConvData['title']);
    }

    public function test_set_truncates_title_to_80_chars(): void
    {
        $capturedConvData = [];
        $this->connection->method('insertOnDuplicate')
            ->willReturnCallback(function (string $table, array $data) use (&$capturedConvData): void {
                if ($table === 'phpclaw_conversations') {
                    $capturedConvData = $data;
                }
            });
        $this->connection->method('delete');
        $this->connection->method('insert');

        $longContent = str_repeat('A', 120);
        $this->memory->set('conv01', [
            'id' => 'conv01',
            'history' => [['role' => 'user', 'content' => $longContent]],
        ]);

        self::assertLessThanOrEqual(80, mb_strlen($capturedConvData['title']));
    }

    public function test_set_inserts_message_per_item(): void
    {
        $insertCount = 0;
        $this->connection->method('insertOnDuplicate');
        $this->connection->method('insert')->willReturnCallback(function () use (&$insertCount): int {
            $insertCount++;

            return 1;
        });
        $this->connection->method('delete');

        $this->memory->set('conv01', [
            'id' => 'conv01',
            'history' => [
                ['role' => 'user',      'content' => 'Hello'],
                ['role' => 'assistant', 'content' => 'Hi'],
            ],
        ]);

        self::assertSame(2, $insertCount);
    }

    public function test_set_deletes_old_messages_before_insert(): void
    {
        $deleteTable = null;
        $this->connection->method('delete')
            ->willReturnCallback(function (string $table) use (&$deleteTable): void {
                $deleteTable = $table;
            });
        $this->connection->method('insertOnDuplicate');
        $this->connection->method('insert');

        $this->memory->set('conv01', ['id' => 'conv01', 'history' => [['role' => 'user', 'content' => 'Hi']]]);

        self::assertSame('phpclaw_messages', $deleteTable);
    }

    public function test_set_generates_ulid_for_each_message(): void
    {
        $capturedIds = [];
        $this->connection->method('insertOnDuplicate');
        $this->connection->method('insert')->willReturnCallback(function (string $table, array $data) use (&$capturedIds): int {
            if ($table === 'phpclaw_messages') {
                $capturedIds[] = $data['id'];
            }

            return 1;
        });
        $this->connection->method('delete');

        $this->memory->set('conv01', [
            'id' => 'conv01',
            'history' => [
                ['role' => 'user', 'content' => 'A'],
                ['role' => 'assistant', 'content' => 'B'],
            ],
        ]);

        self::assertCount(2, $capturedIds);
        self::assertSame(26, strlen($capturedIds[0]));
        self::assertNotSame($capturedIds[0], $capturedIds[1]);
    }

    public function test_forget_deletes_conversation_by_id_and_namespace(): void
    {
        $capturedWhere = [];
        $this->connection->expects(self::once())
            ->method('delete')
            ->willReturnCallback(function (string $table, array $where) use (&$capturedWhere): void {
                $capturedWhere = $where;
            });

        $this->memory->forget('conv01', 'default');

        self::assertArrayHasKey('id = ?', $capturedWhere);
        self::assertSame('conv01', $capturedWhere['id = ?']);
        self::assertArrayHasKey('namespace = ?', $capturedWhere);
        self::assertSame('default', $capturedWhere['namespace = ?']);
    }

    public function test_flush_deletes_all_conversations_in_namespace(): void
    {
        $capturedWhere = [];
        $this->connection->expects(self::once())
            ->method('delete')
            ->willReturnCallback(function (string $table, array $where) use (&$capturedWhere): void {
                $capturedWhere = $where;
            });

        $this->scopedMemory(7, false)->flush('my_ns');

        self::assertArrayHasKey('namespace = ?', $capturedWhere);
        self::assertSame('my_ns', $capturedWhere['namespace = ?']);
        self::assertSame(7, $capturedWhere['admin_user_id = ?']);
        self::assertCount(2, $capturedWhere);
    }

    public function test_flush_is_unscoped_for_the_manage_all_tier(): void
    {
        $capturedWhere = [];
        $this->connection->expects(self::once())
            ->method('delete')
            ->willReturnCallback(function (string $table, array $where) use (&$capturedWhere): void {
                $capturedWhere = $where;
            });

        $this->scopedMemory(1, true)->flush('my_ns');

        self::assertSame(['namespace = ?' => 'my_ns'], $capturedWhere);
    }

    public function test_all_returns_empty_when_no_conversations(): void
    {
        $this->connection->method('fetchAll')->willReturn([]);

        self::assertSame([], $this->memory->all());
    }

    public function test_all_returns_map_of_id_to_messages(): void
    {
        $callCount = 0;
        $this->connection->method('fetchAll')
            ->willReturnCallback(function () use (&$callCount): array {
                $callCount++;
                if ($callCount === 1) {
                    return [['id' => 'conv01', 'created_at' => '2026-01-01 00:00:00'], ['id' => 'conv02', 'created_at' => '2026-01-01 00:00:00']];
                }

                return [
                    ['role' => 'user', 'content' => 'Hi', 'tool_name' => null, 'tool_input' => null],
                ];
            });
        $this->connection->method('fetchRow')->willReturn(['id' => 'found', 'created_at' => '2026-01-01 00:00:00']);

        $result = $this->memory->all('default');

        self::assertArrayHasKey('conv01', $result);
        self::assertArrayHasKey('conv02', $result);
    }

    public function test_has_returns_true_when_conversation_exists(): void
    {
        $this->connection->method('fetchRow')->willReturn(['id' => 'conv01', 'created_at' => '2026-01-01 00:00:00']);
        $this->connection->method('fetchAll')->willReturn([]);

        self::assertTrue($this->memory->has('conv01'));
    }

    public function test_has_returns_false_when_not_found(): void
    {
        $this->connection->method('fetchRow')->willReturn(false);

        self::assertFalse($this->memory->has('missing'));
    }

    public function test_list_conversations_returns_empty_array_when_no_rows(): void
    {
        $this->connection->method('fetchAll')->willReturn([]);

        self::assertSame([], $this->memory->listConversations('default'));
    }

    public function test_list_conversations_returns_conversation_with_messages(): void
    {
        $callCount = 0;
        $this->connection->method('fetchAll')
            ->willReturnCallback(function () use (&$callCount): array {
                $callCount++;
                if ($callCount === 1) {
                    return [['id' => 'conv01', 'title' => 'Test chat', 'created_at' => '2026-01-01 12:00:00']];
                }

                return [
                    ['role' => 'user',      'content' => 'Hello',      'tool_name' => null,  'tool_input' => null],
                    ['role' => 'assistant', 'content' => 'Hi there!',  'tool_name' => null,  'tool_input' => null],
                ];
            });

        $result = $this->memory->listConversations('default');

        self::assertCount(1, $result);
        self::assertSame('conv01', $result[0]['id']);
        self::assertSame('Test chat', $result[0]['title']);
        self::assertCount(2, $result[0]['messages']);
        self::assertSame('user', $result[0]['messages'][0]['role']);
        self::assertSame('Hello', $result[0]['messages'][0]['content']);
    }

    public function test_list_conversations_enriches_tool_rows_with_tool_name_and_input(): void
    {
        $callCount = 0;
        $this->connection->method('fetchAll')
            ->willReturnCallback(function () use (&$callCount): array {
                $callCount++;
                if ($callCount === 1) {
                    return [['id' => 'conv02', 'title' => null, 'created_at' => '2026-01-02 00:00:00']];
                }

                return [
                    [
                        'role' => 'tool',
                        'content' => 'result text',
                        'tool_name' => 'db_query',
                        'tool_input' => '{"query":"SELECT 1"}',
                    ],
                ];
            });

        $result = $this->memory->listConversations('default');

        $msg = $result[0]['messages'][0];
        self::assertSame('tool', $msg['role']);
        self::assertSame('db_query', $msg['tool_name']);
        self::assertSame(['query' => 'SELECT 1'], $msg['tool_input']);
    }

    public function test_list_conversations_handles_non_array_tool_input_gracefully(): void
    {
        $callCount = 0;
        $this->connection->method('fetchAll')
            ->willReturnCallback(function () use (&$callCount): array {
                $callCount++;
                if ($callCount === 1) {
                    return [['id' => 'conv03', 'title' => null, 'created_at' => '2026-01-03 00:00:00']];
                }

                return [
                    [
                        'role' => 'tool',
                        'content' => 'output',
                        'tool_name' => 'read_log',
                        'tool_input' => 'not-valid-json',
                    ],
                ];
            });

        $result = $this->memory->listConversations('default');

        $msg = $result[0]['messages'][0];
        self::assertSame('tool', $msg['role']);
        self::assertSame([], $msg['tool_input']);
    }

    public function test_list_conversations_null_content_becomes_empty_string(): void
    {
        $callCount = 0;
        $this->connection->method('fetchAll')
            ->willReturnCallback(function () use (&$callCount): array {
                $callCount++;
                if ($callCount === 1) {
                    return [['id' => 'conv04', 'title' => 'Q', 'created_at' => '2026-01-04 00:00:00']];
                }

                return [
                    ['role' => 'assistant', 'content' => null, 'tool_name' => null, 'tool_input' => null],
                ];
            });

        $result = $this->memory->listConversations('default');

        self::assertSame('', $result[0]['messages'][0]['content']);
    }

    public function test_get_returns_full_structure_with_id_created_at_metadata_history(): void
    {
        $this->connection->method('fetchRow')
            ->willReturn(['id' => 'conv99', 'created_at' => '2026-05-01 08:00:00']);
        $this->connection->method('fetchAll')->willReturn([
            ['role' => 'user', 'content' => 'ping', 'tool_name' => null, 'tool_input' => null],
        ]);

        $result = $this->memory->get('conv99', 'default');

        self::assertIsArray($result);
        self::assertSame('conv99', $result['id']);
        self::assertSame('2026-05-01 08:00:00', $result['created_at']);
        self::assertSame([], $result['metadata']);
        self::assertCount(1, $result['history']);
    }

    public function test_forget_scopes_delete_to_given_namespace(): void
    {
        $capturedArgs = [];
        $this->connection->expects(self::once())
            ->method('delete')
            ->willReturnCallback(function (string $table, array $where) use (&$capturedArgs): void {
                $capturedArgs = ['table' => $table, 'where' => $where];
            });

        $this->memory->forget('myid', 'mynamespace');

        self::assertSame('phpclaw_conversations', $capturedArgs['table']);
        self::assertSame('myid', $capturedArgs['where']['id = ?']);
        self::assertSame('mynamespace', $capturedArgs['where']['namespace = ?']);
    }

    public function test_flush_removes_all_rows_in_namespace(): void
    {
        $capturedArgs = [];
        $this->connection->expects(self::once())
            ->method('delete')
            ->willReturnCallback(function (string $table, array $where) use (&$capturedArgs): void {
                $capturedArgs = ['table' => $table, 'where' => $where];
            });

        $this->scopedMemory(4, false)->flush('target_ns');

        self::assertSame('phpclaw_conversations', $capturedArgs['table']);
        self::assertSame('target_ns', $capturedArgs['where']['namespace = ?']);
        self::assertSame(4, $capturedArgs['where']['admin_user_id = ?']);
        self::assertCount(2, $capturedArgs['where']);
    }

    public function test_has_returns_false_for_non_existent_id(): void
    {
        $this->connection->method('fetchRow')->willReturn(false);

        self::assertFalse($this->memory->has('ghost-id', 'default'));
    }

    public function test_set_commits_when_all_inserts_succeed(): void
    {
        $this->connection->expects(self::once())->method('beginTransaction');
        $this->connection->expects(self::once())->method('commit');
        $this->connection->expects(self::never())->method('rollBack');
        $this->connection->method('insertOnDuplicate');
        $this->connection->method('delete');
        $this->connection->method('insert')->willReturn(1);

        $this->memory->set('conv01', [
            'id' => 'conv01',
            'history' => [
                ['role' => 'user',      'content' => 'Hello'],
                ['role' => 'assistant', 'content' => 'Hi'],
                ['role' => 'user',      'content' => 'Bye'],
            ],
        ]);
    }

    public function test_set_rolls_back_when_insert_fails(): void
    {
        $this->connection->expects(self::once())->method('beginTransaction');
        $this->connection->expects(self::never())->method('commit');
        $this->connection->expects(self::once())->method('rollBack');
        $this->connection->method('insertOnDuplicate');
        $this->connection->method('delete');
        $this->connection->method('insert')->willThrowException(new \RuntimeException('DB error'));

        $this->expectException(\RuntimeException::class);
        $this->memory->set('conv01', [
            'id' => 'conv01',
            'history' => [['role' => 'user', 'content' => 'Hello']],
        ]);
    }

    public function test_set_persists_metadata_when_provided(): void
    {
        $captured = [];
        $this->connection->method('insertOnDuplicate')
            ->willReturnCallback(function (string $table, array $data) use (&$captured): void {
                if ($table === 'phpclaw_conversations') {
                    $captured = $data;
                }
            });
        $this->connection->method('delete');
        $this->connection->method('insert');

        $this->memory->set('conv01', [
            'id' => 'conv01',
            'history' => [['role' => 'user', 'content' => 'Hi']],
            'metadata' => ['source' => 'web', 'tags' => ['a', 'b']],
        ]);

        self::assertSame(['source' => 'web', 'tags' => ['a', 'b']], json_decode($captured['metadata'], true));
    }

    public function test_get_decodes_stored_metadata(): void
    {
        $this->connection->method('fetchRow')->willReturn([
            'id' => 'conv01',
            'title' => 'T',
            'metadata' => '{"source":"web","tags":["a","b"]}',
            'created_at' => '2026-01-01 00:00:00',
        ]);
        $this->connection->method('fetchAll')->willReturn([]);

        $result = $this->memory->get('conv01', 'default');

        self::assertSame(['source' => 'web', 'tags' => ['a', 'b']], $result['metadata']);
    }

    public function test_set_stamps_the_acting_admin_on_insert(): void
    {
        $captured = [];
        $this->connection->method('insertOnDuplicate')
            ->willReturnCallback(function (string $table, array $data, array $update) use (&$captured): void {
                $captured = ['data' => $data, 'update' => $update];
            });
        $this->connection->method('fetchOne')->willReturn(false);

        $this->scopedMemory(9, false)->set('conv-1', ['id' => 'conv-1', 'history' => []], 'default');

        self::assertSame(9, $captured['data']['admin_user_id']);
        self::assertNotContains(
            'admin_user_id',
            $captured['update'],
            'Ownership is stamped on create only and must never be rewritten on update.',
        );
    }

    public function test_set_writes_zero_under_cli(): void
    {
        $captured = [];
        $this->connection->method('insertOnDuplicate')
            ->willReturnCallback(function (string $table, array $data) use (&$captured): void {
                $captured = $data;
            });
        $this->connection->method('fetchOne')->willReturn(false);

        $this->scopedMemory(0, false)->set('conv-cli', ['id' => 'conv-cli', 'history' => []], 'default');

        self::assertSame(0, $captured['admin_user_id']);
    }

    public function test_get_denies_a_conversation_owned_by_another_admin(): void
    {
        $this->connection->method('fetchRow')->willReturn([
            'id' => 'conv-x', 'admin_user_id' => 7, 'title' => 't',
            'metadata' => '', 'created_at' => '2026-01-01 00:00:00',
        ]);

        $this->expectException(ConversationAccessDeniedException::class);

        $this->scopedMemory(6, false)->get('conv-x', 'default');
    }

    public function test_get_allows_the_owner(): void
    {
        $this->connection->method('fetchRow')->willReturn([
            'id' => 'conv-x', 'admin_user_id' => 6, 'title' => 't',
            'metadata' => '', 'created_at' => '2026-01-01 00:00:00',
        ]);
        $this->connection->method('fetchAll')->willReturn([]);

        self::assertSame('conv-x', $this->scopedMemory(6, false)->get('conv-x', 'default')['id']);
    }

    public function test_get_allows_the_manage_all_tier_on_a_foreign_conversation(): void
    {
        $this->connection->method('fetchRow')->willReturn([
            'id' => 'conv-x', 'admin_user_id' => 7, 'title' => 't',
            'metadata' => '', 'created_at' => '2026-01-01 00:00:00',
        ]);
        $this->connection->method('fetchAll')->willReturn([]);

        self::assertSame('conv-x', $this->scopedMemory(1, true)->get('conv-x', 'default')['id']);
    }

    public function test_has_reports_false_instead_of_throwing_on_a_foreign_conversation(): void
    {
        $this->connection->method('fetchRow')->willReturn([
            'id' => 'conv-x', 'admin_user_id' => 7, 'title' => 't',
            'metadata' => '', 'created_at' => '2026-01-01 00:00:00',
        ]);

        self::assertFalse($this->scopedMemory(6, false)->has('conv-x', 'default'));
    }

    public function test_forget_denies_a_conversation_owned_by_another_admin(): void
    {
        $this->connection->method('fetchOne')->willReturn('7');
        $this->connection->expects(self::never())->method('delete');

        $this->expectException(ConversationAccessDeniedException::class);

        $this->scopedMemory(6, false)->forget('conv-x', 'default');
    }

    public function test_forget_allows_the_manage_all_tier_on_a_foreign_conversation(): void
    {
        $this->connection->method('fetchOne')->willReturn('7');
        $this->connection->expects(self::atLeastOnce())->method('delete');

        $this->scopedMemory(1, true)->forget('conv-x', 'default');
    }

    public function test_set_denies_writing_into_another_admins_conversation(): void
    {
        $this->connection->method('fetchOne')->willReturn('7');
        $this->connection->expects(self::never())->method('insertOnDuplicate');

        $this->expectException(ConversationAccessDeniedException::class);

        $this->scopedMemory(6, false)->set('conv-x', ['id' => 'conv-x', 'history' => []], 'default');
    }

    public function test_all_filters_by_owner_for_the_chat_tier(): void
    {
        $captured = [];
        $this->connection->method('fetchAll')
            ->willReturnCallback(function (string $sql, array $bind) use (&$captured): array {
                $captured[] = ['sql' => $sql, 'bind' => $bind];

                return [];
            });

        $this->scopedMemory(6, false)->all('default');

        self::assertStringContainsString('`admin_user_id` = ?', $captured[0]['sql']);
        self::assertSame(['default', 6], $captured[0]['bind']);
    }

    public function test_all_is_unfiltered_for_the_manage_all_tier(): void
    {
        $captured = [];
        $this->connection->method('fetchAll')
            ->willReturnCallback(function (string $sql, array $bind) use (&$captured): array {
                $captured[] = ['sql' => $sql, 'bind' => $bind];

                return [];
            });

        $this->scopedMemory(1, true)->all('default');

        self::assertStringNotContainsString('admin_user_id', $captured[0]['sql']);
        self::assertSame(['default'], $captured[0]['bind']);
    }

    public function test_list_conversations_filters_by_owner_for_the_chat_tier(): void
    {
        $captured = [];
        $this->connection->method('fetchAll')
            ->willReturnCallback(function (string $sql, array $bind) use (&$captured): array {
                $captured[] = ['sql' => $sql, 'bind' => $bind];

                return [];
            });

        $this->scopedMemory(6, false)->listConversations('default');

        self::assertStringContainsString('`admin_user_id` = ?', $captured[0]['sql']);
        self::assertSame(['default', 6], $captured[0]['bind']);
    }
}

<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tests\Unit\Memory;

use PhpClaw\Exceptions\MemoryException;
use PhpClaw\OpenCart\Exceptions\ConversationAccessDeniedException;
use PhpClaw\OpenCart\Memory\OcDbConversationMemory;
use PhpClaw\OpenCart\Tests\Helpers\OcDbTestCase;

final class OcDbConversationMemoryTest extends OcDbTestCase
{
    private OcDbConversationMemory $memory;

    private OcDbConversationMemory $adminMemory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetTables([
            "CREATE TABLE IF NOT EXISTS `{$this->prefix}phpclaw_conversations` (
                id          VARCHAR(64)      NOT NULL,
                namespace   VARCHAR(100)     NOT NULL DEFAULT 'default',
                owner_id    INT UNSIGNED     DEFAULT NULL,
                title       VARCHAR(255)     DEFAULT NULL,
                metadata    LONGTEXT         DEFAULT NULL,
                created_at  DATETIME         NOT NULL,
                updated_at  DATETIME         NOT NULL,
                PRIMARY KEY (id),
                KEY idx_namespace_created (namespace, created_at),
                KEY idx_owner (owner_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$this->prefix}phpclaw_messages` (
                id              VARCHAR(64)  NOT NULL,
                conversation_id VARCHAR(64)  NOT NULL DEFAULT '',
                role            VARCHAR(20)  NOT NULL DEFAULT '',
                content         LONGTEXT     DEFAULT NULL,
                tool_name       VARCHAR(255) DEFAULT NULL,
                tool_input      TEXT         DEFAULT NULL,
                created_at      DATETIME     NOT NULL,
                PRIMARY KEY (id),
                KEY idx_conv_created (conversation_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ]);

        $this->memory = new OcDbConversationMemory($this->db, $this->prefix, true, 1, false);
        $this->adminMemory = new OcDbConversationMemory($this->db, $this->prefix, true, 99, true);
    }

    public function test_get_returns_null_for_missing_conversation(): void
    {
        self::assertNull($this->memory->get('nonexistent', 'default'));
    }

    public function test_null_pdo_returns_null_on_get(): void
    {
        $mem = new OcDbConversationMemory(null, $this->prefix);
        self::assertNull($mem->get('any', 'default'));
    }

    public function test_all_returns_empty_when_no_conversations(): void
    {
        self::assertSame([], $this->memory->all('default'));
    }

    public function test_forget_on_missing_key_is_safe(): void
    {
        $this->memory->forget('nonexistent', 'default');

        self::assertNull($this->memory->get('nonexistent', 'default'), 'forgetting an absent key leaves it absent');
    }

    public function test_has_returns_false_for_missing_key(): void
    {
        self::assertFalse($this->memory->has('missing', 'default'));
    }

    public function test_set_and_get_round_trip(): void
    {
        $id = 'conv-test-01-aabbccddee-001';
        $this->memory->set($id, [
            'title' => 'Test Conversation',
            'history' => [
                ['role' => 'user', 'content' => 'Hello AI'],
                ['role' => 'assistant', 'content' => 'Hello human'],
            ],
        ], 'default');

        $result = $this->memory->get($id, 'default');
        self::assertIsArray($result);
        self::assertSame($id, $result['id']);
        self::assertSame('Test Conversation', $result['title']);
        self::assertCount(2, $result['history']);
    }

    public function test_set_updates_existing_conversation(): void
    {
        $id = 'conv-update-01-aabbcc-0001';
        $this->memory->set($id, ['title' => 'First Title', 'history' => []], 'default');
        $this->memory->set($id, ['title' => 'Updated Title', 'history' => []], 'default');

        $result = $this->memory->get($id, 'default');
        self::assertSame('Updated Title', $result['title'] ?? '');
    }

    public function test_all_returns_saved_conversations(): void
    {
        $this->memory->set('id-aabb-0001-ccdd-eeff', ['title' => 'Conv A', 'history' => []], 'default');
        $this->memory->set('id-aabb-0002-ccdd-eeff', ['title' => 'Conv B', 'history' => []], 'default');

        $all = $this->memory->all('default');
        self::assertCount(2, $all);
    }

    public function test_forget_removes_conversation_and_messages(): void
    {
        $id = 'conv-forget-01-aabb-0001';
        $this->memory->set($id, [
            'title' => 'To Delete',
            'history' => [['role' => 'user', 'content' => 'bye']],
        ], 'default');

        $this->memory->forget($id, 'default');
        self::assertNull($this->memory->get($id, 'default'));
    }

    public function test_flush_removes_all_in_namespace(): void
    {
        $this->adminMemory->set('id-flush-001-aabb-ccdd', ['title' => 'A', 'history' => []], 'test-ns');
        $this->adminMemory->set('id-flush-002-aabb-ccdd', ['title' => 'B', 'history' => []], 'test-ns');
        $this->adminMemory->flush('test-ns');

        self::assertSame([], $this->adminMemory->all('test-ns'));
    }

    public function test_has_returns_true_after_set(): void
    {
        $id = 'conv-has-001-aabb-ccdd-eeff';
        $this->memory->set($id, ['title' => 'Exists', 'history' => []], 'default');
        self::assertTrue($this->memory->has($id, 'default'));
    }

    public function test_set_with_no_pdo_is_silent(): void
    {
        $mem = new OcDbConversationMemory(null, $this->prefix);
        $mem->set('key', ['history' => []], 'default');

        self::assertNull($mem->get('key', 'default'), 'set() with no pdo must store nothing');
    }

    public function test_auto_title_from_first_user_message(): void
    {
        $id = 'conv-autotitle-aabb-0001';
        $this->memory->set($id, [
            'title' => '',
            'history' => [
                ['role' => 'user', 'content' => 'What is the stock level for product 42?'],
            ],
        ], 'default');

        $result = $this->memory->get($id, 'default');
        self::assertNotEmpty($result['title'] ?? '');
    }

    public function test_tool_batch_message_stored_as_tool_role(): void
    {
        $id = 'conv-batch-001-aabb-ccdd';
        $this->memory->set($id, [
            'title' => 'Batch Test',
            'history' => [
                [
                    'role' => 'tool_batch',
                    'batch_calls' => [
                        ['tool_name' => 'oc_order', 'tool_input' => ['limit' => 5]],
                    ],
                    'batch_results' => ['result1'],
                ],
            ],
        ], 'default');

        $result = $this->memory->get($id, 'default');
        self::assertIsArray($result);
        self::assertCount(1, $result['history']);
        self::assertSame('tool', $result['history'][0]['role']);
    }

    public function test_messages_ordered_by_created_at(): void
    {
        $id = 'conv-order-001-aabb-ccdd';
        $this->memory->set($id, [
            'history' => [
                ['role' => 'user', 'content' => 'First'],
                ['role' => 'assistant', 'content' => 'Second'],
            ],
        ], 'default');

        $result = $this->memory->get($id, 'default');
        $roles = array_column($result['history'], 'role');
        self::assertSame(['user', 'assistant'], $roles);
    }

    public function test_null_pdo_flush_is_silent(): void
    {
        $mem = new OcDbConversationMemory(null, $this->prefix);
        $mem->flush('default');

        self::assertSame([], $mem->all('default'), 'a null pdo must read back as empty, not crash');
    }

    public function test_null_pdo_forget_is_silent(): void
    {
        $mem = new OcDbConversationMemory(null, $this->prefix);
        $mem->forget('key', 'default');

        self::assertNull($mem->get('key', 'default'), 'a null pdo must read back as empty, not crash');
    }

    public function test_null_pdo_has_returns_false(): void
    {
        $mem = new OcDbConversationMemory(null, $this->prefix);
        self::assertFalse($mem->has('key', 'default'));
    }

    public function test_null_pdo_all_returns_empty(): void
    {
        $mem = new OcDbConversationMemory(null, $this->prefix);
        self::assertSame([], $mem->all('default'));
    }

    public function test_get_with_invalid_db_throws_memory_exception(): void
    {
        self::$sharedMysqli->query("DROP TABLE IF EXISTS `{$this->prefix}phpclaw_conversations`");

        $this->expectException(MemoryException::class);
        $this->memory->get('any-id', 'default');
    }

    public function test_forget_with_invalid_db_throws_memory_exception(): void
    {
        self::$sharedMysqli->query("DROP TABLE IF EXISTS `{$this->prefix}phpclaw_conversations`");

        $this->expectException(MemoryException::class);
        $this->memory->forget('any-id', 'default');
    }

    public function test_flush_with_invalid_db_throws_memory_exception(): void
    {
        self::$sharedMysqli->query("DROP TABLE IF EXISTS `{$this->prefix}phpclaw_conversations`");

        $this->expectException(MemoryException::class);
        $this->adminMemory->flush('default');
    }

    public function test_all_with_invalid_db_throws_memory_exception(): void
    {
        self::$sharedMysqli->query("DROP TABLE IF EXISTS `{$this->prefix}phpclaw_conversations`");

        $this->expectException(MemoryException::class);
        $this->memory->all('default');
    }

    public function test_insert_messages_skips_non_array_entries(): void
    {
        $id = 'conv-skip-nonarr-aabb-0001';
        $this->memory->set($id, [
            'title' => 'Skip test',
            'history' => [
                'not-an-array',
                ['role' => 'user', 'content' => 'hello'],
            ],
        ], 'default');

        $result = $this->memory->get($id, 'default');
        self::assertIsArray($result);
        self::assertCount(1, $result['history']);
        self::assertSame('user', $result['history'][0]['role']);
    }

    public function test_insert_messages_skips_tool_batch_with_empty_calls(): void
    {
        $id = 'conv-skip-batch-aabb-0002';
        $this->memory->set($id, [
            'title' => 'Empty batch test',
            'history' => [
                ['role' => 'tool_batch', 'batch_calls' => [], 'batch_results' => []],
                ['role' => 'user', 'content' => 'hi'],
            ],
        ], 'default');

        $result = $this->memory->get($id, 'default');
        self::assertIsArray($result);
        self::assertCount(1, $result['history']);
        self::assertSame('user', $result['history'][0]['role']);
    }

    public function test_insert_messages_skips_unknown_role(): void
    {
        $id = 'conv-skip-role-aabb-0003';
        $this->memory->set($id, [
            'title' => 'Unknown role',
            'history' => [
                ['role' => 'system', 'content' => 'ignored'],
                ['role' => 'user',   'content' => 'kept'],
            ],
        ], 'default');

        $result = $this->memory->get($id, 'default');
        self::assertIsArray($result);
        self::assertCount(1, $result['history']);
        self::assertSame('user', $result['history'][0]['role']);
    }

    public function test_set_with_broken_db_throws_memory_exception_and_rolls_back(): void
    {
        self::$sharedMysqli->query("DROP TABLE IF EXISTS `{$this->prefix}phpclaw_messages`");

        $this->expectException(MemoryException::class);
        $this->memory->set('any-id-rollback-aabb', ['history' => []], 'default');
    }

    public function test_has_with_broken_db_returns_false(): void
    {
        self::$sharedMysqli->query("DROP TABLE IF EXISTS `{$this->prefix}phpclaw_conversations`");

        $result = $this->memory->has('any-id', 'default');
        self::assertFalse($result);
    }

    public function test_decode_json_returns_null_for_non_array_json(): void
    {
        $id = 'conv-decodejson-aabb-0004';
        $now = date('Y-m-d H:i:s');
        $table = $this->prefix.'phpclaw_conversations';
        $this->seed(
            "INSERT INTO `{$table}` (id, namespace, owner_id, title, metadata, created_at, updated_at)
             VALUES ('$id', 'default', 1, 'Test', '123', '$now', '$now')",
        );

        $result = $this->memory->get($id, 'default');
        self::assertIsArray($result);
        self::assertNull($result['metadata']);
    }

    public function test_all_scoped_to_current_user(): void
    {
        $mem1 = new OcDbConversationMemory($this->db, $this->prefix, true, 1, false);
        $mem1->set('conv-scope-user1-001', ['title' => 'User1 A', 'history' => []], 'default');
        $mem1->set('conv-scope-user1-002', ['title' => 'User1 B', 'history' => []], 'default');

        $mem2 = new OcDbConversationMemory($this->db, $this->prefix, true, 2, false);
        $mem2->set('conv-scope-user2-001', ['title' => 'User2 A', 'history' => []], 'default');

        $all1 = $mem1->all('default');
        self::assertCount(2, $all1);
        self::assertArrayHasKey('conv-scope-user1-001', $all1);
        self::assertArrayHasKey('conv-scope-user1-002', $all1);
        self::assertArrayNotHasKey('conv-scope-user2-001', $all1);

        $all2 = $mem2->all('default');
        self::assertCount(1, $all2);
        self::assertArrayHasKey('conv-scope-user2-001', $all2);
        self::assertArrayNotHasKey('conv-scope-user1-001', $all2);
    }

    public function test_all_returns_everything_for_manage_all(): void
    {
        $mem1 = new OcDbConversationMemory($this->db, $this->prefix, true, 1, false);
        $mem1->set('conv-ma-user1-aabb', ['title' => 'User1', 'history' => []], 'default');

        $mem2 = new OcDbConversationMemory($this->db, $this->prefix, true, 2, false);
        $mem2->set('conv-ma-user2-aabb', ['title' => 'User2', 'history' => []], 'default');

        $now = date('Y-m-d H:i:s');
        $table = $this->prefix.'phpclaw_conversations';

        $this->seed(
            "INSERT INTO `{$table}` (id, namespace, owner_id, title, metadata, created_at, updated_at)
             VALUES ('conv-ma-legacy-aabb', 'default', NULL, 'Legacy', NULL, '$now', '$now')",
        );

        $this->seed(
            "INSERT INTO `{$table}` (id, namespace, owner_id, title, metadata, created_at, updated_at)
             VALUES ('conv-ma-cli-aabb', 'default', 0, 'CLI', NULL, '$now', '$now')",
        );

        $admin = new OcDbConversationMemory($this->db, $this->prefix, true, 99, true);
        $all = $admin->all('default');

        self::assertCount(4, $all);
        self::assertArrayHasKey('conv-ma-user1-aabb', $all);
        self::assertArrayHasKey('conv-ma-user2-aabb', $all);
        self::assertArrayHasKey('conv-ma-legacy-aabb', $all);
        self::assertArrayHasKey('conv-ma-cli-aabb', $all);
    }

    public function test_get_denies_cross_user_without_manage_all(): void
    {
        $mem1 = new OcDbConversationMemory($this->db, $this->prefix, true, 1, false);
        $mem1->set('conv-deny-get-aabb', ['title' => 'User1 conv', 'history' => []], 'default');

        $mem2 = new OcDbConversationMemory($this->db, $this->prefix, true, 2, false);

        $this->expectException(ConversationAccessDeniedException::class);
        $mem2->get('conv-deny-get-aabb', 'default');
    }

    public function test_forget_denies_cross_user_without_manage_all(): void
    {
        $mem1 = new OcDbConversationMemory($this->db, $this->prefix, true, 1, false);
        $mem1->set('conv-deny-forget-aabb', ['title' => 'User1 conv', 'history' => []], 'default');

        $mem2 = new OcDbConversationMemory($this->db, $this->prefix, true, 2, false);

        $this->expectException(ConversationAccessDeniedException::class);
        $mem2->forget('conv-deny-forget-aabb', 'default');
    }

    public function test_flush_requires_manage_all(): void
    {
        $mem = new OcDbConversationMemory($this->db, $this->prefix, true, 1, false);

        $this->expectException(ConversationAccessDeniedException::class);
        $mem->flush('default');
    }

    public function test_set_stamps_owner_id_on_create_only(): void
    {
        $id = 'conv-stamp-owner-aabb';
        $mem1 = new OcDbConversationMemory($this->db, $this->prefix, true, 1, false);
        $mem1->set($id, ['title' => 'Original', 'history' => []], 'default');

        $table = $this->prefix.'phpclaw_conversations';
        $result = self::$sharedMysqli->query("SELECT owner_id FROM `{$table}` WHERE id = '{$id}'");
        $row = $result->fetch_assoc();
        self::assertSame(1, (int) $row['owner_id']);

        $mem_admin = new OcDbConversationMemory($this->db, $this->prefix, true, 99, true);
        $mem_admin->set($id, ['title' => 'Admin Update', 'history' => [['role' => 'user', 'content' => 'hi']]], 'default');

        $result2 = self::$sharedMysqli->query("SELECT owner_id FROM `{$table}` WHERE id = '{$id}'");
        $row2 = $result2->fetch_assoc();
        self::assertSame(1, (int) $row2['owner_id']);
    }
}

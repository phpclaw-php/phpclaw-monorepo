<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Memory;

use PhpClaw\PrestaShop\Exceptions\ConversationAccessDeniedException;
use PhpClaw\PrestaShop\Memory\PsDbConversationMemory;
use PhpClaw\PrestaShop\Tests\Helpers\PsDbTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(PsDbConversationMemory::class)]
final class PsDbConversationMemoryTest extends PsDbTestCase
{
    private PsDbConversationMemory $memory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetTables([
            "CREATE TABLE IF NOT EXISTS `{$this->prefix}phpclaw_conversations` (
                id          VARCHAR(64)  NOT NULL,
                namespace   VARCHAR(100) NOT NULL DEFAULT 'default',
                id_employee INT UNSIGNED DEFAULT NULL,
                title       VARCHAR(255) DEFAULT NULL,
                metadata    LONGTEXT     DEFAULT NULL,
                created_at  DATETIME     NOT NULL,
                updated_at  DATETIME     NOT NULL,
                PRIMARY KEY (id),
                KEY idx_namespace_created (namespace, created_at),
                KEY idx_employee_ns_updated (id_employee, namespace, updated_at)
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

        $this->memory = new PsDbConversationMemory($this->db, $this->prefix, true);
    }

    public function test_get_returns_null_for_missing_conversation(): void
    {
        self::assertNull($this->memory->get('nonexistent', 'conversations'));
    }

    public function test_set_creates_conversation_and_get_retrieves_it(): void
    {
        $data = [
            'title' => 'Test Chat',
            'history' => [['role' => 'user', 'content' => 'Hello']],
        ];

        $this->memory->set('conv-1', $data, 'conversations');
        $result = $this->memory->get('conv-1', 'conversations');

        self::assertIsArray($result);
        self::assertSame('Test Chat', $result['title']);
    }

    public function test_forget_removes_conversation(): void
    {
        $this->memory->set('conv-del', ['title' => 'Delete Me', 'history' => []], 'conversations');
        $this->memory->forget('conv-del', 'conversations');

        self::assertNull($this->memory->get('conv-del', 'conversations'));
    }

    public function test_flush_removes_all_in_namespace(): void
    {
        $this->memory->set('c1', ['title' => 'A', 'history' => []], 'conversations');
        $this->memory->set('c2', ['title' => 'B', 'history' => []], 'conversations');

        $manageAllMemory = new PsDbConversationMemory($this->db, $this->prefix, true, manageAll: true);
        $manageAllMemory->flush('conversations');

        self::assertNull($manageAllMemory->get('c1', 'conversations'));
        self::assertNull($manageAllMemory->get('c2', 'conversations'));
    }

    public function test_flush_does_nothing_without_manage_all(): void
    {
        $this->memory->set('c1', ['title' => 'A', 'history' => []], 'conversations');

        $this->memory->flush('conversations');

        $row = $this->memory->get('c1', 'conversations');

        self::assertSame('c1', $row['id']);
        self::assertSame('A', $row['title']);
        self::assertSame([], $row['history']);
    }

    public function test_all_returns_all_conversations(): void
    {
        $this->memory->set('ca', ['title' => 'Alpha', 'history' => []], 'conversations');
        $this->memory->set('cb', ['title' => 'Beta', 'history' => []], 'conversations');

        $all = $this->memory->all('conversations');

        self::assertCount(2, $all);
    }

    public function test_has_returns_true_for_existing_conversation(): void
    {
        $this->memory->set('cx', ['title' => 'X', 'history' => []], 'conversations');

        self::assertTrue($this->memory->has('cx', 'conversations'));
    }

    public function test_has_returns_false_for_missing_conversation(): void
    {
        self::assertFalse($this->memory->has('cx', 'conversations'));
    }

    public function test_null_pdo_get_returns_null(): void
    {
        $memory = new PsDbConversationMemory(null, $this->prefix);

        self::assertNull($memory->get('any', 'conversations'));
    }

    public function test_null_pdo_set_does_not_throw(): void
    {
        $memory = new PsDbConversationMemory(null, $this->prefix);
        $memory->set('k', ['title' => 'x', 'history' => []], 'conversations');

        self::assertNull($memory->get('k', 'conversations'), 'set() with no pdo must store nothing');
    }

    public function test_null_db_all_returns_empty_array(): void
    {
        $memory = new PsDbConversationMemory(null, $this->prefix);

        self::assertSame([], $memory->all('conversations'));
    }

    public function test_null_db_has_returns_false(): void
    {
        $memory = new PsDbConversationMemory(null, $this->prefix);

        self::assertFalse($memory->has('any', 'conversations'));
    }

    public function test_null_db_forget_does_not_throw(): void
    {
        $memory = new PsDbConversationMemory(null, $this->prefix);
        $memory->forget('any', 'conversations');

        self::assertNull($memory->get('k', 'conversations'), 'a null db must read back as empty');
    }

    public function test_null_db_flush_does_not_throw(): void
    {
        $memory = new PsDbConversationMemory(null, $this->prefix);
        $memory->flush('conversations');

        self::assertSame([], $memory->all('conversations'), 'a null db must read back as empty');
    }

    public function test_history_is_loaded_when_store_messages_true(): void
    {
        $data = [
            'title' => 'With History',
            'history' => [
                ['role' => 'user',      'content' => 'Hello'],
                ['role' => 'assistant', 'content' => 'Hi there!'],
            ],
        ];

        $this->memory->set('conv-hist', $data, 'conversations');
        $result = $this->memory->get('conv-hist', 'conversations');

        self::assertIsArray($result);
        self::assertCount(2, $result['history']);
        self::assertSame('user', $result['history'][0]['role']);
        self::assertSame('Hello', $result['history'][0]['content']);
    }

    public function test_tool_message_persisted_and_retrieved(): void
    {
        $data = [
            'history' => [
                ['role' => 'user',      'content' => 'Check stock'],
                ['role' => 'tool',      'content' => '{"quantity":5}', 'tool_name' => 'ps_stock', 'tool_input' => ['product_id' => 1]],
                ['role' => 'assistant', 'content' => 'Stock is 5'],
            ],
        ];

        $this->memory->set('conv-tool', $data, 'conversations');
        $result = $this->memory->get('conv-tool', 'conversations');

        self::assertCount(3, $result['history']);
        $toolMsg = $result['history'][1];
        self::assertSame('tool', $toolMsg['role']);
        self::assertSame('ps_stock', $toolMsg['tool_name']);
        self::assertIsArray($toolMsg['tool_input']);
    }

    public function test_tool_batch_message_collapsed_to_single_tool_row(): void
    {
        $data = [
            'history' => [
                ['role' => 'user', 'content' => 'Parallel tools'],
                [
                    'role' => 'tool_batch',
                    'batch_calls' => [
                        ['tool_name' => 'ps_product', 'tool_input' => ['limit' => 5]],
                        ['tool_name' => 'ps_order',   'tool_input' => ['limit' => 3]],
                    ],
                    'batch_results' => ['r1', 'r2'],
                ],
                ['role' => 'assistant', 'content' => 'Done'],
            ],
        ];

        $this->memory->set('conv-batch', $data, 'conversations');
        $result = $this->memory->get('conv-batch', 'conversations');

        $toolRows = array_filter($result['history'], fn (array $m): bool => $m['role'] === 'tool');
        self::assertCount(1, $toolRows);
    }

    public function test_unknown_role_skipped_during_replace(): void
    {
        $data = [
            'history' => [
                ['role' => 'user',    'content' => 'Hi'],
                ['role' => 'unknown', 'content' => 'Ignored'],
                ['role' => 'assistant', 'content' => 'Hello'],
            ],
        ];

        $this->memory->set('conv-unk', $data, 'conversations');
        $result = $this->memory->get('conv-unk', 'conversations');

        self::assertCount(2, $result['history']);
    }

    public function test_title_auto_extracted_from_first_user_message(): void
    {
        $data = [
            'history' => [['role' => 'user', 'content' => 'Short message']],
        ];

        $this->memory->set('conv-auto-title', $data, 'conversations');
        $result = $this->memory->get('conv-auto-title', 'conversations');

        self::assertSame('Short message', $result['title']);
    }

    public function test_title_truncated_when_exceeds_60_chars(): void
    {
        $this->memory->set(
            'conv-long-title',
            ['history' => [['role' => 'user', 'content' => str_repeat('A', 70)]]],
            'conversations',
        );

        self::assertSame(
            str_repeat('A', 60)."\u{2026}",
            $this->memory->get('conv-long-title', 'conversations')['title'],
        );
    }

    public function test_set_updates_existing_conversation_on_second_write(): void
    {
        $this->memory->set('conv-upd', ['title' => 'First', 'history' => []], 'conversations');
        $this->memory->set('conv-upd', ['title' => 'Updated', 'history' => []], 'conversations');

        $result = $this->memory->get('conv-upd', 'conversations');

        self::assertSame('Updated', $result['title']);
    }

    public function test_metadata_persisted_and_decoded(): void
    {
        $data = [
            'title' => 'Meta test',
            'history' => [],
            'metadata' => ['user_id' => 42, 'lang' => 'en'],
        ];

        $this->memory->set('conv-meta', $data, 'conversations');
        $result = $this->memory->get('conv-meta', 'conversations');

        self::assertSame(42, $result['metadata']['user_id']);
    }

    public function test_store_messages_false_skips_persistence(): void
    {
        $noStoreMem = new PsDbConversationMemory($this->db, $this->prefix, false);

        $noStoreMem->set('conv-nostore', [
            'title' => 'No Store',
            'history' => [['role' => 'user', 'content' => 'test']],
        ], 'conversations');

        self::assertNull($noStoreMem->get('conv-nostore', 'conversations'));
    }

    public function test_all_returns_summary_with_title_and_id(): void
    {
        $this->memory->set('sum-1', ['title' => 'Summary', 'history' => []], 'ns-all');
        $all = $this->memory->all('ns-all');

        self::assertArrayHasKey('sum-1', $all);
        self::assertSame('Summary', $all['sum-1']['title']);
        self::assertArrayHasKey('created_at', $all['sum-1']);
    }

    public function test_history_empty_when_store_messages_false(): void
    {
        $noStore = new PsDbConversationMemory($this->db, $this->prefix, false);

        $noStore->set('conv-ns', ['title' => 'Hidden', 'history' => [['role' => 'user', 'content' => 'x']]], 'conversations');

        self::assertNull($noStore->get('conv-ns', 'conversations'));
    }

    public function test_set_non_array_value_is_no_op(): void
    {
        $this->memory->set('conv-nonarr', 'just a string', 'conversations');

        self::assertNull($this->memory->get('conv-nonarr', 'conversations'));
    }

    public function test_flush_with_messages_also_clears_messages(): void
    {
        $data = [
            'history' => [
                ['role' => 'user',      'content' => 'Flush me'],
                ['role' => 'assistant', 'content' => 'OK'],
            ],
        ];

        $this->memory->set('conv-flush2', $data, 'ns-flush2');

        $manageAllMemory = new PsDbConversationMemory($this->db, $this->prefix, true, manageAll: true);
        $manageAllMemory->flush('ns-flush2');

        self::assertNull($manageAllMemory->get('conv-flush2', 'ns-flush2'));
    }

    public function test_get_refuses_a_conversation_owned_by_another_employee(): void
    {
        $owner = new PsDbConversationMemory($this->db, $this->prefix, true, 7);
        $owner->set('c-owned', ['title' => 'Mine', 'history' => []], 'conversations');

        $intruder = new PsDbConversationMemory($this->db, $this->prefix, true, 9);

        $this->expectException(ConversationAccessDeniedException::class);

        $intruder->get('c-owned', 'conversations');
    }

    public function test_get_allows_the_owning_employee(): void
    {
        $owner = new PsDbConversationMemory($this->db, $this->prefix, true, 7);
        $owner->set('c-owned', ['title' => 'Mine', 'history' => []], 'conversations');

        self::assertSame('Mine', $owner->get('c-owned', 'conversations')['title']);
    }

    public function test_manage_all_reads_a_conversation_owned_by_another_employee(): void
    {
        $owner = new PsDbConversationMemory($this->db, $this->prefix, true, 7);
        $owner->set('c-owned', ['title' => 'Mine', 'history' => []], 'conversations');

        $admin = new PsDbConversationMemory($this->db, $this->prefix, true, 9, true);

        self::assertSame('Mine', $admin->get('c-owned', 'conversations')['title']);
    }

    public function test_forget_refuses_a_conversation_owned_by_another_employee(): void
    {
        $owner = new PsDbConversationMemory($this->db, $this->prefix, true, 7);
        $owner->set('c-owned', ['title' => 'Mine', 'history' => []], 'conversations');

        try {
            (new PsDbConversationMemory($this->db, $this->prefix, true, 9))->forget('c-owned', 'conversations');
            self::fail('forget() must refuse a conversation owned by another employee.');
        } catch (ConversationAccessDeniedException) {
            self::assertTrue($owner->has('c-owned', 'conversations'));
        }
    }

    public function test_manage_all_forgets_a_conversation_owned_by_another_employee(): void
    {
        $owner = new PsDbConversationMemory($this->db, $this->prefix, true, 7);
        $owner->set('c-owned', ['title' => 'Mine', 'history' => []], 'conversations');

        (new PsDbConversationMemory($this->db, $this->prefix, true, 9, true))->forget('c-owned', 'conversations');

        self::assertFalse($owner->has('c-owned', 'conversations'));
    }

    public function test_all_lists_only_the_acting_employees_conversations(): void
    {
        (new PsDbConversationMemory($this->db, $this->prefix, true, 7))
            ->set('c-seven', ['title' => 'Seven', 'history' => []], 'conversations');
        $nine = new PsDbConversationMemory($this->db, $this->prefix, true, 9);
        $nine->set('c-nine', ['title' => 'Nine', 'history' => []], 'conversations');

        self::assertSame(['c-nine'], array_keys($nine->all('conversations')));
    }

    public function test_manage_all_lists_every_employees_conversations(): void
    {
        (new PsDbConversationMemory($this->db, $this->prefix, true, 7))
            ->set('c-seven', ['title' => 'Seven', 'history' => []], 'conversations');
        (new PsDbConversationMemory($this->db, $this->prefix, true, 9))
            ->set('c-nine', ['title' => 'Nine', 'history' => []], 'conversations');

        $keys = array_keys((new PsDbConversationMemory($this->db, $this->prefix, true, 9, true))->all('conversations'));
        sort($keys);

        self::assertSame(['c-nine', 'c-seven'], $keys);
    }
}

<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Unit\Memory;

use Joomla\Database\DatabaseInterface;
use Joomla\Database\QueryInterface;
use PhpClaw\Exceptions\MemoryException;
use PhpClaw\Joomla\Component\Administrator\Exceptions\ConversationAccessDeniedException;
use PhpClaw\Joomla\Component\Administrator\Memory\JoomlaDbConversationMemory;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class JoomlaDbConversationMemoryTest extends TestCase
{
    private DatabaseInterface&MockObject $db;

    private QueryInterface&MockObject $query;

    private JoomlaDbConversationMemory $memory;

    protected function setUp(): void
    {
        $this->query = $this->createMock(QueryInterface::class);
        $this->query->method('select')->willReturnSelf();
        $this->query->method('from')->willReturnSelf();
        $this->query->method('where')->willReturnSelf();
        $this->query->method('order')->willReturnSelf();
        $this->query->method('delete')->willReturnSelf();
        $this->query->method('update')->willReturnSelf();
        $this->query->method('insert')->willReturnSelf();
        $this->query->method('set')->willReturnSelf();
        $this->query->method('columns')->willReturnSelf();
        $this->query->method('values')->willReturnSelf();

        $this->db = $this->createMock(DatabaseInterface::class);
        $this->db->method('getQuery')->willReturn($this->query);
        $this->db->method('quoteName')->willReturnCallback(
            fn (mixed $n) => is_array($n) ? $n : (string) $n,
        );
        $this->db->method('quote')->willReturnCallback(
            fn (mixed $v) => "'".addslashes((string) $v)."'",
        );
        $this->db->method('setQuery')->willReturnSelf();

        $this->memory = new JoomlaDbConversationMemory($this->db, true, 7);
    }

    public function test_class_implements_memory_interface(): void
    {
        $this->assertTrue(
            is_a(JoomlaDbConversationMemory::class, MemoryInterface::class, true),
        );
    }

    public function test_class_is_final(): void
    {
        $ref = new \ReflectionClass(JoomlaDbConversationMemory::class);
        $this->assertTrue($ref->isFinal());
    }

    public function test_constructor_accepts_database_and_store_messages(): void
    {
        $ref = new \ReflectionClass(JoomlaDbConversationMemory::class);
        $constructor = $ref->getConstructor();
        $this->assertNotNull($constructor);

        $params = $constructor->getParameters();
        $this->assertCount(4, $params);
        $this->assertSame('db', $params[0]->getName());
        $this->assertSame('storeMessages', $params[1]->getName());
        $this->assertTrue($params[1]->isOptional());
        $this->assertTrue($params[1]->getDefaultValue());
        $this->assertSame('actingUserId', $params[2]->getName());
        $this->assertTrue($params[2]->isOptional());
        $this->assertSame(0, $params[2]->getDefaultValue());
        $this->assertSame('manageAll', $params[3]->getName());
        $this->assertTrue($params[3]->isOptional());
        $this->assertFalse($params[3]->getDefaultValue());
    }

    public function test_all_memory_interface_methods_exist(): void
    {
        foreach (['get', 'set', 'forget', 'flush', 'all', 'has'] as $method) {
            $this->assertTrue(
                method_exists(JoomlaDbConversationMemory::class, $method),
                "Method {$method}() must exist.",
            );
        }
    }

    public function test_get_returns_null_when_conversation_not_found(): void
    {
        $this->db->method('loadAssoc')->willReturn(null);

        $result = $this->memory->get('nonexistent-id', 'conversations');

        $this->assertNull($result);
    }

    public function test_get_returns_conversation_array_when_found(): void
    {
        $this->db->method('loadAssoc')->willReturn([
            'id' => 'conv-123',
            'namespace' => 'conversations',
            'user_id' => 7,
            'title' => 'Test chat',
            'metadata' => '{}',
            'created_at' => '2026-05-01 12:00:00',
        ]);
        $this->db->method('loadAssocList')->willReturn([]);

        $result = $this->memory->get('conv-123', 'conversations');

        $this->assertIsArray($result);
        $this->assertSame('conv-123', $result['id']);
        $this->assertSame('Test chat', $result['title']);
        $this->assertArrayHasKey('history', $result);
    }

    public function test_get_includes_message_history_when_store_messages_true(): void
    {
        $this->db->method('loadAssoc')->willReturn([
            'id' => 'conv-with-messages',
            'namespace' => 'conversations',
            'user_id' => 7,
            'title' => 'Chat',
            'metadata' => null,
            'created_at' => '2026-05-01 12:00:00',
        ]);
        $this->db->method('loadAssocList')->willReturn([
            ['role' => 'user',      'content' => 'Hello', 'tool_name' => null, 'tool_input' => null],
            ['role' => 'assistant', 'content' => 'Hi!',   'tool_name' => null, 'tool_input' => null],
        ]);

        $result = $this->memory->get('conv-with-messages', 'conversations');

        $this->assertCount(2, $result['history']);
        $this->assertSame('user', $result['history'][0]['role']);
        $this->assertSame('assistant', $result['history'][1]['role']);
    }

    public function test_get_skips_message_history_when_store_messages_false(): void
    {
        $memoryNoStore = new JoomlaDbConversationMemory($this->db, false, 7);

        $this->db->method('loadAssoc')->willReturn([
            'id' => 'conv-private',
            'namespace' => 'conversations',
            'user_id' => 7,
            'title' => null,
            'metadata' => null,
            'created_at' => '2026-05-01 12:00:00',
        ]);

        $result = $memoryNoStore->get('conv-private', 'conversations');

        $this->assertIsArray($result);
        $this->assertSame([], $result['history']);
    }

    public function test_set_throws_for_non_array_value(): void
    {
        $this->expectException(MemoryException::class);
        $this->expectExceptionMessage('expects an array value');

        $this->memory->set('test-id', 'not-an-array', 'conversations');
    }

    public function test_set_inserts_new_conversation_when_not_exists(): void
    {
        $this->db->method('loadResult')->willReturn(null);
        $this->db->expects(self::atLeastOnce())->method('execute');

        $this->memory->set('new-conv-id', [
            'history' => [],
            'metadata' => [],
        ], 'conversations');
    }

    public function test_set_updates_existing_conversation(): void
    {
        $this->db->method('loadResult')->willReturn('new-conv-id');
        $this->db->expects(self::atLeastOnce())->method('execute');

        $this->memory->set('new-conv-id', [
            'history' => [],
            'metadata' => ['key' => 'val'],
        ], 'conversations');
    }

    public function test_set_stores_messages_when_store_messages_true(): void
    {
        $this->db->method('loadResult')->willReturn(null);
        $executeCount = 0;
        $this->db->method('execute')->willReturnCallback(function () use (&$executeCount): void {
            $executeCount++;
        });

        $this->memory->set('msg-conv', [
            'history' => [
                ['role' => 'user',      'content' => 'Hello'],
                ['role' => 'assistant', 'content' => 'World'],
            ],
        ], 'conversations');

        $this->assertGreaterThanOrEqual(4, $executeCount);
    }

    public function test_set_persists_nothing_when_store_messages_false(): void
    {
        $memoryNoStore = new JoomlaDbConversationMemory($this->db, false, 7);

        $this->db->method('loadResult')->willReturn(null);
        $executeCount = 0;
        $this->db->method('execute')->willReturnCallback(function () use (&$executeCount): void {
            $executeCount++;
        });

        $memoryNoStore->set('private-conv', [
            'history' => [
                ['role' => 'user', 'content' => 'Secret question'],
            ],
        ], 'conversations');

        $this->assertSame(0, $executeCount);
    }

    public function test_forget_deletes_messages_and_conversation(): void
    {
        $count = 0;
        $this->db->method('execute')->willReturnCallback(function () use (&$count): void {
            $count++;
        });

        $this->memory->forget('conv-to-delete', 'conversations');

        $this->assertSame(2, $count);
    }

    public function test_flush_deletes_all_conversations_in_namespace(): void
    {
        $this->db->method('loadColumn')->willReturn(['id1', 'id2']);
        $count = 0;
        $this->db->method('execute')->willReturnCallback(function () use (&$count): void {
            $count++;
        });

        $this->memory->flush('conversations');

        $this->assertSame(2, $count);
    }

    public function test_flush_skips_message_delete_when_no_conversations(): void
    {
        $this->db->method('loadColumn')->willReturn([]);
        $count = 0;
        $this->db->method('execute')->willReturnCallback(function () use (&$count): void {
            $count++;
        });

        $this->memory->flush('empty_ns');

        $this->assertSame(1, $count);
    }

    public function test_all_returns_empty_array_when_no_conversations(): void
    {
        $this->db->method('loadAssocList')->willReturn([]);

        $result = $this->memory->all('conversations');

        $this->assertSame([], $result);
    }

    public function test_all_returns_keyed_array_by_conversation_id(): void
    {
        $this->db->method('loadAssocList')->willReturn([
            ['id' => 'abc', 'title' => 'First chat',  'metadata' => '{}', 'created_at' => '2026-05-01', 'updated_at' => '2026-05-01'],
            ['id' => 'def', 'title' => 'Second chat', 'metadata' => '{}', 'created_at' => '2026-05-02', 'updated_at' => '2026-05-02'],
        ]);

        $result = $this->memory->all('conversations');

        $this->assertCount(2, $result);
        $this->assertArrayHasKey('abc', $result);
        $this->assertArrayHasKey('def', $result);
        $this->assertSame('First chat', $result['abc']['title']);
        $this->assertSame('Second chat', $result['def']['title']);
    }

    public function test_has_returns_true_when_conversation_exists(): void
    {
        $this->db->method('loadResult')->willReturn('1');

        $this->assertTrue($this->memory->has('existing-conv', 'conversations'));
    }

    public function test_has_returns_false_when_conversation_does_not_exist(): void
    {
        $this->db->method('loadResult')->willReturn('0');

        $this->assertFalse($this->memory->has('missing-conv', 'conversations'));
    }

    public function test_extract_title_uses_first_user_message(): void
    {
        $ref = new \ReflectionMethod(JoomlaDbConversationMemory::class, 'extractTitleFromHistory');

        $history = [
            ['role' => 'user',      'content' => 'Hello world'],
            ['role' => 'assistant', 'content' => 'Hi there'],
        ];

        $title = $ref->invoke(null, $history);
        $this->assertSame('Hello world', $title);
    }

    public function test_extract_title_truncates_long_messages(): void
    {
        $ref = new \ReflectionMethod(JoomlaDbConversationMemory::class, 'extractTitleFromHistory');

        $longMessage = str_repeat('A', 100);
        $history = [['role' => 'user', 'content' => $longMessage]];

        $title = $ref->invoke(null, $history);
        $this->assertSame(61, mb_strlen($title));
        $this->assertStringEndsWith("\u{2026}", $title);
    }

    public function test_extract_title_returns_null_for_empty_history(): void
    {
        $ref = new \ReflectionMethod(JoomlaDbConversationMemory::class, 'extractTitleFromHistory');

        $title = $ref->invoke(null, []);
        $this->assertNull($title);
    }

    public function test_extract_title_skips_assistant_messages(): void
    {
        $ref = new \ReflectionMethod(JoomlaDbConversationMemory::class, 'extractTitleFromHistory');

        $history = [
            ['role' => 'assistant', 'content' => 'I am an AI'],
            ['role' => 'user',      'content' => 'What is PHP?'],
        ];

        $title = $ref->invoke(null, $history);
        $this->assertSame('What is PHP?', $title);
    }

    public function test_set_stores_tool_batch_as_single_row(): void
    {
        $this->db->method('loadResult')->willReturn(null);
        $executeCount = 0;
        $this->db->method('execute')->willReturnCallback(function () use (&$executeCount): void {
            $executeCount++;
        });

        $this->memory->set('batch-conv', [
            'history' => [
                [
                    'role' => 'tool_batch',
                    'batch_calls' => [
                        ['tool_name' => 'joomla_articles', 'tool_input' => ['limit' => 5]],
                        ['tool_name' => 'joomla_categories', 'tool_input' => []],
                    ],
                    'batch_results' => ['result-a', 'result-b'],
                ],
            ],
        ], 'conversations');

        $this->assertGreaterThanOrEqual(3, $executeCount);
    }

    public function test_set_skips_tool_batch_with_empty_calls(): void
    {
        $this->db->method('loadResult')->willReturn(null);
        $executeCount = 0;
        $this->db->method('execute')->willReturnCallback(function () use (&$executeCount): void {
            $executeCount++;
        });

        $this->memory->set('batch-empty', [
            'history' => [
                [
                    'role' => 'tool_batch',
                    'batch_calls' => [],
                    'batch_results' => [],
                ],
            ],
        ], 'conversations');

        $this->assertSame(2, $executeCount);
    }

    public function test_set_skips_unknown_role(): void
    {
        $this->db->method('loadResult')->willReturn(null);
        $executeCount = 0;
        $this->db->method('execute')->willReturnCallback(function () use (&$executeCount): void {
            $executeCount++;
        });

        $this->memory->set('unknown-role-conv', [
            'history' => [
                ['role' => 'system', 'content' => 'You are an AI'],
            ],
        ], 'conversations');

        $this->assertSame(2, $executeCount);
    }

    public function test_set_skips_non_array_history_entries(): void
    {
        $this->db->method('loadResult')->willReturn(null);
        $executeCount = 0;
        $this->db->method('execute')->willReturnCallback(function () use (&$executeCount): void {
            $executeCount++;
        });

        $this->memory->set('non-array-conv', [
            'history' => [
                'not-an-array',
                ['role' => 'user', 'content' => 'Valid'],
            ],
        ], 'conversations');

        $this->assertGreaterThanOrEqual(3, $executeCount);
    }

    public function test_set_updates_title_on_existing_conversation(): void
    {
        $this->db->method('loadResult')->willReturn('existing-id');
        $this->db->expects(self::atLeastOnce())->method('execute');

        $this->memory->set('existing-id', [
            'title' => 'My updated title',
            'history' => [],
            'metadata' => [],
        ], 'conversations');
    }

    public function test_message_row_to_entry_decodes_tool_input(): void
    {
        $this->db->method('loadAssoc')->willReturn(
            ['id' => 'conv-1', 'namespace' => 'conversations', 'user_id' => 7, 'title' => 'T', 'metadata' => null, 'created_at' => '2025-01-01', 'updated_at' => null],
        );
        $this->db->method('loadAssocList')->willReturn([
            [
                'role' => 'tool',
                'content' => 'result',
                'tool_name' => 'joomla_users',
                'tool_input' => '{"limit":10,"search":"alice"}',
            ],
        ]);

        $result = $this->memory->get('conv-1', 'conversations');

        $this->assertIsArray($result);
        $entry = $result['history'][0];
        $this->assertSame('tool', $entry['role']);
        $this->assertSame('joomla_users', $entry['tool_name']);
        $this->assertSame(['limit' => 10, 'search' => 'alice'], $entry['tool_input']);
    }

    public function test_message_row_to_entry_handles_invalid_tool_input_json(): void
    {
        $this->db->method('loadAssoc')->willReturn(
            ['id' => 'conv-2', 'namespace' => 'conversations', 'user_id' => 7, 'title' => 'T', 'metadata' => null, 'created_at' => '2025-01-01', 'updated_at' => null],
        );
        $this->db->method('loadAssocList')->willReturn([
            ['role' => 'tool', 'content' => 'r', 'tool_name' => 'x', 'tool_input' => 'not-json'],
        ]);

        $result = $this->memory->get('conv-2', 'conversations');

        $this->assertNull($result['history'][0]['tool_input']);
    }

    public function test_insert_message_encodes_tool_input(): void
    {
        $this->db->method('loadResult')->willReturn(null);
        $quoted = [];
        $this->db->method('quote')->willReturnCallback(function ($v) use (&$quoted) {
            $quoted[] = (string) $v;

            return "'".addslashes((string) $v)."'";
        });

        $this->memory->set('conv-tool-input', [
            'history' => [
                ['role' => 'tool', 'content' => 'r', 'tool_name' => 'joomla_users', 'tool_input' => ['limit' => 5]],
            ],
        ], 'conversations');

        $foundJson = false;
        foreach ($quoted as $val) {
            if (str_contains($val, '"limit":5')) {
                $foundJson = true;
                break;
            }
        }
        $this->assertTrue($foundJson, 'expected JSON-encoded tool_input among quoted values');
    }

    public function test_guard_re_raises_memory_exception(): void
    {
        $this->db->method('loadAssoc')->willThrowException(
            new MemoryException('inner memory failure'),
        );

        $this->expectException(MemoryException::class);
        $this->expectExceptionMessage('inner memory failure');

        $this->memory->get('any-key', 'conversations');
    }

    public function test_guard_wraps_generic_throwable_in_memory_exception(): void
    {
        $this->db->method('loadAssoc')->willThrowException(
            new \RuntimeException('connection refused'),
        );

        $this->expectException(MemoryException::class);
        $this->expectExceptionMessage('JoomlaDbConversationMemory::get failed: connection refused');

        $this->memory->get('any-key', 'conversations');
    }

    public function test_get_denies_a_conversation_owned_by_another_user(): void
    {
        $this->db->method('loadAssoc')->willReturn(
            ['id' => 'conv-9', 'namespace' => 'conversations', 'user_id' => 99, 'title' => 'T', 'metadata' => null, 'created_at' => '2025-01-01', 'updated_at' => null],
        );

        $this->expectException(ConversationAccessDeniedException::class);

        $this->memory->get('conv-9', 'conversations');
    }

    public function test_get_denies_an_unowned_conversation_for_a_regular_user(): void
    {
        $this->db->method('loadAssoc')->willReturn(
            ['id' => 'conv-cli', 'namespace' => 'conversations', 'user_id' => 0, 'title' => 'T', 'metadata' => null, 'created_at' => '2025-01-01', 'updated_at' => null],
        );

        $this->expectException(ConversationAccessDeniedException::class);

        $this->memory->get('conv-cli', 'conversations');
    }

    public function test_manage_all_reads_any_conversation(): void
    {
        $this->db->method('loadAssoc')->willReturn(
            ['id' => 'conv-9', 'namespace' => 'conversations', 'user_id' => 99, 'title' => 'T', 'metadata' => null, 'created_at' => '2025-01-01', 'updated_at' => null],
        );
        $this->db->method('loadAssocList')->willReturn([]);

        $admin = new JoomlaDbConversationMemory($this->db, true, 7, true);

        $this->assertIsArray($admin->get('conv-9', 'conversations'));
    }

    public function test_ownership_denial_is_not_wrapped_in_a_memory_exception(): void
    {
        $this->db->method('loadAssoc')->willReturn(
            ['id' => 'conv-9', 'namespace' => 'conversations', 'user_id' => 99, 'title' => 'T', 'metadata' => null, 'created_at' => '2025-01-01', 'updated_at' => null],
        );

        try {
            $this->memory->get('conv-9', 'conversations');
            $this->fail('Expected ConversationAccessDeniedException');
        } catch (ConversationAccessDeniedException $e) {
            $this->assertNotInstanceOf(MemoryException::class, $e);
        }
    }

    public function test_conversation_insert_stamps_the_acting_user(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3).'/component/src/Memory/JoomlaDbConversationMemory.php',
        );

        $this->assertStringContainsString("'id', 'namespace', 'user_id', 'title', 'metadata',", $source);
        $this->assertMatchesRegularExpression(
            '/columns\(self::CONVERSATION_COLUMNS\).*?actingUserId/s',
            $source,
            'insertConversation() must write the acting user id',
        );
    }

    public function test_update_never_rewrites_the_owner(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3).'/component/src/Memory/JoomlaDbConversationMemory.php',
        );

        $this->assertDoesNotMatchRegularExpression(
            '/private function updateConversation\(.*?quoteName\(.user_id.\).*?private function/s',
            $source,
            'updateConversation() must not touch user_id: ownership is stamped on create only',
        );
    }
}

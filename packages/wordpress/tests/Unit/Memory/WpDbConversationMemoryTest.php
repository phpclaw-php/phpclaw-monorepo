<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\Memory;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\Exceptions\MemoryException;
use PhpClaw\WordPress\Exceptions\ConversationAccessDeniedException;
use PhpClaw\WordPress\Memory\WpDbConversationMemory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WpDbConversationMemory::class)]
final class WpDbConversationMemoryTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private object $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->wpdb = new class
        {
            public string $prefix = 'wp_';

            public string $last_error = '';

            public array $queryLog = [];

            public array $deleteLog = [];

            public array $insertLog = [];

            public array $resultsQueryLog = [];

            public ?array $nextGetRow = null;

            public ?array $nextGetResults = null;

            public ?array $nextGetCol = null;

            public ?string $nextGetVar = null;

            public function prepare(string $sql, mixed ...$args): string
            {
                $i = 0;

                return preg_replace_callback('/%s|%d/', function () use (&$i, $args) {
                    return "'".addslashes((string) ($args[$i++] ?? ''))."'";
                }, $sql);
            }

            public function query(string $sql): int|false
            {
                $this->queryLog[] = $sql;

                return 1;
            }

            public function get_row(string $sql, string $output = OBJECT): mixed
            {
                return $this->nextGetRow;
            }

            public function get_results(string $sql, string $output = OBJECT): array
            {
                $this->resultsQueryLog[] = $sql;

                return $this->nextGetResults ?? [];
            }

            public function get_col(string $sql): array
            {
                return $this->nextGetCol ?? [];
            }

            public function get_var(string $sql): ?string
            {
                return $this->nextGetVar;
            }

            public function delete(string $table, array $where, array $format = []): int|false
            {
                $this->deleteLog[] = [$table, $where];

                return 1;
            }

            public function insert(string $table, array $data, array $format = []): int|false
            {
                $this->insertLog[] = [$table, $data];

                return 1;
            }
        };

        $GLOBALS['wpdb'] = $this->wpdb;

        Functions\when('get_option')->justReturn([]);
        Functions\when('wp_cache_delete')->justReturn(true);

        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_current_user_id')->justReturn(5);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_get_returns_null_for_missing_conversation(): void
    {
        $this->wpdb->nextGetRow = null;

        $memory = new WpDbConversationMemory;

        self::assertNull($memory->get('missing-id'));
    }

    public function test_get_returns_conversation_array(): void
    {
        $this->wpdb->nextGetRow = [
            'id' => 'conv-01',
            'namespace' => 'default',
            'title' => 'Hello world',
            'metadata' => null,
            'created_at' => '2026-04-19 10:00:00',
        ];

        $memory = new WpDbConversationMemory;
        $result = $memory->get('conv-01');

        self::assertIsArray($result);
        self::assertSame('conv-01', $result['id']);
        self::assertSame('Hello world', $result['title']);
        self::assertSame([], $result['history']);
    }

    public function test_set_upserts_conversation_row(): void
    {
        $memory = new WpDbConversationMemory;
        $memory->set('conv-02', [
            'title' => 'Test conversation',
            'metadata' => [],
            'history' => [],
        ]);

        self::assertCount(1, $this->wpdb->queryLog);
        self::assertStringContainsString('INSERT INTO', $this->wpdb->queryLog[0]);
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $this->wpdb->queryLog[0]);
    }

    public function test_set_extracts_title_from_first_user_message(): void
    {
        Functions\when('get_option')->justReturn(['store_messages' => '1']);

        $memory = new WpDbConversationMemory;
        $memory->set('conv-03', [
            'history' => [
                ['role' => 'user', 'content' => 'What is the weather today?'],
            ],
        ]);

        $sql = $this->wpdb->queryLog[0];
        self::assertStringContainsString('What is the weather today?', $sql);
    }

    public function test_set_throws_on_non_array_value(): void
    {
        $this->expectException(MemoryException::class);

        $memory = new WpDbConversationMemory;
        $memory->set('conv-bad', 'not an array');
    }

    public function test_forget_deletes_conversation_row(): void
    {
        $memory = new WpDbConversationMemory;
        $memory->forget('conv-del', 'default');

        self::assertCount(1, $this->wpdb->deleteLog);
        self::assertSame(['id' => 'conv-del', 'namespace' => 'default'], $this->wpdb->deleteLog[0][1]);
    }

    public function test_has_returns_true_when_count_is_one(): void
    {
        $this->wpdb->nextGetVar = '1';

        $memory = new WpDbConversationMemory;

        self::assertTrue($memory->has('conv-exists'));
    }

    public function test_has_returns_false_when_count_is_zero(): void
    {
        $this->wpdb->nextGetVar = '0';

        $memory = new WpDbConversationMemory;

        self::assertFalse($memory->has('conv-missing'));
    }

    public function test_all_returns_indexed_array(): void
    {
        $this->wpdb->nextGetResults = [
            ['id' => 'c1', 'title' => 'First', 'metadata' => null, 'created_at' => '2026-01-01 00:00:00'],
            ['id' => 'c2', 'title' => 'Second', 'metadata' => null, 'created_at' => '2026-01-02 00:00:00'],
        ];

        $memory = new WpDbConversationMemory;
        $result = $memory->all();

        self::assertArrayHasKey('c1', $result);
        self::assertArrayHasKey('c2', $result);
        self::assertSame('First', $result['c1']['title']);
    }

    public function test_store_messages_defaults_to_true_when_not_configured(): void
    {
        Functions\when('get_option')->justReturn([]);

        $memory = new WpDbConversationMemory;

        $memory->set('conv_priv', [
            'history' => [['role' => 'user', 'content' => 'Private message']],
            'title' => null,
        ], 'conversations');

        self::assertNotEmpty($this->wpdb->queryLog);
        $query = implode(' ', $this->wpdb->queryLog);
        self::assertStringContainsString('Private message', $query);
    }

    public function test_store_messages_persists_content_when_enabled(): void
    {
        Functions\when('get_option')->justReturn(['store_messages' => '1']);

        $memory = new WpDbConversationMemory;

        $memory->set('conv_enabled', [
            'history' => [['role' => 'user', 'content' => 'Hello AI']],
            'title' => null,
        ], 'conversations');

        self::assertNotEmpty($this->wpdb->queryLog);
        $query = implode(' ', $this->wpdb->queryLog);
        self::assertStringContainsString('Hello AI', $query);
    }

    public function test_store_messages_off_skips_persistence(): void
    {
        Functions\when('get_option')->justReturn(['store_messages' => '0']);

        $memory = new WpDbConversationMemory;
        $memory->set('conv_off', [
            'history' => [['role' => 'user', 'content' => 'Secret prompt']],
            'title' => null,
        ], 'conversations');

        self::assertEmpty($this->wpdb->queryLog);
    }

    public function test_set_with_metadata_persists_as_json(): void
    {
        Functions\when('get_option')->justReturn(['store_messages' => '1']);

        $memory = new WpDbConversationMemory;
        $memory->set('conv_meta', [
            'title' => 'With metadata',
            'metadata' => ['user_id' => 42, 'tag' => 'support'],
            'history' => [],
        ]);

        $query = implode(' ', $this->wpdb->queryLog);
        self::assertStringContainsString('user_id', $query);
    }

    public function test_get_returns_history_when_messages_exist(): void
    {
        $this->wpdb->nextGetRow = [
            'id' => 'conv-with-msgs',
            'namespace' => 'default',
            'title' => 'Active conversation',
            'metadata' => null,
            'created_at' => '2026-04-19 10:00:00',
        ];
        $this->wpdb->nextGetResults = [
            ['role' => 'user',      'content' => 'Hi',          'tool_name' => null,    'tool_input' => null, 'created_at' => '2026-04-19 10:00:00'],
            ['role' => 'assistant', 'content' => 'Hello there', 'tool_name' => null,    'tool_input' => null, 'created_at' => '2026-04-19 10:00:01'],
        ];

        $memory = new WpDbConversationMemory;
        $result = $memory->get('conv-with-msgs');

        self::assertIsArray($result);
        self::assertCount(2, $result['history']);
        self::assertSame('Hi', $result['history'][0]['content']);
    }

    public function test_flush_deletes_namespace_conversations(): void
    {
        $memory = new WpDbConversationMemory;
        $memory->flush('default');

        self::assertNotEmpty($this->wpdb->deleteLog);
        self::assertSame(['namespace' => 'default'], $this->wpdb->deleteLog[0][1]);
    }

    public function test_flush_with_existing_conversations_deletes_messages_too(): void
    {
        $this->wpdb->nextGetCol = ['conv1', 'conv2'];

        $memory = new WpDbConversationMemory;
        $memory->flush('default');

        self::assertNotEmpty($this->wpdb->queryLog);
        self::assertNotEmpty($this->wpdb->deleteLog);
        self::assertStringContainsString('DELETE FROM', $this->wpdb->queryLog[0]);
    }

    public function test_all_returns_empty_when_no_rows(): void
    {
        $this->wpdb->nextGetResults = [];

        $memory = new WpDbConversationMemory;

        self::assertSame([], $memory->all());
    }

    public function test_all_with_namespace_filter(): void
    {
        $this->wpdb->nextGetResults = [
            ['id' => 'x1', 'title' => 'X', 'metadata' => null, 'created_at' => '2026-04-19 10:00:00'],
        ];

        $memory = new WpDbConversationMemory;
        $result = $memory->all('reports');

        self::assertArrayHasKey('x1', $result);
    }

    public function test_set_with_history_inserts_message_rows(): void
    {
        Functions\when('get_option')->justReturn(['store_messages' => '1']);

        $memory = new WpDbConversationMemory;
        $memory->set('conv_with_msgs', [
            'history' => [
                ['role' => 'user',      'content' => 'Hi'],
                ['role' => 'assistant', 'content' => 'Hello'],
            ],
            'title' => 'Greeting',
        ]);

        self::assertNotEmpty($this->wpdb->insertLog);
        self::assertGreaterThanOrEqual(2, count($this->wpdb->insertLog));
    }

    public function test_set_skips_non_array_messages_in_history(): void
    {
        Functions\when('get_option')->justReturn(['store_messages' => '1']);

        $memory = new WpDbConversationMemory;
        $memory->set('conv_mixed', [
            'history' => [
                'not-an-array',
                ['role' => 'user', 'content' => 'OK'],
                42,
            ],
            'title' => 't',
        ]);

        self::assertCount(1, $this->wpdb->insertLog);
    }

    public function test_set_skips_messages_with_invalid_role(): void
    {
        Functions\when('get_option')->justReturn(['store_messages' => '1']);

        $memory = new WpDbConversationMemory;
        $memory->set('conv_roles', [
            'history' => [
                ['role' => 'unknown_role', 'content' => 'skip'],
                ['role' => 'system',       'content' => 'skip too'],
                ['role' => 'user',         'content' => 'keep'],
            ],
            'title' => 't',
        ]);

        self::assertCount(1, $this->wpdb->insertLog);
    }

    public function test_set_throws_on_db_error(): void
    {
        Functions\when('get_option')->justReturn(['store_messages' => '1']);
        $this->wpdb->last_error = 'unique constraint';

        $this->expectException(MemoryException::class);
        $this->expectExceptionMessageMatches('/DB write failed/');

        $memory = new WpDbConversationMemory;
        $memory->set('conv_fail', ['title' => 'x', 'history' => []]);
    }

    public function test_get_with_namespace_filter(): void
    {
        $this->wpdb->nextGetRow = [
            'id' => 'conv-ns',
            'namespace' => 'special_ns',
            'title' => 'Scoped',
            'metadata' => null,
            'created_at' => '2026-04-19 10:00:00',
        ];

        $memory = new WpDbConversationMemory;
        $result = $memory->get('conv-ns', 'special_ns');

        self::assertSame('Scoped', $result['title']);
    }

    public function test_set_with_existing_created_at_preserves_it(): void
    {
        Functions\when('get_option')->justReturn(['store_messages' => '1']);

        $memory = new WpDbConversationMemory;
        $memory->set('conv_old', [
            'title' => 'Old',
            'history' => [],
            'created_at' => '2025-01-01 00:00:00',
        ]);

        $sql = $this->wpdb->queryLog[0] ?? '';
        self::assertStringContainsString('2025-01-01 00:00:00', $sql);
    }

    public function test_set_with_tool_batch_message(): void
    {
        Functions\when('get_option')->justReturn(['store_messages' => '1']);

        $memory = new WpDbConversationMemory;
        $memory->set('conv_batch', [
            'title' => 'B',
            'history' => [
                [
                    'role' => 'tool_batch',
                    'batch_calls' => [
                        ['tool_name' => 'http_get', 'tool_input' => ['url' => '/a']],
                        ['tool_name' => 'shell',    'tool_input' => ['cmd' => 'ls']],
                    ],
                    'batch_results' => ['resp1', 'resp2'],
                ],
            ],
        ]);

        self::assertCount(1, $this->wpdb->insertLog);
        self::assertSame('tool', $this->wpdb->insertLog[0][1]['role']);
        self::assertStringContainsString('http_get', $this->wpdb->insertLog[0][1]['tool_name']);
    }

    public function test_set_skips_tool_batch_with_empty_calls(): void
    {
        Functions\when('get_option')->justReturn(['store_messages' => '1']);

        $memory = new WpDbConversationMemory;
        $memory->set('conv_empty_batch', [
            'title' => 'B',
            'history' => [
                ['role' => 'tool_batch', 'batch_calls' => [], 'batch_results' => []],
            ],
        ]);

        self::assertEmpty($this->wpdb->insertLog);
    }

    public function test_has_returns_namespace_scoped(): void
    {
        $this->wpdb->nextGetVar = '1';

        $memory = new WpDbConversationMemory;

        self::assertTrue($memory->has('conv-id', 'special_ns'));
    }

    public function test_forget_with_explicit_namespace_deletes_messages_and_conv(): void
    {
        $memory = new WpDbConversationMemory;
        $memory->forget('to-del', 'reports');

        self::assertNotEmpty($this->wpdb->deleteLog);
        $namespaces = array_column(array_column($this->wpdb->deleteLog, 1), 'namespace');

        self::assertContains('reports', $namespaces);
    }

    public function test_all_scoped_to_current_user(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('get_current_user_id')->justReturn(7);

        $this->wpdb->nextGetResults = [
            ['id' => 'u7-conv', 'title' => 'User 7 conv', 'metadata' => null, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'],
        ];

        $memory = new WpDbConversationMemory;
        $result = $memory->all('conversations');

        $sql = implode(' ', $this->wpdb->resultsQueryLog);
        self::assertStringContainsString("'7'", $sql);
        self::assertArrayHasKey('u7-conv', $result);
    }

    public function test_all_returns_everything_for_manage_all_capability(): void
    {
        Functions\when('current_user_can')->justReturn(true);

        $this->wpdb->nextGetResults = [
            ['id' => 'admin-conv', 'title' => 'Admin view', 'metadata' => null, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'],
        ];

        $memory = new WpDbConversationMemory;
        $result = $memory->all('conversations');

        $sql = implode(' ', $this->wpdb->resultsQueryLog);
        self::assertStringNotContainsString('user_id', $sql);
        self::assertArrayHasKey('admin-conv', $result);
    }

    public function test_get_denies_cross_user_without_capability(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('get_current_user_id')->justReturn(7);

        $this->wpdb->nextGetRow = [
            'id' => 'u5-conv',
            'namespace' => 'conversations',
            'title' => 'User 5 private',
            'metadata' => null,
            'user_id' => 5,
            'created_at' => '2026-01-01 00:00:00',
        ];

        $this->expectException(ConversationAccessDeniedException::class);

        $memory = new WpDbConversationMemory;
        $memory->get('u5-conv', 'conversations');
    }

    public function test_get_allows_own_conversation(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('get_current_user_id')->justReturn(5);

        $this->wpdb->nextGetRow = [
            'id' => 'u5-conv',
            'namespace' => 'conversations',
            'title' => 'My conversation',
            'metadata' => null,
            'user_id' => 5,
            'created_at' => '2026-01-01 00:00:00',
        ];

        $memory = new WpDbConversationMemory;
        $result = $memory->get('u5-conv', 'conversations');

        self::assertIsArray($result);
        self::assertSame('My conversation', $result['title']);
    }

    public function test_get_manage_all_capability_bypasses_ownership(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_current_user_id')->justReturn(9);

        $this->wpdb->nextGetRow = [
            'id' => 'u5-conv',
            'namespace' => 'conversations',
            'title' => 'Another user conv',
            'metadata' => null,
            'user_id' => 5,
            'created_at' => '2026-01-01 00:00:00',
        ];

        $memory = new WpDbConversationMemory;
        $result = $memory->get('u5-conv', 'conversations');

        self::assertIsArray($result);
        self::assertSame('Another user conv', $result['title']);
    }

    public function test_set_stamps_user_id_on_create_only(): void
    {
        Functions\when('get_option')->justReturn(['store_messages' => '1']);
        Functions\when('get_current_user_id')->justReturn(5);

        $memory = new WpDbConversationMemory;
        $memory->set('conv-stamp', [
            'title' => 'Stamped',
            'history' => [],
        ]);

        $sql = $this->wpdb->queryLog[0] ?? '';

        self::assertStringContainsString('user_id', $sql);

        $updatePart = substr($sql, (int) strpos($sql, 'ON DUPLICATE KEY UPDATE'));
        self::assertStringNotContainsString('user_id', $updatePart);
    }

    public function test_get_denies_null_user_id_row_to_non_admin(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('get_current_user_id')->justReturn(5);

        $this->wpdb->nextGetRow = [
            'id' => 'legacy-conv',
            'namespace' => 'conversations',
            'title' => 'Pre-migration',
            'metadata' => null,
            'user_id' => null,
            'created_at' => '2025-01-01 00:00:00',
        ];

        $this->expectException(ConversationAccessDeniedException::class);

        $memory = new WpDbConversationMemory;
        $memory->get('legacy-conv', 'conversations');
    }
}

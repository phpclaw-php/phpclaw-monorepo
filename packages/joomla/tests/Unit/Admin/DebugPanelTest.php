<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Unit\Admin;

use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Joomla\Component\Administrator\Admin\DebugPanel;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PHPUnit\Framework\TestCase;

final class DebugPanelTest extends TestCase
{
    protected function setUp(): void
    {
        HookRegistry::reset();
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
    }

    public function test_send_returns_correct_shape(): void
    {
        $response = new AgentResponse(
            text: 'Hello from AI',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            iterations: 1,
            inputTokens: 100,
            outputTokens: 50,
        );

        $conversation = new Conversation(
            id: 'conv-123',
            history: [],
            createdAt: new \DateTimeImmutable,
        );

        $turn = new ConversationTurn(
            response: $response,
            conversation: $conversation,
        );

        $memory = $this->createMock(MemoryInterface::class);
        $memory->method('get')->willReturn(null);

        $engine = $this->createMock(ClawInterface::class);
        $engine->method('conversation')->willReturn($conversation);
        $engine->method('streamInConversation')->willReturnCallback(
            static function ($c, $m, $onToken, $beforePersist = null) use ($turn): ConversationTurn {
                if (is_callable($beforePersist)) {
                    $beforePersist(['history' => []]);
                }

                return $turn;
            },
        );
        $engine->method('memory')->willReturn($memory);

        $panel = new DebugPanel($engine);
        $output = $panel->send('test message');

        $this->assertArrayHasKey('text', $output);
        $this->assertArrayHasKey('provider', $output);
        $this->assertArrayHasKey('model', $output);
        $this->assertArrayHasKey('tokens', $output);
        $this->assertArrayHasKey('iterations', $output);
        $this->assertArrayHasKey('conversation_id', $output);
        $this->assertSame('Hello from AI', $output['text']);
        $this->assertSame('anthropic', $output['provider']);
        $this->assertSame(150, $output['tokens']);
        $this->assertSame('conv-123', $output['conversation_id']);
    }

    public function test_load_conversation_returns_empty_when_not_found(): void
    {
        $memory = $this->createMock(MemoryInterface::class);
        $memory->method('get')->with('conv-999', 'conversations')->willReturn(null);

        $engine = $this->createMock(ClawInterface::class);
        $engine->method('memory')->willReturn($memory);

        $panel = new DebugPanel($engine);
        $result = $panel->loadConversation('conv-999');

        $this->assertSame('conv-999', $result['id']);
        $this->assertSame('', $result['title']);
        $this->assertSame([], $result['messages']);
    }

    public function test_load_conversation_reads_history_key(): void
    {
        $stored = [
            'title' => 'Test Chat',
            'history' => [
                ['role' => 'user', 'content' => 'Hello'],
                ['role' => 'assistant', 'content' => 'Hi there'],
                ['role' => 'tool', 'content' => 'tool output', 'tool_name' => 'joomla_articles', 'tool_input' => ['limit' => 3]],
            ],
        ];

        $memory = $this->createMock(MemoryInterface::class);
        $memory->method('get')->with('conv-1', 'conversations')->willReturn($stored);

        $engine = $this->createMock(ClawInterface::class);
        $engine->method('memory')->willReturn($memory);

        $panel = new DebugPanel($engine);
        $result = $panel->loadConversation('conv-1');

        $this->assertSame('conv-1', $result['id']);
        $this->assertSame('Test Chat', $result['title']);
        $this->assertCount(3, $result['messages']);
    }

    public function test_load_conversation_includes_tool_rows_with_remapped_fields(): void
    {
        $stored = [
            'title' => 'Mixed',
            'messages' => [
                ['role' => 'user', 'content' => 'Hi'],
                ['role' => 'tool', 'content' => '{"articles":[]}', 'tool_name' => 'joomla_articles', 'tool_input' => ['limit' => 5]],
                ['role' => 'assistant', 'content' => 'Response'],
                ['role' => 'tool', 'content' => '{"categories":[]}', 'tool_name' => 'joomla_categories', 'tool_input' => ['limit' => 2]],
                ['role' => 'system', 'content' => 'discarded'],
            ],
        ];

        $memory = $this->createMock(MemoryInterface::class);
        $memory->method('get')->with('conv-2', 'conversations')->willReturn($stored);

        $engine = $this->createMock(ClawInterface::class);
        $engine->method('memory')->willReturn($memory);

        $panel = new DebugPanel($engine);
        $result = $panel->loadConversation('conv-2');

        $this->assertCount(4, $result['messages']);
        foreach ($result['messages'] as $msg) {
            $this->assertContains($msg['role'], ['user', 'assistant', 'tool']);
        }

        $tools = array_values(array_filter(
            $result['messages'],
            static fn (array $m): bool => $m['role'] === 'tool',
        ));
        $this->assertCount(2, $tools);
        $this->assertSame('joomla_articles', $tools[0]['tool_name']);
        $this->assertSame(['limit' => 5], $tools[0]['tool_input']);
        $this->assertSame('{"articles":[]}', $tools[0]['tool_result']);
        $this->assertSame('joomla_categories', $tools[1]['tool_name']);
        $this->assertSame('{"categories":[]}', $tools[1]['tool_result']);
    }

    public function test_send_returns_tool_calls_collected_from_hook(): void
    {
        $response = new AgentResponse(
            text: 'Found 2 articles',
            provider: 'ollama',
            model: 'qwen2.5:7b',
            iterations: 2,
            inputTokens: 200,
            outputTokens: 100,
        );

        $conversation = new Conversation(
            id: 'conv-tool-1',
            history: [],
            createdAt: new \DateTimeImmutable,
        );

        $turn = new ConversationTurn(response: $response, conversation: $conversation);

        $memory = $this->createMock(MemoryInterface::class);
        $memory->method('get')->willReturn(null);

        $engine = $this->createMock(ClawInterface::class);
        $engine->method('conversation')->willReturn($conversation);
        $engine->method('memory')->willReturn($memory);
        $engine->method('streamInConversation')->willReturnCallback(
            static function ($c, $m, $onToken, $beforePersist = null) use ($turn): ConversationTurn {
                HookRegistry::fire('tool.after', [
                    'tool_name' => 'joomla_articles',
                    'tool_input' => ['limit' => 2],
                    'tool_result' => '{"articles":[{"id":1},{"id":2}],"count":2}',
                ]);
                if (is_callable($beforePersist)) {
                    $beforePersist(['history' => []]);
                }

                return $turn;
            },
        );

        $panel = new DebugPanel($engine);
        $output = $panel->send('list 2 articles');

        $this->assertArrayHasKey('tool_calls', $output);
        $this->assertCount(1, $output['tool_calls']);
        $this->assertSame('joomla_articles', $output['tool_calls'][0]['tool_name']);
        $this->assertSame(['limit' => 2], $output['tool_calls'][0]['tool_input']);
        $this->assertStringContainsString('"count":2', $output['tool_calls'][0]['tool_result']);
    }

    public function test_stream_emits_done_frame_with_full_payload(): void
    {
        $response = new AgentResponse(
            text: 'Streamed response',
            provider: 'ollama',
            model: 'qwen2.5:7b',
            iterations: 1,
            inputTokens: 80,
            outputTokens: 40,
        );

        $conversation = new Conversation(
            id: 'conv-stream-1',
            history: [],
            createdAt: new \DateTimeImmutable,
        );

        $turn = new ConversationTurn(response: $response, conversation: $conversation);

        $memory = $this->createMock(MemoryInterface::class);
        $memory->method('get')->willReturn(null);

        $engine = $this->createMock(ClawInterface::class);
        $engine->method('conversation')->willReturn($conversation);
        $engine->method('memory')->willReturn($memory);
        $engine->method('streamInConversation')->willReturn($turn);

        $frames = [];
        $emit = static function (string $event, array $data) use (&$frames): void {
            $frames[] = ['event' => $event, 'data' => $data];
        };

        $panel = new DebugPanel($engine);
        $panel->stream('hi', '', $emit);

        $this->assertNotEmpty($frames);
        $last = end($frames);
        $this->assertSame('done', $last['event']);
        $this->assertSame('Streamed response', $last['data']['text']);
        $this->assertSame('ollama', $last['data']['provider']);
        $this->assertSame('qwen2.5:7b', $last['data']['model']);
        $this->assertSame(120, $last['data']['tokens']);
        $this->assertSame(1, $last['data']['iterations']);
        $this->assertSame('conv-stream-1', $last['data']['conversation_id']);
        $this->assertTrue($last['data']['is_new']);
        $this->assertSame([], $last['data']['tool_calls']);
    }

    public function test_stream_collects_tool_calls_and_emits_tool_after_frames(): void
    {
        $response = new AgentResponse(
            text: 'Done',
            provider: 'ollama',
            model: 'qwen2.5:7b',
            iterations: 2,
            inputTokens: 100,
            outputTokens: 50,
        );

        $conversation = new Conversation(
            id: 'conv-stream-2',
            history: [],
            createdAt: new \DateTimeImmutable,
        );

        $turn = new ConversationTurn(response: $response, conversation: $conversation);

        $memory = $this->createMock(MemoryInterface::class);
        $memory->method('get')->willReturn(null);

        $engine = $this->createMock(ClawInterface::class);
        $engine->method('conversation')->willReturn($conversation);
        $engine->method('memory')->willReturn($memory);
        $engine->method('streamInConversation')->willReturnCallback(
            static function () use ($turn): ConversationTurn {
                HookRegistry::fire('tool.before', [
                    'tool_name' => 'joomla_articles',
                    'tool_input' => ['limit' => 3],
                ]);
                HookRegistry::fire('tool.after', [
                    'tool_name' => 'joomla_articles',
                    'tool_input' => ['limit' => 3],
                    'tool_result' => '{"articles":[],"count":0}',
                ]);
                HookRegistry::fire('tool.after', [
                    'tool_name' => '',
                    'tool_input' => [],
                    'tool_result' => 'should be dropped',
                ]);

                return $turn;
            },
        );

        $frames = [];
        $emit = static function (string $event, array $data) use (&$frames): void {
            $frames[] = ['event' => $event, 'data' => $data];
        };

        $panel = new DebugPanel($engine);
        $panel->stream('list 3 articles', '', $emit);

        $events = array_column($frames, 'event');
        $this->assertContains('tool_before', $events);
        $this->assertContains('tool_after', $events);
        $this->assertContains('done', $events);

        $toolAfters = array_values(array_filter(
            $frames,
            static fn (array $f): bool => $f['event'] === 'tool_after',
        ));
        $this->assertCount(1, $toolAfters, 'empty tool_name must be dropped');
        $this->assertSame('joomla_articles', $toolAfters[0]['data']['tool_name']);

        $done = end($frames);
        $this->assertSame('done', $done['event']);
        $this->assertCount(1, $done['data']['tool_calls']);
        $this->assertSame('joomla_articles', $done['data']['tool_calls'][0]['tool_name']);
    }

    public function test_stream_splices_tool_rows_into_conversation_history(): void
    {
        $response = new AgentResponse(
            text: 'OK',
            provider: 'ollama',
            model: 'qwen2.5:7b',
            iterations: 2,
            inputTokens: 50,
            outputTokens: 25,
        );

        $conversation = new Conversation(
            id: 'conv-splice',
            history: [],
            createdAt: new \DateTimeImmutable,
        );

        $turn = new ConversationTurn(response: $response, conversation: $conversation);

        $stored = [
            'title' => 'Existing',
            'history' => [
                ['role' => 'user', 'content' => 'list 1 article'],
                ['role' => 'assistant', 'content' => 'OK'],
            ],
        ];

        $writtenValue = null;
        $memory = $this->createMock(MemoryInterface::class);
        $memory->method('get')->willReturn($stored);

        $engine = $this->createMock(ClawInterface::class);
        $engine->method('conversation')->willReturn($conversation);
        $engine->method('memory')->willReturn($memory);
        $engine->method('streamInConversation')->willReturnCallback(
            static function ($conv, $message, $onToken, $beforePersist = null) use ($turn, $stored, &$writtenValue): ConversationTurn {
                HookRegistry::fire('tool.after', [
                    'tool_name' => 'joomla_articles',
                    'tool_input' => ['limit' => 1],
                    'tool_result' => '{"articles":[{"id":1}]}',
                ]);
                $payload = $beforePersist !== null ? $beforePersist($stored) : $stored;
                $writtenValue = $payload;

                return $turn;
            },
        );

        $emit = static function (): void {};
        $panel = new DebugPanel($engine);
        $panel->stream('list 1 article', 'conv-splice', $emit);

        $this->assertIsArray($writtenValue);
        $this->assertArrayHasKey('history', $writtenValue);
        $this->assertCount(3, $writtenValue['history']);

        $roles = array_column($writtenValue['history'], 'role');
        $this->assertSame(['user', 'tool', 'assistant'], $roles);

        $toolRow = $writtenValue['history'][1];
        $this->assertSame('joomla_articles', $toolRow['tool_name']);
        $this->assertSame(['limit' => 1], $toolRow['tool_input']);
        $this->assertSame('{"articles":[{"id":1}]}', $toolRow['content']);
    }

    public function test_send_splices_tools_and_sets_title_when_new(): void
    {
        $response = new AgentResponse(
            text: 'Found 1',
            provider: 'ollama',
            model: 'qwen2.5:7b',
            iterations: 2,
            inputTokens: 50,
            outputTokens: 20,
        );

        $conversation = new Conversation(
            id: 'conv-send-new',
            history: [],
            createdAt: new \DateTimeImmutable,
        );

        $turn = new ConversationTurn(response: $response, conversation: $conversation);

        $stored = [
            'title' => '',
            'history' => [
                ['role' => 'user',      'content' => 'list 1 article'],
                ['role' => 'assistant', 'content' => 'Found 1'],
            ],
        ];

        $writtenValue = null;
        $memory = $this->createMock(MemoryInterface::class);
        $memory->method('get')->willReturn($stored);

        $engine = $this->createMock(ClawInterface::class);
        $engine->method('conversation')->willReturn($conversation);
        $engine->method('memory')->willReturn($memory);
        $engine->method('streamInConversation')->willReturnCallback(
            static function ($c, $m, $onToken, $beforePersist = null) use ($turn, $stored, &$writtenValue): ConversationTurn {
                HookRegistry::fire('tool.after', [
                    'tool_name' => 'joomla_articles',
                    'tool_input' => ['limit' => 1],
                    'tool_result' => '{"articles":[{"id":1}]}',
                ]);
                if (is_callable($beforePersist)) {
                    $writtenValue = $beforePersist($stored);
                }

                return $turn;
            },
        );

        $panel = new DebugPanel($engine);
        $panel->send('list 1 article');

        $this->assertIsArray($writtenValue);
        $this->assertSame('list 1 article', $writtenValue['title']);

        $roles = array_column($writtenValue['history'], 'role');
        $this->assertSame(['user', 'tool', 'assistant'], $roles);
    }

    public function test_send_truncates_long_title_with_ellipsis(): void
    {
        $longMessage = str_repeat('x', 80);

        $response = new AgentResponse(
            text: 'OK',
            provider: 'ollama',
            model: 'qwen2.5:7b',
            iterations: 1,
        );

        $conversation = new Conversation(
            id: 'conv-long',
            history: [],
            createdAt: new \DateTimeImmutable,
        );

        $turn = new ConversationTurn(response: $response, conversation: $conversation);

        $stored = ['title' => '', 'history' => []];

        $writtenValue = null;
        $memory = $this->createMock(MemoryInterface::class);
        $memory->method('get')->willReturn($stored);

        $engine = $this->createMock(ClawInterface::class);
        $engine->method('conversation')->willReturn($conversation);
        $engine->method('memory')->willReturn($memory);
        $engine->method('streamInConversation')->willReturnCallback(
            static function ($c, $m, $onToken, $beforePersist = null) use ($turn, $stored, &$writtenValue): ConversationTurn {
                if (is_callable($beforePersist)) {
                    $writtenValue = $beforePersist($stored);
                }

                return $turn;
            },
        );

        $panel = new DebugPanel($engine);
        $panel->send($longMessage);

        $this->assertSame(mb_substr($longMessage, 0, 60)."\u{2026}", $writtenValue['title']);
    }

    public function test_stream_emits_chunk_frame_on_provider_token(): void
    {
        $response = new AgentResponse(
            text: 'streamed',
            provider: 'ollama',
            model: 'qwen2.5:7b',
            iterations: 1,
        );

        $conversation = new Conversation(
            id: 'conv-token',
            history: [],
            createdAt: new \DateTimeImmutable,
        );

        $turn = new ConversationTurn(response: $response, conversation: $conversation);

        $memory = $this->createMock(MemoryInterface::class);
        $memory->method('get')->willReturn(null);

        $engine = $this->createMock(ClawInterface::class);
        $engine->method('conversation')->willReturn($conversation);
        $engine->method('memory')->willReturn($memory);
        $engine->method('streamInConversation')->willReturnCallback(
            static function () use ($turn): ConversationTurn {
                HookRegistry::fire('provider.token', ['token' => 'Hel']);
                HookRegistry::fire('provider.token', ['token' => 'lo!']);
                HookRegistry::fire('provider.token', ['token' => '']);

                return $turn;
            },
        );

        $frames = [];
        $emit = static function (string $event, array $data) use (&$frames): void {
            $frames[] = ['event' => $event, 'data' => $data];
        };

        $panel = new DebugPanel($engine);
        $panel->stream('hi', '', $emit);

        $chunks = array_values(array_filter(
            $frames,
            static fn (array $f): bool => $f['event'] === 'chunk',
        ));

        $this->assertCount(2, $chunks, 'empty tokens must be dropped');
        $this->assertSame('Hel', $chunks[0]['data']['text']);
        $this->assertSame('lo!', $chunks[1]['data']['text']);
    }

    public function test_stream_sets_title_in_before_persist_when_is_new(): void
    {
        $response = new AgentResponse(text: 'OK', provider: 'ollama', model: 'qwen2.5:7b', iterations: 1);
        $conversation = new Conversation(id: 'conv-isnew', history: [], createdAt: new \DateTimeImmutable);
        $turn = new ConversationTurn(response: $response, conversation: $conversation);

        $memory = $this->createMock(MemoryInterface::class);
        $memory->method('get')->willReturn(null);

        $engine = $this->createMock(ClawInterface::class);
        $engine->method('conversation')->willReturn($conversation);
        $engine->method('memory')->willReturn($memory);

        $persistedTitle = null;
        $engine->method('streamInConversation')->willReturnCallback(
            static function ($c, $m, $onToken, $beforePersist = null) use ($turn, &$persistedTitle): ConversationTurn {
                if ($beforePersist !== null) {
                    $payload = $beforePersist(['history' => []]);
                    $persistedTitle = $payload['title'] ?? null;
                }

                return $turn;
            },
        );

        $panel = new DebugPanel($engine);
        $emit = static function (): void {};
        $panel->stream('short title', '', $emit);

        $this->assertSame('short title', $persistedTitle);

        $persistedTitle = null;
        $longMsg = str_repeat('y', 80);
        $panel->stream($longMsg, '', $emit);
        $this->assertSame(mb_substr($longMsg, 0, 60)."\u{2026}", $persistedTitle);
    }

    public function test_find_last_assistant_index_returns_inner_assistant_index(): void
    {
        $memory = $this->createMock(MemoryInterface::class);
        $engine = $this->createMock(ClawInterface::class);
        $engine->method('memory')->willReturn($memory);

        $panel = new DebugPanel($engine);
        $ref = new \ReflectionMethod(DebugPanel::class, 'findLastAssistantIndex');
        $ref->setAccessible(true);

        $messages = [
            ['role' => 'user',      'content' => 'a'],
            ['role' => 'assistant', 'content' => 'b'],
            ['role' => 'user',      'content' => 'c'],
        ];

        $this->assertSame(1, $ref->invoke($panel, $messages));

        $this->assertSame(0, $ref->invoke($panel, []));
        $this->assertSame(2, $ref->invoke($panel, [
            ['role' => 'user', 'content' => 'x'],
            ['role' => 'user', 'content' => 'y'],
        ]));
    }
}

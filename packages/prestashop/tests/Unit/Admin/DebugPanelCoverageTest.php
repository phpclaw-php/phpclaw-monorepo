<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Admin;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\PrestaShop\Admin\DebugPanel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DebugPanel::class)]
final class DebugPanelCoverageTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private function makeResponse(string $text = 'ok'): AgentResponse
    {
        return new AgentResponse(
            text: $text,
            provider: 'openai',
            model: 'gpt-4o-mini',
            iterations: 1,
            inputTokens: 5,
            outputTokens: 10,
        );
    }

    public function test_list_conversations_returns_empty_on_memory_exception(): void
    {
        $engine = \Mockery::mock(ClawInterface::class);
        $engine->allows('memory')->andThrow(new \RuntimeException('memory failure'));

        $panel = new DebugPanel($engine);

        self::assertSame([], $panel->listConversations());
    }

    public function test_list_conversations_returns_empty_when_all_returns_non_array(): void
    {
        $memory = new ArrayMemory;
        $engine = \Mockery::mock(ClawInterface::class);
        $engine->allows('memory')->andReturn($memory);

        $panel = new DebugPanel($engine);

        self::assertSame([], $panel->listConversations());
    }

    public function test_list_conversations_skips_non_array_entries(): void
    {
        $memory = new ArrayMemory;
        $memory->set('conv-ok', ['title' => 'Good', 'updated_at' => '2026-06-01 10:00:00'], 'conversations');
        $memory->set('conv-bad', 'not-an-array', 'conversations');

        $engine = \Mockery::mock(ClawInterface::class);
        $engine->allows('memory')->andReturn($memory);

        $panel = new DebugPanel($engine);
        $list = $panel->listConversations();

        self::assertCount(1, $list);
        self::assertSame('conv-ok', $list[0]['id']);
    }

    public function test_list_conversations_sorts_by_time_desc(): void
    {
        $memory = new ArrayMemory;
        $memory->set('conv-old', ['title' => 'Old', 'updated_at' => '2026-01-01 00:00:00'], 'conversations');
        $memory->set('conv-new', ['title' => 'New', 'updated_at' => '2026-06-01 12:00:00'], 'conversations');
        $memory->set('conv-mid', ['title' => 'Mid', 'updated_at' => '2026-03-15 06:00:00'], 'conversations');

        $engine = \Mockery::mock(ClawInterface::class);
        $engine->allows('memory')->andReturn($memory);

        $panel = new DebugPanel($engine);
        $list = $panel->listConversations();

        self::assertCount(3, $list);
        self::assertSame('conv-new', $list[0]['id']);
        self::assertSame('conv-mid', $list[1]['id']);
        self::assertSame('conv-old', $list[2]['id']);
    }

    public function test_list_conversations_uses_created_at_when_updated_at_absent(): void
    {
        $memory = new ArrayMemory;
        $memory->set('conv-1', ['title' => 'Only Created', 'created_at' => '2026-06-10 08:00:00'], 'conversations');

        $engine = \Mockery::mock(ClawInterface::class);
        $engine->allows('memory')->andReturn($memory);

        $panel = new DebugPanel($engine);
        $list = $panel->listConversations();

        self::assertCount(1, $list);
        self::assertSame('2026-06-10 08:00:00', $list[0]['time']);
    }

    public function test_list_conversations_returns_empty_time_when_no_timestamps(): void
    {
        $memory = new ArrayMemory;
        $memory->set('conv-notimestamp', ['title' => 'No time'], 'conversations');

        $engine = \Mockery::mock(ClawInterface::class);
        $engine->allows('memory')->andReturn($memory);

        $panel = new DebugPanel($engine);
        $list = $panel->listConversations();

        self::assertCount(1, $list);
        self::assertSame('', $list[0]['time']);
    }

    public function test_list_conversations_empty_title_defaults_to_empty_string(): void
    {
        $memory = new ArrayMemory;
        $memory->set('conv-notitle', ['updated_at' => '2026-06-01'], 'conversations');

        $engine = \Mockery::mock(ClawInterface::class);
        $engine->allows('memory')->andReturn($memory);

        $panel = new DebugPanel($engine);
        $list = $panel->listConversations();

        self::assertSame('', $list[0]['title']);
    }

    public function test_build_memory_callback_sets_title_on_new_conversation(): void
    {
        $response = $this->makeResponse('done');
        $turn = new ConversationTurn($response, Conversation::start());
        $message = 'What is the total revenue this month?';

        $engine = \Mockery::mock(ClawInterface::class);
        $engine->allows('conversation')->andReturn(Conversation::start());
        $engine->expects('streamInConversation')->once()->andReturnUsing(
            static function ($conv, $sent, $tokenCb, $persistCb) use ($turn, $message): ConversationTurn {
                self::assertSame($message, $persistCb(['history' => []])['title']);

                return $turn;
            }
        );

        $events = [];
        (new DebugPanel($engine))->stream(
            $message,
            '',
            static function (string $event, array $data) use (&$events): void {
                $events[] = $event;
            },
        );

        self::assertContains('done', $events);
    }

    public function test_build_memory_callback_truncates_long_message_title(): void
    {
        $response = $this->makeResponse('done');
        $turn = new ConversationTurn($response, Conversation::start());

        $engine = \Mockery::mock(ClawInterface::class);
        $engine->allows('conversation')->andReturn(Conversation::start());
        $engine->expects('streamInConversation')->once()->andReturnUsing(
            static function ($conv, $message, $tokenCb, $persistCb) use ($turn): ConversationTurn {
                $result = $persistCb(['history' => []]);
                self::assertStringContainsString("\u{2026}", $result['title']);

                return $turn;
            }
        );

        (new DebugPanel($engine))->stream(
            str_repeat('X', 100),
            '',
            static function (): void {},
        );
    }

    public function test_build_memory_callback_no_truncation_for_short_message(): void
    {
        $response = $this->makeResponse('done');
        $turn = new ConversationTurn($response, Conversation::start());
        $shortMsg = 'Short message';

        $engine = \Mockery::mock(ClawInterface::class);
        $engine->allows('conversation')->andReturn(Conversation::start());
        $engine->expects('streamInConversation')->once()->andReturnUsing(
            static function ($conv, $message, $tokenCb, $persistCb) use ($turn, $shortMsg): ConversationTurn {
                $result = $persistCb(['history' => []]);
                self::assertSame($shortMsg, $result['title']);

                return $turn;
            }
        );

        (new DebugPanel($engine))->stream(
            $shortMsg,
            '',
            static function (): void {},
        );
    }

    public function test_build_memory_callback_not_called_on_existing_conversation(): void
    {
        $response = $this->makeResponse('done');
        $turn = new ConversationTurn($response, Conversation::start());

        $engine = \Mockery::mock(ClawInterface::class);
        $engine->allows('conversation')->andReturn(Conversation::start());
        $engine->expects('streamInConversation')->once()->andReturnUsing(
            static function ($conv, $message, $tokenCb, $persistCb) use ($turn): ConversationTurn {
                $result = $persistCb(['history' => [], 'title' => 'existing title']);
                self::assertSame('existing title', $result['title'] ?? '');

                return $turn;
            }
        );

        (new DebugPanel($engine))->stream(
            'some message',
            'existing-conv-id',
            static function (): void {},
        );
    }

    public function test_splice_tool_calls_into_history_with_empty_tool_calls(): void
    {
        $response = $this->makeResponse();
        $turn = new ConversationTurn($response, Conversation::start());

        $engine = \Mockery::mock(ClawInterface::class);
        $engine->allows('conversation')->andReturn(Conversation::start());
        $engine->expects('streamInConversation')->once()->andReturnUsing(
            static function ($conv, $msg, $tokenCb, $persistCb) use ($turn): ConversationTurn {
                $payload = $persistCb(['history' => [
                    ['role' => 'user',      'content' => 'hello'],
                    ['role' => 'assistant', 'content' => 'world'],
                ]]);
                self::assertCount(2, $payload['history']);

                return $turn;
            }
        );

        (new DebugPanel($engine))->stream('hello', 'existing', static function (): void {});
    }

    public function test_send_with_no_tool_calls_returns_an_empty_tool_call_list(): void
    {
        $response = $this->makeResponse('Products found.');
        $conv = Conversation::start();
        $turn = new ConversationTurn($response, $conv);

        $engine = \Mockery::mock(ClawInterface::class);
        $engine->allows('conversation')->andReturn($conv);
        $engine->expects('streamInConversation')->once()->andReturnUsing(
            static function ($c, $m, $tokenCb, $persistCb) use ($turn): ConversationTurn {
                $persistCb([]);

                return $turn;
            }
        );

        $result = (new DebugPanel($engine))->send('List top products');

        self::assertSame([], $result['tool_calls']);
    }

    public function test_load_conversation_handles_non_array_history_items(): void
    {
        $memory = new ArrayMemory;
        $memory->set('conv-mixed', [
            'title' => 'Mixed',
            'history' => [
                'not-an-array',
                ['role' => 'user', 'content' => 'hello'],
                null,
            ],
        ], 'conversations');

        $engine = \Mockery::mock(ClawInterface::class);
        $engine->expects('memory')->once()->andReturn($memory);

        $result = (new DebugPanel($engine))->loadConversation('conv-mixed');

        self::assertCount(1, $result['messages']);
        self::assertSame('user', $result['messages'][0]['role']);
    }

    public function test_find_last_assistant_index_returns_length_when_no_assistant(): void
    {
        $engine = \Mockery::mock(ClawInterface::class);
        $panel = new DebugPanel($engine);

        $ref = new \ReflectionMethod(DebugPanel::class, 'findLastAssistantIndex');
        $ref->setAccessible(true);

        $history = [
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'tool', 'content' => 'result'],
        ];

        self::assertSame(2, $ref->invoke($panel, $history));
    }

    public function test_find_last_assistant_index_returns_correct_index(): void
    {
        $engine = \Mockery::mock(ClawInterface::class);
        $panel = new DebugPanel($engine);

        $ref = new \ReflectionMethod(DebugPanel::class, 'findLastAssistantIndex');
        $ref->setAccessible(true);

        $history = [
            ['role' => 'user',      'content' => 'Q'],
            ['role' => 'assistant', 'content' => 'A1'],
            ['role' => 'user',      'content' => 'Q2'],
            ['role' => 'assistant', 'content' => 'A2'],
        ];

        self::assertSame(3, $ref->invoke($panel, $history));
    }
}

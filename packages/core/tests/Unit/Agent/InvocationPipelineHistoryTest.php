<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Agent;

use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\InvocationPipeline;
use PhpClaw\Agent\Message;
use PhpClaw\Agent\MessageAugmenter;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Memory\ArrayMemory;
use PHPUnit\Framework\TestCase;

final class InvocationPipelineHistoryTest extends TestCase
{
    protected function setUp(): void
    {
        HookRegistry::reset();
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
    }

    public function test_a_saved_tool_row_without_a_call_id_is_not_sent_to_the_agent(): void
    {
        $sent = $this->sendAfter([
            Message::user('list routes'),
            Message::fromArray(['role' => 'tool', 'content' => 'GET /', 'tool_name' => 'route_list', 'tool_input' => []]),
            Message::assistant('One route.'),
        ]);

        $this->assertSame([['user', 'list routes'], ['assistant', 'One route.']], $sent);
    }

    public function test_a_tool_result_with_a_call_id_is_sent_to_the_agent(): void
    {
        $sent = $this->sendAfter([
            Message::user('list routes'),
            Message::toolUse('call_1', 'route_list', []),
            Message::toolResult('call_1', 'route_list', 'GET /'),
            Message::assistant('One route.'),
        ]);

        $this->assertSame([['user', 'list routes'], ['assistant', ''], ['tool', 'GET /'], ['assistant', 'One route.']], $sent);
    }

    public function test_a_history_without_tool_rows_is_sent_unchanged(): void
    {
        $sent = $this->sendAfter([Message::user('hello'), Message::assistant('Hi.')]);

        $this->assertSame([['user', 'hello'], ['assistant', 'Hi.']], $sent);
    }

    public function test_the_saved_tool_row_stays_in_the_stored_conversation(): void
    {
        $memory = new ArrayMemory;
        $conversation = new Conversation('conv_1', [
            Message::user('list routes'),
            Message::fromArray(['role' => 'tool', 'content' => 'GET /', 'tool_name' => 'route_list', 'tool_input' => []]),
            Message::assistant('One route.'),
        ], new \DateTimeImmutable);

        (new InvocationPipeline(new MessageAugmenter(null), $memory))->executeInConversation(
            $conversation,
            'thanks',
            false,
            null,
            static fn (): AgentResponse => new AgentResponse(text: 'Welcome.', provider: 'test', model: 'test', iterations: 1),
        );

        $stored = $memory->get('conv_1', Conversation::MEMORY_NAMESPACE);
        $this->assertSame(['user', 'tool', 'assistant', 'user', 'assistant'], array_column($stored['history'], 'role'));
    }

    private function sendAfter(array $history): array
    {
        $sent = [];
        $conversation = new Conversation('conv_1', $history, new \DateTimeImmutable);

        (new InvocationPipeline(new MessageAugmenter(null), null))->executeInConversation(
            $conversation,
            'thanks',
            false,
            null,
            static function (string $augmented, array $history) use (&$sent): AgentResponse {
                $sent = array_map(static fn (Message $message): array => [$message->role, $message->content], $history);

                return new AgentResponse(text: 'Welcome.', provider: 'test', model: 'test', iterations: 1);
            },
        );

        return $sent;
    }
}

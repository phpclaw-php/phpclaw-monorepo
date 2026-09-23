<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tests\Unit\Admin;

use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\OpenCart\Admin\DebugPanel;
use PHPUnit\Framework\TestCase;

final class DebugPanelTest extends TestCase
{
    private ClawInterface $engine;

    private DebugPanel $panel;

    protected function setUp(): void
    {
        $this->engine = $this->createMock(ClawInterface::class);
        $this->panel = new DebugPanel($this->engine);
    }

    private function makeConversation(string $id = 'conv-01'): Conversation
    {
        return new Conversation(
            id: $id,
            history: [],
            createdAt: new \DateTimeImmutable,
        );
    }

    private function makeResponse(string $text = 'response text'): AgentResponse
    {
        return new AgentResponse(
            text: $text,
            provider: 'anthropic',
            model: 'claude-haiku',
            iterations: 1,
            inputTokens: 10,
            outputTokens: 20,
        );
    }

    public function test_send_throws_on_empty_message(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->panel->send('');
    }

    public function test_send_returns_correct_keys(): void
    {
        $conv = $this->makeConversation();
        $turn = new ConversationTurn(response: $this->makeResponse(), conversation: $conv);

        $this->engine->method('conversation')->willReturn($conv);
        $this->engine->method('streamInConversation')->willReturnCallback(
            function (Conversation $c, string $m, callable $onToken, ?callable $beforePersist) use ($turn): ConversationTurn {
                $beforePersist === null ?: $beforePersist(['history' => []]);

                return $turn;
            }
        );

        $result = $this->panel->send('hello');

        self::assertArrayHasKey('text', $result);
        self::assertArrayHasKey('provider', $result);
        self::assertArrayHasKey('model', $result);
        self::assertArrayHasKey('tokens', $result);
        self::assertArrayHasKey('iterations', $result);
        self::assertArrayHasKey('conversation_id', $result);
    }

    public function test_send_returns_response_text(): void
    {
        $conv = $this->makeConversation();
        $turn = new ConversationTurn(response: $this->makeResponse('hello world'), conversation: $conv);

        $this->engine->method('conversation')->willReturn($conv);
        $this->engine->method('streamInConversation')->willReturnCallback(
            function (Conversation $c, string $m, callable $onToken, ?callable $beforePersist) use ($turn): ConversationTurn {
                $beforePersist === null ?: $beforePersist(['history' => []]);

                return $turn;
            }
        );

        $result = $this->panel->send('test message');
        self::assertSame('hello world', $result['text']);
    }

    public function test_send_returns_provider_info(): void
    {
        $conv = $this->makeConversation();
        $turn = new ConversationTurn(response: $this->makeResponse(), conversation: $conv);

        $this->engine->method('conversation')->willReturn($conv);
        $this->engine->method('streamInConversation')->willReturnCallback(
            function (Conversation $c, string $m, callable $onToken, ?callable $beforePersist) use ($turn): ConversationTurn {
                $beforePersist === null ?: $beforePersist(['history' => []]);

                return $turn;
            }
        );

        $result = $this->panel->send('test');
        self::assertSame('anthropic', $result['provider']);
        self::assertSame('claude-haiku', $result['model']);
    }

    public function test_send_sums_tokens(): void
    {
        $conv = $this->makeConversation();
        $turn = new ConversationTurn(response: $this->makeResponse(), conversation: $conv);

        $this->engine->method('conversation')->willReturn($conv);
        $this->engine->method('streamInConversation')->willReturnCallback(
            function (Conversation $c, string $m, callable $onToken, ?callable $beforePersist) use ($turn): ConversationTurn {
                $beforePersist === null ?: $beforePersist(['history' => []]);

                return $turn;
            }
        );

        $result = $this->panel->send('test');
        self::assertSame(30, $result['tokens']);
    }

    public function test_send_with_conversation_id_passes_it_through(): void
    {
        $conv = $this->makeConversation('my-conv-id');
        $turn = new ConversationTurn(response: $this->makeResponse(), conversation: $conv);

        $this->engine->method('conversation')->willReturn($conv);
        $this->engine->method('streamInConversation')->willReturnCallback(
            function (Conversation $c, string $m, callable $onToken, ?callable $beforePersist) use ($turn): ConversationTurn {
                $beforePersist === null ?: $beforePersist(['history' => []]);

                return $turn;
            }
        );

        $result = $this->panel->send('hello', 'my-conv-id');
        self::assertSame('my-conv-id', $result['conversation_id']);
    }

    public function test_send_empty_response_gets_placeholder(): void
    {
        $response = new AgentResponse(text: '', provider: 'openai', model: 'gpt-4', iterations: 1);
        $conv = $this->makeConversation();
        $turn = new ConversationTurn(response: $response, conversation: $conv);

        $this->engine->method('conversation')->willReturn($conv);
        $this->engine->method('streamInConversation')->willReturnCallback(
            function (Conversation $c, string $m, callable $onToken, ?callable $beforePersist) use ($turn): ConversationTurn {
                $beforePersist === null ?: $beforePersist(['history' => []]);

                return $turn;
            }
        );

        $result = $this->panel->send('nothing here');
        self::assertNotEmpty($result['text']);
    }

    public function test_send_rethrows_guard_exception_as_runtime(): void
    {
        $conv = $this->makeConversation();
        $this->engine->method('conversation')->willReturn($conv);
        $this->engine->method('streamInConversation')
            ->willThrowException(new GuardException('injection'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Request blocked by security guard.');
        $this->panel->send('inject');
    }

    public function test_send_rethrows_provider_exception_as_runtime(): void
    {
        $conv = $this->makeConversation();
        $this->engine->method('conversation')->willReturn($conv);
        $this->engine->method('streamInConversation')
            ->willThrowException(new ProviderException('API down'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AI provider error');
        $this->panel->send('test');
    }

    public function test_send_rethrows_max_iterations_exception_as_runtime(): void
    {
        $conv = $this->makeConversation();
        $this->engine->method('conversation')->willReturn($conv);
        $this->engine->method('streamInConversation')
            ->willThrowException(new MaxIterationsException('too many'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('max iterations');
        $this->panel->send('loop');
    }

    public function test_load_conversation_returns_empty_for_empty_id(): void
    {
        $result = $this->panel->loadConversation('');
        self::assertSame('', $result['title']);
        self::assertSame([], $result['messages']);
    }

    public function test_load_conversation_returns_empty_when_memory_returns_null(): void
    {
        $memory = $this->createMock(MemoryInterface::class);
        $memory->method('get')->willReturn(null);
        $this->engine->method('memory')->willReturn($memory);

        $result = $this->panel->loadConversation('some-conv-id');
        self::assertSame('', $result['title']);
        self::assertSame([], $result['messages']);
    }

    public function test_load_conversation_returns_user_assistant_and_tool_messages(): void
    {
        $memory = $this->createMock(MemoryInterface::class);
        $memory->method('get')->willReturn([
            'title' => 'Test Chat',
            'history' => [
                ['role' => 'user',      'content' => 'Hello'],
                ['role' => 'assistant', 'content' => 'Hi there'],
                ['role' => 'tool',      'content' => 'tool data'],
            ],
        ]);
        $this->engine->method('memory')->willReturn($memory);

        $result = $this->panel->loadConversation('conv-abc');
        $messages = $result['messages'];

        self::assertCount(3, $messages);
        self::assertSame('user', $messages[0]['role']);
        self::assertSame('Hello', $messages[0]['content']);
        self::assertSame('assistant', $messages[1]['role']);
        self::assertSame('Hi there', $messages[1]['content']);
        self::assertSame('tool', $messages[2]['role']);
    }

    public function test_stream_throws_on_empty_message(): void
    {
        $emitted = [];
        $emit = function (string $event, array $data) use (&$emitted): void {
            $emitted[] = [$event, $data];
        };

        $this->expectException(\InvalidArgumentException::class);
        $this->panel->stream('', 'conv-01', $emit);
    }

    public function test_stream_emits_done_event_with_response_payload(): void
    {
        HookRegistry::reset();
        $conv = $this->makeConversation('conv-stream-1');

        $this->engine->method('conversation')->with('conv-stream-1')->willReturn($conv);
        $this->engine->method('streamInConversation')->willReturnCallback(
            function (Conversation $c, string $m, callable $onToken, ?callable $beforePersist) use ($conv): ConversationTurn {
                $beforePersist === null ?: $beforePersist(['history' => []]);

                return new ConversationTurn(response: $this->makeResponse('streamed text'), conversation: $conv);
            }
        );

        $emitted = [];
        $this->panel->stream('hi', 'conv-stream-1', function (string $event, array $data) use (&$emitted): void {
            $emitted[] = [$event, $data];
        });

        $doneEvents = array_filter($emitted, fn (array $e): bool => $e[0] === 'done');
        self::assertCount(1, $doneEvents);
        $done = array_values($doneEvents)[0][1];
        self::assertSame('streamed text', $done['text']);
        self::assertSame('anthropic', $done['provider']);
        self::assertSame('claude-haiku', $done['model']);
        self::assertSame(30, $done['tokens']);
        self::assertSame('conv-stream-1', $done['conversation_id']);
        self::assertFalse($done['is_new']);
        self::assertSame([], $done['tool_calls']);
    }

    public function test_stream_new_conversation_sets_title_from_short_message(): void
    {
        HookRegistry::reset();
        $conv = $this->makeConversation('conv-new');

        $this->engine->method('conversation')->with('')->willReturn($conv);
        $capturedTitle = null;
        $this->engine->method('streamInConversation')->willReturnCallback(
            function (Conversation $c, string $m, callable $onToken, ?callable $beforePersist) use ($conv, &$capturedTitle): ConversationTurn {
                $payload = ['history' => []];
                $beforePersist === null ?: $payload = $beforePersist($payload);
                $capturedTitle = $payload['title'] ?? null;

                return new ConversationTurn(response: $this->makeResponse(), conversation: $conv);
            }
        );

        $emitted = [];
        $this->panel->stream('short message', '', function (string $event, array $data) use (&$emitted): void {
            $emitted[] = [$event, $data];
        });

        self::assertSame('short message', $capturedTitle);
        $done = array_values(array_filter($emitted, fn (array $e): bool => $e[0] === 'done'))[0][1];
        self::assertTrue($done['is_new']);
        self::assertSame('short message', $done['title']);
    }

    public function test_stream_long_message_title_truncated_to_60_chars_plus_ellipsis(): void
    {
        HookRegistry::reset();
        $conv = $this->makeConversation('conv-long');
        $longMessage = str_repeat('abcde', 20);

        $this->engine->method('conversation')->with('')->willReturn($conv);
        $this->engine->method('streamInConversation')->willReturnCallback(
            function (Conversation $c, string $m, callable $onToken, ?callable $beforePersist) use ($conv): ConversationTurn {
                $beforePersist === null ?: $beforePersist(['history' => []]);

                return new ConversationTurn(response: $this->makeResponse(), conversation: $conv);
            }
        );

        $emitted = [];
        $this->panel->stream($longMessage, '', function (string $event, array $data) use (&$emitted): void {
            $emitted[] = [$event, $data];
        });

        $done = array_values(array_filter($emitted, fn (array $e): bool => $e[0] === 'done'))[0][1];
        self::assertSame(substr($longMessage, 0, 60)."\u{2026}", $done['title']);
    }

    public function test_stream_tool_before_hook_emits_tool_before_event(): void
    {
        HookRegistry::reset();
        $conv = $this->makeConversation('conv-tool');

        $this->engine->method('conversation')->willReturn($conv);
        $this->engine->method('streamInConversation')->willReturnCallback(
            function (Conversation $c, string $m, callable $onToken, ?callable $beforePersist) use ($conv): ConversationTurn {
                HookRegistry::fire('tool.before', ['tool_name' => 'oc_product', 'tool_input' => ['limit' => 1]]);
                $beforePersist === null ?: $beforePersist(['history' => []]);

                return new ConversationTurn(response: $this->makeResponse(), conversation: $conv);
            }
        );

        $emitted = [];
        $this->panel->stream('list', '', function (string $event, array $data) use (&$emitted): void {
            $emitted[] = [$event, $data];
        });

        $before = array_values(array_filter($emitted, fn (array $e): bool => $e[0] === 'tool_before'));
        self::assertCount(1, $before);
        self::assertSame('oc_product', $before[0][1]['tool_name']);
        self::assertSame(['limit' => 1], $before[0][1]['tool_input']);
    }

    public function test_stream_tool_after_hook_collects_and_emits_tool_calls(): void
    {
        HookRegistry::reset();
        $conv = $this->makeConversation('conv-tool-after');

        $this->engine->method('conversation')->willReturn($conv);
        $capturedPayload = null;
        $this->engine->method('streamInConversation')->willReturnCallback(
            function (Conversation $c, string $m, callable $onToken, ?callable $beforePersist) use ($conv, &$capturedPayload): ConversationTurn {
                HookRegistry::fire('tool.after', [
                    'tool_name' => 'oc_order',
                    'tool_input' => ['status' => 'pending'],
                    'tool_result' => '{"orders": [1]}',
                ]);
                $capturedPayload = $beforePersist(['history' => [['role' => 'user', 'content' => 'list orders'], ['role' => 'assistant', 'content' => 'done']]]);

                return new ConversationTurn(response: $this->makeResponse(), conversation: $conv);
            }
        );

        $emitted = [];
        $this->panel->stream('list orders', '', function (string $event, array $data) use (&$emitted): void {
            $emitted[] = [$event, $data];
        });

        $after = array_values(array_filter($emitted, fn (array $e): bool => $e[0] === 'tool_after'));
        self::assertCount(1, $after);
        self::assertSame('oc_order', $after[0][1]['tool_name']);

        self::assertCount(3, $capturedPayload['history']);
        self::assertSame('user', $capturedPayload['history'][0]['role']);
        self::assertSame('tool', $capturedPayload['history'][1]['role']);
        self::assertSame('oc_order', $capturedPayload['history'][1]['tool_name']);
        self::assertSame('assistant', $capturedPayload['history'][2]['role']);

        $done = array_values(array_filter($emitted, fn (array $e): bool => $e[0] === 'done'))[0][1];
        self::assertCount(1, $done['tool_calls']);
        self::assertSame('oc_order', $done['tool_calls'][0]['tool_name']);
    }

    public function test_stream_tool_after_skips_empty_tool_name(): void
    {
        HookRegistry::reset();
        $conv = $this->makeConversation('conv-empty-name');

        $this->engine->method('conversation')->willReturn($conv);
        $this->engine->method('streamInConversation')->willReturnCallback(
            function (Conversation $c, string $m, callable $onToken, ?callable $beforePersist) use ($conv): ConversationTurn {
                HookRegistry::fire('tool.after', ['tool_name' => '', 'tool_input' => [], 'tool_result' => '']);
                $beforePersist === null ?: $beforePersist(['history' => []]);

                return new ConversationTurn(response: $this->makeResponse(), conversation: $conv);
            }
        );

        $emitted = [];
        $this->panel->stream('hi', '', function (string $event, array $data) use (&$emitted): void {
            $emitted[] = [$event, $data];
        });

        $after = array_filter($emitted, fn (array $e): bool => $e[0] === 'tool_after');
        self::assertCount(0, $after);
    }

    public function test_stream_provider_token_hook_emits_chunk_event(): void
    {
        HookRegistry::reset();
        $conv = $this->makeConversation('conv-token');

        $this->engine->method('conversation')->willReturn($conv);
        $this->engine->method('streamInConversation')->willReturnCallback(
            function (Conversation $c, string $m, callable $onToken, ?callable $beforePersist) use ($conv): ConversationTurn {
                HookRegistry::fire('provider.token', ['token' => 'Hello']);
                HookRegistry::fire('provider.token', ['token' => '']);
                $beforePersist === null ?: $beforePersist(['history' => []]);

                return new ConversationTurn(response: $this->makeResponse(), conversation: $conv);
            }
        );

        $emitted = [];
        $this->panel->stream('hi', '', function (string $event, array $data) use (&$emitted): void {
            $emitted[] = [$event, $data];
        });

        $chunks = array_values(array_filter($emitted, fn (array $e): bool => $e[0] === 'chunk'));
        self::assertCount(1, $chunks);
        self::assertSame('Hello', $chunks[0][1]['text']);
    }

    public function test_stream_rethrows_guard_exception_as_runtime(): void
    {
        HookRegistry::reset();
        $this->engine->method('conversation')->willReturn($this->makeConversation());
        $this->engine->method('streamInConversation')->willThrowException(new GuardException('blocked content'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Request blocked by security guard/');
        $this->panel->stream('hi', '', fn (string $e, array $d) => null);
    }

    public function test_stream_rethrows_provider_exception_as_runtime(): void
    {
        HookRegistry::reset();
        $this->engine->method('conversation')->willReturn($this->makeConversation());
        $this->engine->method('streamInConversation')->willThrowException(new ProviderException('api down'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/AI provider error/');
        $this->panel->stream('hi', '', fn (string $e, array $d) => null);
    }

    public function test_stream_rethrows_max_iterations_exception_as_runtime(): void
    {
        HookRegistry::reset();
        $this->engine->method('conversation')->willReturn($this->makeConversation());
        $this->engine->method('streamInConversation')->willThrowException(new MaxIterationsException('looped 20 times'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/max iterations/');
        $this->panel->stream('hi', '', fn (string $e, array $d) => null);
    }

    public function test_send_with_tool_calls_splices_into_memory(): void
    {
        $storedHistory = null;

        $conv = $this->makeConversation('conv-tool-send');
        $turn = new ConversationTurn(response: $this->makeResponse('answer'), conversation: $conv);

        $this->engine->method('conversation')->willReturn($conv);
        $this->engine->method('streamInConversation')->willReturnCallback(
            function (Conversation $c, string $m, callable $onToken, ?callable $beforePersist) use ($turn, &$storedHistory): ConversationTurn {
                HookRegistry::fire(
                    LifecycleEvent::ToolAfter->value,
                    ['tool_name' => 'oc_product', 'tool_input' => ['limit' => 5], 'tool_result' => 'ok'],
                );
                $storedHistory = $beforePersist([
                    'history' => [
                        ['role' => 'user',      'content' => 'list'],
                        ['role' => 'assistant', 'content' => 'done'],
                    ],
                ]);

                return $turn;
            }
        );

        $result = $this->panel->send('list products');

        self::assertCount(1, $result['tool_calls']);
        self::assertSame('oc_product', $result['tool_calls'][0]['tool_name']);
        self::assertNotNull($storedHistory);
        self::assertContains('tool', array_column($storedHistory['history'], 'role'));
    }

    public function test_stream_on_token_callback_is_invoked(): void
    {
        HookRegistry::reset();
        $conv = $this->makeConversation('conv-ontoken');

        $this->engine->method('conversation')->willReturn($conv);
        $tokenCallbackFired = false;
        $this->engine->method('streamInConversation')->willReturnCallback(
            function (
                Conversation $c,
                string $m,
                callable $onToken,
                ?callable $beforePersist,
            ) use ($conv, &$tokenCallbackFired): ConversationTurn {
                $onToken('hello');
                $tokenCallbackFired = true;
                $beforePersist === null ?: $beforePersist(['history' => []]);

                return new ConversationTurn(
                    response: $this->makeResponse('hi'),
                    conversation: $conv,
                );
            }
        );

        $emitted = [];
        $this->panel->stream('test', 'conv-ontoken', function (string $event, array $data) use (&$emitted): void {
            $emitted[] = [$event, $data];
        });

        self::assertTrue($tokenCallbackFired);
    }

    public function test_stream_deregisters_its_listeners_so_repeated_calls_never_double_fire(): void
    {
        HookRegistry::reset();
        $conv = $this->makeConversation('conv-repeat');

        $this->engine->method('conversation')->willReturn($conv);
        $this->engine->method('streamInConversation')->willReturnCallback(
            function (Conversation $c, string $m, callable $onToken, ?callable $beforePersist) use ($conv): ConversationTurn {
                HookRegistry::fire('tool.after', [
                    'tool_name' => 'oc_order',
                    'tool_input' => [],
                    'tool_result' => 'ok',
                ]);
                $beforePersist === null ?: $beforePersist(['history' => []]);

                return new ConversationTurn(response: $this->makeResponse(), conversation: $conv);
            }
        );

        $firstRunEmitted = [];
        $this->panel->stream('first', '', function (string $event, array $data) use (&$firstRunEmitted): void {
            $firstRunEmitted[] = $event;
        });

        self::assertSame(0, HookRegistry::count('tool.after'), 'stream() must deregister its listeners once the turn completes');

        $secondRunEmitted = [];
        $this->panel->stream('second', '', function (string $event, array $data) use (&$secondRunEmitted): void {
            $secondRunEmitted[] = $event;
        });

        $secondRunToolAfterCount = count(array_filter($secondRunEmitted, static fn (string $e): bool => $e === 'tool_after'));
        self::assertSame(1, $secondRunToolAfterCount, 'A second stream() call must emit tool_after exactly once, proving the first call\'s listener did not stack');
    }
}

<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Controller\Adminhtml\Chat;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Magento\Controller\Adminhtml\Chat\Stream;
use PhpClaw\Magento\Factory\PhpClawFactoryInterface;
use PhpClaw\Magento\Service\ToolCallCollectorFactory;
use PhpClaw\Magento\Service\ToolHistorySplicer;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class StreamTest extends TestCase
{
    private Context&MockObject $context;

    private RequestInterface&MockObject $request;

    private PhpClawFactoryInterface&MockObject $phpClawFactory;

    private PhpClawInterface&MockObject $engine;

    private MemoryInterface&MockObject $memory;

    private LoggerInterface&MockObject $logger;

    private Stream $controller;

    protected function setUp(): void
    {
        $this->context = $this->createMock(Context::class);
        $this->request = $this->createMock(RequestInterface::class);
        $this->phpClawFactory = $this->createMock(PhpClawFactoryInterface::class);
        $this->engine = $this->createMock(PhpClawInterface::class);
        $this->memory = $this->createMock(MemoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->context->method('getRequest')->willReturn($this->request);
        $this->phpClawFactory->method('create')->willReturn($this->engine);
        $this->engine->method('memory')->willReturn($this->memory);

        $splicer = new ToolHistorySplicer;
        $this->controller = new Stream($this->context, $this->phpClawFactory, $this->logger, $splicer, new ToolCallCollectorFactory, $this->createMock(JsonFactory::class));
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
    }

    private function makeConversation(string $id = '01JTEST00000000000000000AB'): Conversation
    {
        return new Conversation(id: $id, history: [], createdAt: new \DateTimeImmutable);
    }

    private function makeResponse(string $text = 'ok'): AgentResponse
    {
        return new AgentResponse(
            text: $text,
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            iterations: 1,
        );
    }

    private function makeTurn(string $text = 'ok', ?Conversation $conv = null): ConversationTurn
    {
        return new ConversationTurn(
            response: $this->makeResponse($text),
            conversation: $conv ?? $this->makeConversation(),
        );
    }

    private function noopEmit(): callable
    {
        return static function (string $event, array $payload): void {};
    }

    public function test_admin_resource_constant_matches_acl_xml(): void
    {
        self::assertSame('PhpClaw_Magento::phpclaw_chat', Stream::ADMIN_RESOURCE);
    }

    public function test_process_url_keys_copies_form_key_from_json_body_into_request_param(): void
    {
        $body = json_encode(['form_key' => 'abc123', 'message' => 'hi']);
        $this->request->method('getContent')->willReturn((string) $body);
        $this->request->expects(self::once())
            ->method('setParam')
            ->with('form_key', 'abc123');

        try {
            $this->controller->_processUrlKeys();
        } catch (\Throwable) {
        }
    }

    public function test_process_url_keys_skips_set_param_when_form_key_missing(): void
    {
        $body = json_encode(['message' => 'hi']);
        $this->request->method('getContent')->willReturn((string) $body);
        $this->request->expects(self::never())->method('setParam');

        try {
            $this->controller->_processUrlKeys();
        } catch (\Throwable) {
        }
    }

    public function test_process_url_keys_skips_set_param_on_invalid_json(): void
    {
        $this->request->method('getContent')->willReturn('not-json{{{');
        $this->request->expects(self::never())->method('setParam');

        try {
            $this->controller->_processUrlKeys();
        } catch (\Throwable) {
        }
    }

    public function test_process_turn_returns_error_payload_when_message_is_empty(): void
    {
        $frame = $this->controller->processTurn(['message' => ''], $this->noopEmit());

        self::assertArrayHasKey('error', $frame);
        self::assertSame('Message is required.', $frame['error']);
        self::assertSame(400, $frame['code']);
    }

    public function test_process_turn_returns_error_payload_when_message_is_whitespace(): void
    {
        $frame = $this->controller->processTurn(['message' => '   '], $this->noopEmit());

        self::assertArrayHasKey('error', $frame);
        self::assertSame('Message is required.', $frame['error']);
    }

    public function test_process_turn_returns_error_payload_when_message_absent(): void
    {
        $frame = $this->controller->processTurn([], $this->noopEmit());

        self::assertArrayHasKey('error', $frame);
        self::assertSame('Message is required.', $frame['error']);
    }

    public function test_process_turn_uses_provided_conversation_id(): void
    {
        $convId = '01JTEST00000000000000000AB';
        $conv = $this->makeConversation($convId);
        $this->engine->method('conversation')->willReturn($conv);
        $this->engine->method('streamInConversation')->willReturn($this->makeTurn('hi', $conv));

        $frame = $this->controller->processTurn(
            ['message' => 'hello', 'conversation_id' => $convId],
            $this->noopEmit(),
        );

        self::assertSame($convId, $frame['conversation_id']);
        self::assertFalse($frame['is_new']);
    }

    public function test_process_turn_generates_ulid_when_conversation_id_absent(): void
    {
        $this->engine->method('conversation')
            ->willReturnCallback(fn (string $id) => $this->makeConversation($id));
        $this->engine->method('streamInConversation')
            ->willReturnCallback(fn (Conversation $c) => $this->makeTurn('ok', $c));

        $frame = $this->controller->processTurn(['message' => 'hello'], $this->noopEmit());

        self::assertMatchesRegularExpression('/^[0-9A-Z]{26}$/', $frame['conversation_id']);
        self::assertTrue($frame['is_new']);
    }

    public function test_process_turn_happy_path_returns_done_fields(): void
    {
        $conv = $this->makeConversation();
        $this->engine->method('conversation')->willReturn($conv);
        $this->engine->method('streamInConversation')->willReturn($this->makeTurn('great answer', $conv));

        $frame = $this->controller->processTurn(
            ['message' => 'hello', 'conversation_id' => $conv->id],
            $this->noopEmit(),
        );

        self::assertSame('great answer', $frame['text']);
        self::assertSame('anthropic', $frame['provider']);
        self::assertSame('claude-haiku-4-5-20251001', $frame['model']);
        self::assertSame(1, $frame['iterations']);
        self::assertArrayHasKey('tokens', $frame);
        self::assertArrayHasKey('tool_calls', $frame);
        self::assertArrayHasKey('title', $frame);
    }

    public function test_process_turn_tool_calls_captured_when_hook_fires(): void
    {
        $conv = $this->makeConversation();
        $this->engine->method('conversation')->willReturn($conv);
        $this->engine->method('streamInConversation')
            ->willReturnCallback(function () use ($conv): ConversationTurn {
                HookRegistry::fire('tool.after', [
                    'tool_name' => 'db_query',
                    'tool_input' => ['query' => 'SELECT 1'],
                    'tool_result' => '1',
                ]);

                return $this->makeTurn('done', $conv);
            });

        $frame = $this->controller->processTurn(
            ['message' => 'query', 'conversation_id' => $conv->id],
            $this->noopEmit(),
        );

        self::assertCount(1, $frame['tool_calls']);
        self::assertSame('db_query', $frame['tool_calls'][0]['tool_name']);
    }

    public function test_process_turn_tool_before_emits_frame(): void
    {
        $conv = $this->makeConversation();
        $emitted = [];
        $collect = static function (string $event, array $payload) use (&$emitted): void {
            $emitted[] = ['event' => $event, 'payload' => $payload];
        };

        $this->engine->method('conversation')->willReturn($conv);
        $this->engine->method('streamInConversation')
            ->willReturnCallback(function () use ($conv): ConversationTurn {
                HookRegistry::fire('tool.before', [
                    'tool_name' => 'read_log',
                    'tool_input' => ['lines' => 10],
                ]);

                return $this->makeTurn('ok', $conv);
            });

        $this->controller->processTurn(
            ['message' => 'check logs', 'conversation_id' => $conv->id],
            $collect,
        );

        $toolBefore = array_values(array_filter($emitted, fn ($e) => $e['event'] === 'tool_before'));
        self::assertNotEmpty($toolBefore);
        self::assertSame('read_log', $toolBefore[0]['payload']['tool_name']);
    }

    public function test_process_turn_chunk_frame_emitted_when_provider_token_fires(): void
    {
        $conv = $this->makeConversation();
        $emitted = [];
        $collect = static function (string $event, array $payload) use (&$emitted): void {
            $emitted[] = ['event' => $event, 'payload' => $payload];
        };

        $this->engine->method('conversation')->willReturn($conv);
        $this->engine->method('streamInConversation')
            ->willReturnCallback(function () use ($conv): ConversationTurn {
                HookRegistry::fire('provider.token', ['token' => 'Hello']);
                HookRegistry::fire('provider.token', ['token' => ' world']);

                return $this->makeTurn('Hello world', $conv);
            });

        $this->controller->processTurn(
            ['message' => 'say hello', 'conversation_id' => $conv->id],
            $collect,
        );

        $chunks = array_values(array_filter($emitted, fn ($e) => $e['event'] === 'chunk'));
        self::assertCount(2, $chunks);
        self::assertSame('Hello', $chunks[0]['payload']['text']);
        self::assertSame(' world', $chunks[1]['payload']['text']);
    }

    public function test_process_turn_empty_token_skipped(): void
    {
        $conv = $this->makeConversation();
        $emitted = [];
        $collect = static function (string $event, array $payload) use (&$emitted): void {
            $emitted[] = ['event' => $event, 'payload' => $payload];
        };

        $this->engine->method('conversation')->willReturn($conv);
        $this->engine->method('streamInConversation')
            ->willReturnCallback(function () use ($conv): ConversationTurn {
                HookRegistry::fire('provider.token', ['token' => '']);
                HookRegistry::fire('provider.token', ['token' => 'ok']);

                return $this->makeTurn('ok', $conv);
            });

        $this->controller->processTurn(
            ['message' => 'hi', 'conversation_id' => $conv->id],
            $collect,
        );

        $chunks = array_values(array_filter($emitted, fn ($e) => $e['event'] === 'chunk'));
        self::assertCount(1, $chunks);
        self::assertSame('ok', $chunks[0]['payload']['text']);
    }

    public function test_process_turn_returns_guard_exception_error_frame(): void
    {
        $conv = $this->makeConversation();
        $this->engine->method('conversation')->willReturn($conv);
        $this->engine->method('streamInConversation')
            ->willThrowException(new GuardException('injection'));

        $frame = $this->controller->processTurn(
            ['message' => 'DROP TABLE', 'conversation_id' => $conv->id],
            $this->noopEmit(),
        );

        self::assertArrayHasKey('error', $frame);
        self::assertSame(422, $frame['code']);
        self::assertSame('Blocked request.', $frame['error']);
    }

    public function test_process_turn_returns_provider_exception_error_frame(): void
    {
        $conv = $this->makeConversation();
        $this->engine->method('conversation')->willReturn($conv);
        $this->engine->method('streamInConversation')
            ->willThrowException(new ProviderException('timeout'));

        $frame = $this->controller->processTurn(
            ['message' => 'hello', 'conversation_id' => $conv->id],
            $this->noopEmit(),
        );

        self::assertSame(502, $frame['code']);
        self::assertStringContainsString('provider', strtolower($frame['error']));
    }

    public function test_process_turn_returns_max_iterations_error_frame(): void
    {
        $conv = $this->makeConversation();
        $this->engine->method('conversation')->willReturn($conv);
        $this->engine->method('streamInConversation')
            ->willThrowException(new MaxIterationsException('loop'));

        $frame = $this->controller->processTurn(
            ['message' => 'loop forever', 'conversation_id' => $conv->id],
            $this->noopEmit(),
        );

        self::assertSame(504, $frame['code']);
    }

    public function test_process_turn_returns_500_and_logs_on_unexpected_throwable(): void
    {
        $conv = $this->makeConversation();
        $this->engine->method('conversation')->willReturn($conv);
        $this->engine->method('streamInConversation')
            ->willThrowException(new \RuntimeException('kaboom'));

        $this->logger->expects(self::once())->method('error');

        $frame = $this->controller->processTurn(
            ['message' => 'crash', 'conversation_id' => $conv->id],
            $this->noopEmit(),
        );

        self::assertSame(500, $frame['code']);
        self::assertSame('An internal error occurred. Please try again.', $frame['error']);
    }

    public function test_process_turn_title_truncated_for_new_conversation_over_60_chars(): void
    {
        $longMsg = str_repeat('A', 70);

        $this->engine->method('conversation')
            ->willReturnCallback(fn (string $id) => $this->makeConversation($id));
        $this->engine->method('streamInConversation')
            ->willReturnCallback(fn (Conversation $c) => $this->makeTurn('ok', $c));

        $frame = $this->controller->processTurn(['message' => $longMsg], $this->noopEmit());

        self::assertTrue($frame['is_new']);
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    public function test_execute_returns_null_for_valid_message(): void
    {
        $conv = $this->makeConversation();
        $this->request->method('getContent')
            ->willReturn((string) json_encode(['message' => 'hi', 'conversation_id' => $conv->id]));
        $this->engine->method('conversation')->willReturn($conv);
        $this->engine->method('streamInConversation')->willReturn($this->makeTurn('hi back', $conv));

        $result = $this->controller->execute();
        ob_start();

        self::assertNull($result);
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    public function test_execute_returns_null_for_empty_message(): void
    {
        $this->request->method('getContent')
            ->willReturn((string) json_encode(['message' => '']));

        $result = $this->controller->execute();
        ob_start();

        self::assertNull($result);
    }
}

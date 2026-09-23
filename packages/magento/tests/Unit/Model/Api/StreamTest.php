<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Model\Api;

use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Magento\Factory\PhpClawFactoryInterface;
use PhpClaw\Magento\Model\Api\Stream;
use PhpClaw\Magento\Service\ToolHistorySplicer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class StreamTest extends TestCase
{
    private PhpClawFactoryInterface&MockObject $phpClawFactory;

    private PhpClawInterface&MockObject $engine;

    private LoggerInterface&MockObject $logger;

    private Stream $model;

    protected function setUp(): void
    {
        $this->engine = $this->createMock(PhpClawInterface::class);
        $this->phpClawFactory = $this->createMock(PhpClawFactoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->phpClawFactory->method('create')->willReturn($this->engine);

        $this->model = new Stream($this->phpClawFactory, $this->logger, new ToolHistorySplicer);
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
            iterations: 2,
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

    public function test_process_stream_returns_error_when_message_is_empty(): void
    {
        $frame = $this->model->processStream('', '', $this->noopEmit());

        self::assertArrayHasKey('error', $frame);
        self::assertSame('Message is required.', $frame['error']);
        self::assertSame(400, $frame['code']);
    }

    public function test_process_stream_returns_error_when_message_is_whitespace(): void
    {
        $frame = $this->model->processStream('   ', '', $this->noopEmit());

        self::assertArrayHasKey('error', $frame);
        self::assertSame('Message is required.', $frame['error']);
    }

    public function test_process_stream_does_not_call_factory_on_empty_message(): void
    {
        $this->phpClawFactory->expects(self::never())->method('create');

        $this->model->processStream('', '', $this->noopEmit());
    }

    public function test_process_stream_happy_path_returns_done_fields(): void
    {
        $conv = $this->makeConversation();
        $this->engine->method('conversation')->willReturn($conv);
        $this->engine->method('streamInConversation')->willReturn($this->makeTurn('great answer', $conv));

        $frame = $this->model->processStream(
            'hello',
            $conv->id,
            $this->noopEmit(),
        );

        self::assertSame('great answer', $frame['text']);
        self::assertSame('anthropic', $frame['provider']);
        self::assertSame('claude-haiku-4-5-20251001', $frame['model']);
        self::assertSame(2, $frame['iterations']);
        self::assertArrayHasKey('tokens', $frame);
        self::assertArrayHasKey('tool_calls', $frame);
        self::assertArrayHasKey('is_new', $frame);
        self::assertFalse($frame['is_new']);
    }

    public function test_process_stream_marks_is_new_true_when_no_conversation_id(): void
    {
        $this->engine->method('conversation')
            ->willReturnCallback(fn (string $id) => $this->makeConversation($id));
        $this->engine->method('streamInConversation')
            ->willReturnCallback(fn (Conversation $c) => $this->makeTurn('ok', $c));

        $frame = $this->model->processStream('hello', '', $this->noopEmit());

        self::assertTrue($frame['is_new']);
        self::assertMatchesRegularExpression('/^[0-9A-Z]{26}$/', $frame['conversation_id']);
    }

    public function test_process_stream_title_set_from_message_for_new_conversation(): void
    {
        $this->engine->method('conversation')
            ->willReturnCallback(fn (string $id) => $this->makeConversation($id));
        $this->engine->method('streamInConversation')
            ->willReturnCallback(function (Conversation $c, string $m, callable $onToken, ?callable $beforePersist = null): ConversationTurn {
                if ($beforePersist !== null) {
                    $beforePersist(['history' => []]);
                }

                return $this->makeTurn('ok', $c);
            });

        $frame = $this->model->processStream('short message', '', $this->noopEmit());

        self::assertSame('short message', $frame['title']);
    }

    public function test_process_stream_title_truncated_at_60_chars_for_new_conversation(): void
    {
        $longMsg = str_repeat('X', 70);

        $this->engine->method('conversation')
            ->willReturnCallback(fn (string $id) => $this->makeConversation($id));
        $this->engine->method('streamInConversation')
            ->willReturnCallback(function (Conversation $c, string $m, callable $onToken, ?callable $beforePersist = null): ConversationTurn {
                if ($beforePersist !== null) {
                    $beforePersist(['history' => []]);
                }

                return $this->makeTurn('ok', $c);
            });

        $frame = $this->model->processStream($longMsg, '', $this->noopEmit());

        self::assertTrue($frame['is_new']);
        self::assertSame(61, mb_strlen($frame['title']));
    }

    public function test_process_stream_tool_before_hook_emits_frame(): void
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
                    'tool_name' => 'db_query',
                    'tool_input' => ['query' => 'SELECT 1'],
                ]);

                return $this->makeTurn('done', $conv);
            });

        $this->model->processStream('run query', $conv->id, $collect);

        $before = array_values(array_filter($emitted, fn ($e) => $e['event'] === 'tool_before'));
        self::assertNotEmpty($before);
        self::assertSame('db_query', $before[0]['payload']['tool_name']);
    }

    public function test_process_stream_tool_after_hook_populates_tool_calls(): void
    {
        $conv = $this->makeConversation();

        $this->engine->method('conversation')->willReturn($conv);
        $this->engine->method('streamInConversation')
            ->willReturnCallback(function () use ($conv): ConversationTurn {
                HookRegistry::fire('tool.after', [
                    'tool_name' => 'product_search',
                    'tool_input' => ['sku' => 'ABC'],
                    'tool_result' => 'Found 3 products',
                ]);

                return $this->makeTurn('done', $conv);
            });

        $frame = $this->model->processStream('find products', $conv->id, $this->noopEmit());

        self::assertCount(1, $frame['tool_calls']);
        self::assertSame('product_search', $frame['tool_calls'][0]['tool_name']);
        self::assertSame('Found 3 products', $frame['tool_calls'][0]['tool_result']);
    }

    public function test_process_stream_guard_exception_returns_422_error_frame(): void
    {
        $conv = $this->makeConversation();
        $this->engine->method('conversation')->willReturn($conv);
        $this->engine->method('streamInConversation')
            ->willThrowException(new GuardException('injection attempt'));

        $frame = $this->model->processStream('DROP TABLE', $conv->id, $this->noopEmit());

        self::assertArrayHasKey('error', $frame);
        self::assertSame('Blocked request.', $frame['error']);
        self::assertSame(422, $frame['code']);
    }

    public function test_process_stream_provider_exception_returns_502_error_frame(): void
    {
        $conv = $this->makeConversation();
        $this->engine->method('conversation')->willReturn($conv);
        $this->engine->method('streamInConversation')
            ->willThrowException(new ProviderException('timeout'));

        $frame = $this->model->processStream('hello', $conv->id, $this->noopEmit());

        self::assertArrayHasKey('error', $frame);
        self::assertSame(502, $frame['code']);
        self::assertStringContainsString('provider', strtolower($frame['error']));
    }

    public function test_process_stream_max_iterations_exception_returns_504_error_frame(): void
    {
        $conv = $this->makeConversation();
        $this->engine->method('conversation')->willReturn($conv);
        $this->engine->method('streamInConversation')
            ->willThrowException(new MaxIterationsException('hit cap'));

        $frame = $this->model->processStream('loop forever', $conv->id, $this->noopEmit());

        self::assertArrayHasKey('error', $frame);
        self::assertSame(504, $frame['code']);
    }

    public function test_process_stream_unexpected_throwable_returns_500_and_logs(): void
    {
        $conv = $this->makeConversation();
        $this->engine->method('conversation')->willReturn($conv);
        $this->engine->method('streamInConversation')
            ->willThrowException(new \RuntimeException('kaboom'));

        $this->logger->expects(self::once())->method('error');

        $frame = $this->model->processStream('crash', $conv->id, $this->noopEmit());

        self::assertSame(500, $frame['code']);
        self::assertSame('An internal error occurred. Please try again.', $frame['error']);
    }

    public function test_process_stream_does_not_leak_exception_message(): void
    {
        $conv = $this->makeConversation();
        $this->engine->method('conversation')->willReturn($conv);
        $this->engine->method('streamInConversation')
            ->willThrowException(new \RuntimeException('secret-key-xyz-12345'));

        $frame = $this->model->processStream('trigger', $conv->id, $this->noopEmit());

        self::assertStringNotContainsString('secret-key-xyz-12345', $frame['error']);
    }

    public function test_process_stream_empty_tool_name_not_added_to_tool_calls(): void
    {
        $conv = $this->makeConversation();

        $this->engine->method('conversation')->willReturn($conv);
        $this->engine->method('streamInConversation')
            ->willReturnCallback(function () use ($conv): ConversationTurn {
                HookRegistry::fire('tool.after', [
                    'tool_name' => '',
                    'tool_input' => [],
                    'tool_result' => 'ignored',
                ]);

                return $this->makeTurn('done', $conv);
            });

        $frame = $this->model->processStream('query', $conv->id, $this->noopEmit());

        self::assertCount(0, $frame['tool_calls']);
    }

    public function test_process_stream_splices_tool_calls_into_history_on_persist(): void
    {
        $conv = $this->makeConversation();
        $persisted = null;

        $this->engine->method('conversation')->willReturn($conv);
        $this->engine->method('streamInConversation')
            ->willReturnCallback(function (Conversation $c, string $m, callable $onToken, ?callable $beforePersist = null) use (&$persisted): ConversationTurn {
                HookRegistry::fire('tool.after', [
                    'tool_name' => 'product_search',
                    'tool_input' => ['sku' => 'ABC'],
                    'tool_result' => 'Found 3 products',
                ]);

                if ($beforePersist !== null) {
                    $persisted = $beforePersist(['history' => [
                        ['role' => 'user',      'content' => 'find products'],
                        ['role' => 'assistant', 'content' => 'here you go'],
                    ]]);
                }

                return $this->makeTurn('done', $c);
            });

        $frame = $this->model->processStream('find products', $conv->id, $this->noopEmit());

        self::assertCount(1, $frame['tool_calls']);
        self::assertIsArray($persisted);
        self::assertArrayHasKey('history', $persisted);
        self::assertGreaterThan(2, count($persisted['history']));
    }

    public function test_emit_outputs_sse_event_and_data_lines(): void
    {
        $reflect = new \ReflectionMethod($this->model, 'emit');
        $reflect->setAccessible(true);

        ob_start();
        ob_start();
        $reflect->invoke($this->model, 'tool_after', ['tool_name' => 'db_query', 'result' => 'ok']);
        ob_end_clean();
        $output = ob_get_clean();

        self::assertStringContainsString('event: tool_after', $output);
        self::assertStringContainsString('"tool_name":"db_query"', $output);
    }
}

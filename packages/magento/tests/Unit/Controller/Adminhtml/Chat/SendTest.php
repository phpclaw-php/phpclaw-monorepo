<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Controller\Adminhtml\Chat;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Magento\Controller\Adminhtml\Chat\Send;
use PhpClaw\Magento\Factory\PhpClawFactoryInterface;
use PhpClaw\Magento\Service\ToolCallCollectorFactory;
use PhpClaw\Magento\Service\ToolHistorySplicer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SendTest extends TestCase
{
    private JsonFactory&MockObject $jsonFactory;

    private Json&MockObject $jsonResult;

    private PhpClawFactoryInterface&MockObject $phpClawFactory;

    private PhpClawInterface&MockObject $engine;

    private Context&MockObject $context;

    private RequestInterface&MockObject $request;

    private Send $controller;

    public ?array $lastSetData = null;

    protected function setUp(): void
    {
        $this->jsonResult = $this->createMock(Json::class);
        $this->jsonFactory = $this->createMock(JsonFactory::class);
        $this->engine = $this->createMock(PhpClawInterface::class);
        $this->phpClawFactory = $this->createMock(PhpClawFactoryInterface::class);
        $this->request = $this->createMock(RequestInterface::class);
        $this->context = $this->createMock(Context::class);

        $this->context->method('getRequest')->willReturn($this->request);
        $this->jsonFactory->method('create')->willReturn($this->jsonResult);
        $this->phpClawFactory->method('create')->willReturn($this->engine);

        $self = $this;
        $this->jsonResult->method('setData')
            ->willReturnCallback(function (array $d) use ($self): Json {
                $self->lastSetData = $d;

                return $self->jsonResult;
            });
        $this->jsonResult->method('setHttpResponseCode')->willReturn($this->jsonResult);

        $logger = $this->createMock(LoggerInterface::class);
        $splicer = new ToolHistorySplicer;
        $this->controller = new Send($this->context, $this->jsonFactory, $this->phpClawFactory, $logger, $splicer, new ToolCallCollectorFactory);
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
    }

    private function makeConversation(string $id = 'conv-id-01234567890123'): Conversation
    {
        return new Conversation(id: $id, history: [], createdAt: new \DateTimeImmutable);
    }

    private function makeResponse(string $text = 'ok'): AgentResponse
    {
        return new AgentResponse(text: $text, provider: 'anthropic', model: 'claude-haiku-4-5-20251001', iterations: 1);
    }

    private function makeTurn(string $text = 'ok', string $conversationId = 'conv-id-01234567890123'): ConversationTurn
    {
        return new ConversationTurn(
            response: $this->makeResponse($text),
            conversation: $this->makeConversation($conversationId),
        );
    }

    public function test_it_returns_text_on_success(): void
    {
        $this->request->method('getContent')
            ->willReturn(json_encode(['message' => 'hello']));
        $this->engine->method('conversation')->willReturn($this->makeConversation());
        $this->engine->method('streamInConversation')
            ->willReturnCallback(function (Conversation $c, string $m, callable $onToken, ?callable $beforePersist = null): ConversationTurn {
                if (is_callable($beforePersist)) {
                    $beforePersist(['history' => []]);
                }

                return $this->makeTurn('world');
            });

        $result = $this->controller->execute();

        self::assertSame($this->jsonResult, $result);
        self::assertSame('world', $this->lastSetData['text']);
        self::assertSame('anthropic', $this->lastSetData['provider']);
        self::assertArrayHasKey('conversation_id', $this->lastSetData);
    }

    public function test_it_uses_provided_conversation_id(): void
    {
        $this->request->method('getContent')
            ->willReturn(json_encode(['message' => 'hi', 'conversation_id' => 'abc123']));
        $this->engine->method('conversation')->willReturn($this->makeConversation('abc123'));
        $this->engine->method('streamInConversation')
            ->willReturnCallback(function (Conversation $c, string $m, callable $onToken, ?callable $beforePersist = null): ConversationTurn {
                if (is_callable($beforePersist)) {
                    $beforePersist(['history' => []]);
                }

                return $this->makeTurn('hi', 'abc123');
            });

        $this->controller->execute();

        self::assertSame('abc123', $this->lastSetData['conversation_id']);
    }

    public function test_it_generates_conversation_id_when_not_provided(): void
    {
        $this->request->method('getContent')
            ->willReturn(json_encode(['message' => 'hello']));

        $this->engine->method('conversation')
            ->willReturnCallback(function (string $id): Conversation {
                return $this->makeConversation($id);
            });
        $this->engine->method('streamInConversation')
            ->willReturnCallback(function (Conversation $conv, string $m, callable $onToken, ?callable $beforePersist = null): ConversationTurn {
                if (is_callable($beforePersist)) {
                    $beforePersist(['history' => []]);
                }

                return new ConversationTurn(
                    response: $this->makeResponse('ok'),
                    conversation: $conv,
                );
            });

        $this->controller->execute();

        self::assertMatchesRegularExpression('/^[0-9A-Z]{26}$/', $this->lastSetData['conversation_id']);
    }

    public function test_it_returns_400_on_empty_message(): void
    {
        $this->request->method('getContent')
            ->willReturn(json_encode(['message' => '']));

        $this->jsonResult->expects(self::once())
            ->method('setHttpResponseCode')->with(400)->willReturn($this->jsonResult);

        $this->controller->execute();
    }

    public function test_it_returns_422_on_guard_exception(): void
    {
        $this->request->method('getContent')
            ->willReturn(json_encode(['message' => 'inject']));
        $this->engine->method('conversation')->willReturn($this->makeConversation());
        $this->engine->method('streamInConversation')->willThrowException(new GuardException('injection'));

        $this->jsonResult->expects(self::once())
            ->method('setHttpResponseCode')->with(422)->willReturn($this->jsonResult);

        $this->controller->execute();
    }

    public function test_it_returns_502_on_provider_exception(): void
    {
        $this->request->method('getContent')
            ->willReturn(json_encode(['message' => 'hello']));
        $this->engine->method('conversation')->willReturn($this->makeConversation());
        $this->engine->method('streamInConversation')->willThrowException(new ProviderException('API down'));

        $this->jsonResult->expects(self::once())
            ->method('setHttpResponseCode')->with(502)->willReturn($this->jsonResult);

        $this->controller->execute();
    }

    public function test_it_returns_504_on_max_iterations(): void
    {
        $this->request->method('getContent')
            ->willReturn(json_encode(['message' => 'loop']));
        $this->engine->method('conversation')->willReturn($this->makeConversation());
        $this->engine->method('streamInConversation')->willThrowException(new MaxIterationsException('hit cap'));

        $this->jsonResult->expects(self::once())
            ->method('setHttpResponseCode')->with(504)->willReturn($this->jsonResult);

        $this->controller->execute();
    }

    public function test_it_returns_500_on_unexpected_exception(): void
    {
        $this->request->method('getContent')
            ->willReturn(json_encode(['message' => 'crash']));
        $this->engine->method('conversation')->willReturn($this->makeConversation());
        $this->engine->method('streamInConversation')->willThrowException(new \RuntimeException('boom'));

        $this->jsonResult->expects(self::once())
            ->method('setHttpResponseCode')->with(500)->willReturn($this->jsonResult);

        $this->controller->execute();
    }

    public function test_process_url_keys_copies_form_key_from_json_body(): void
    {
        $body = json_encode(['form_key' => 'csrf-token-xyz', 'message' => 'hi']);
        $this->request->method('getContent')->willReturn((string) $body);
        $this->request->expects(self::once())
            ->method('setParam')
            ->with('form_key', 'csrf-token-xyz');

        try {
            $this->controller->_processUrlKeys();
        } catch (\Throwable) {
        }
    }

    public function test_process_url_keys_skips_set_param_when_form_key_missing(): void
    {
        $body = json_encode(['message' => 'hi only']);
        $this->request->method('getContent')->willReturn((string) $body);
        $this->request->expects(self::never())->method('setParam');

        try {
            $this->controller->_processUrlKeys();
        } catch (\Throwable) {
        }
    }

    public function test_tool_calls_captured_via_hook_registry(): void
    {
        HookRegistry::reset();

        $convId = 'conv-id-01234567890123';
        $history = [
            ['role' => 'user',      'content' => 'list products'],
            ['role' => 'assistant', 'content' => 'here are 2 products'],
        ];

        $captured = null;
        $this->engine->method('conversation')->willReturn($this->makeConversation($convId));
        $this->engine->method('streamInConversation')
            ->willReturnCallback(function (Conversation $conv, string $m, callable $onToken, ?callable $beforePersist = null) use ($convId, $history, &$captured): ConversationTurn {
                HookRegistry::fire('tool.after', [
                    'tool_name' => 'magento_products',
                    'tool_input' => ['limit' => 2],
                    'tool_result' => '[{"sku":"PHC-010"},{"sku":"PHC-009"}]',
                ]);
                if (is_callable($beforePersist)) {
                    $captured = $beforePersist(['id' => $convId, 'history' => $history]);
                }

                return $this->makeTurn('here are 2 products', $convId);
            });

        $this->request->method('getContent')
            ->willReturn(json_encode(['message' => 'list 2 products', 'conversation_id' => $convId]));

        $this->controller->execute();

        self::assertCount(1, $this->lastSetData['tool_calls']);
        self::assertSame('magento_products', $this->lastSetData['tool_calls'][0]['tool_name']);

        self::assertNotNull($captured);
        self::assertIsArray($captured['history']);
        self::assertSame('user', $captured['history'][0]['role']);
        self::assertSame('tool', $captured['history'][1]['role']);
        self::assertSame('assistant', $captured['history'][2]['role']);
        self::assertSame('magento_products', $captured['history'][1]['tool_name']);

        HookRegistry::reset();
    }

    public function test_tool_call_with_empty_tool_name_is_filtered_out(): void
    {
        HookRegistry::reset();

        $this->engine->method('conversation')->willReturn($this->makeConversation());
        $this->engine->method('streamInConversation')
            ->willReturnCallback(function (Conversation $conv, string $m, callable $onToken, ?callable $beforePersist = null): ConversationTurn {
                HookRegistry::fire('tool.after', [
                    'tool_name' => '',
                    'tool_input' => [],
                    'tool_result' => 'noise',
                ]);
                $payload = ['history' => []];
                if (is_callable($beforePersist)) {
                    $payload = $beforePersist($payload);
                }
                self::assertSame([], $payload['history']);

                return $this->makeTurn('done');
            });

        $this->request->method('getContent')
            ->willReturn(json_encode(['message' => 'noop']));

        $this->controller->execute();

        self::assertSame([], $this->lastSetData['tool_calls']);

        HookRegistry::reset();
    }

    public function test_tool_calls_splice_skipped_when_no_tool_calls(): void
    {
        HookRegistry::reset();

        $capturedPayload = null;
        $this->engine->method('conversation')->willReturn($this->makeConversation());
        $this->engine->method('streamInConversation')
            ->willReturnCallback(function (Conversation $conv, string $m, callable $onToken, ?callable $beforePersist = null) use (&$capturedPayload): ConversationTurn {
                $payload = ['history' => [['role' => 'user', 'content' => 'list orders']]];
                if (is_callable($beforePersist)) {
                    $capturedPayload = $beforePersist($payload);
                }

                return $this->makeTurn('ok');
            });

        $this->request->method('getContent')
            ->willReturn(json_encode(['message' => 'list orders']));

        $this->controller->execute();

        self::assertSame([['role' => 'user', 'content' => 'list orders']], $capturedPayload['history']);
        self::assertSame([], $this->lastSetData['tool_calls']);

        HookRegistry::reset();
    }

    public function test_find_last_assistant_index_falls_back_to_end_when_no_assistant(): void
    {
        HookRegistry::reset();

        $convId = 'conv-id-01234567890123';
        $history = [
            ['role' => 'user', 'content' => 'list products'],
        ];

        $captured = null;
        $this->engine->method('conversation')->willReturn($this->makeConversation($convId));
        $this->engine->method('streamInConversation')
            ->willReturnCallback(function (Conversation $conv, string $m, callable $onToken, ?callable $beforePersist = null) use ($convId, $history, &$captured): ConversationTurn {
                HookRegistry::fire('tool.after', [
                    'tool_name' => 'magento_products',
                    'tool_input' => [],
                    'tool_result' => '[]',
                ]);
                if (is_callable($beforePersist)) {
                    $captured = $beforePersist(['id' => $convId, 'history' => $history]);
                }

                return $this->makeTurn('first reply', $convId);
            });

        $this->request->method('getContent')
            ->willReturn(json_encode(['message' => 'list products', 'conversation_id' => $convId]));

        $this->controller->execute();

        self::assertSame('user', $captured['history'][0]['role']);
        self::assertSame('tool', $captured['history'][1]['role']);

        HookRegistry::reset();
    }
}

<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit\Http;

use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Hooks\LifecycleEvent;
use PhpClaw\Symfony\Http\ApiController;
use PhpClaw\Symfony\Http\StreamEventBridge;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Loader\YamlFileLoader;

final class ApiControllerTest extends TestCase
{
    private PhpClawInterface&MockObject $agent;

    private StreamEventBridge $streamBridge;

    private ApiController $controller;

    protected function setUp(): void
    {
        $this->agent = $this->createMock(PhpClawInterface::class);
        $this->streamBridge = new StreamEventBridge;
        $this->controller = new ApiController($this->agent, $this->streamBridge);
    }

    private function jsonRequest(array $data, string $method = 'POST'): Request
    {
        $request = Request::create('/', $method, [], [], [], [], json_encode($data));
        $request->headers->set('Content-Type', 'application/json');

        return $request;
    }

    public function test_send_returns_json_on_success(): void
    {
        $response = new AgentResponse(
            text: 'Hello world',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            iterations: 1,
            inputTokens: 10,
            outputTokens: 5,
        );

        $this->agent->method('conversation')->willReturnCallback(fn () => $this->createConversationMock());
        $this->agent->method('sendInConversation')->willReturn($this->createTurnMock($response));

        $jsonResponse = $this->controller->send($this->jsonRequest(['message' => 'hello']));

        self::assertSame(200, $jsonResponse->getStatusCode());
        $data = json_decode($jsonResponse->getContent(), true);
        self::assertSame('Hello world', $data['text']);
        self::assertSame('anthropic', $data['provider']);
        self::assertArrayHasKey('conversation_id', $data);
    }

    public function test_send_returns_400_when_message_empty(): void
    {
        $jsonResponse = $this->controller->send($this->jsonRequest(['message' => '']));

        self::assertSame(400, $jsonResponse->getStatusCode());
        $data = json_decode($jsonResponse->getContent(), true);
        self::assertArrayHasKey('error', $data);
    }

    public function test_send_returns_422_on_guard_exception(): void
    {
        $this->agent->method('conversation')->willReturnCallback(fn () => $this->createConversationMock());
        $this->agent->method('sendInConversation')->willThrowException(new GuardException('blocked'));

        $jsonResponse = $this->controller->send($this->jsonRequest(['message' => 'inject']));

        self::assertSame(422, $jsonResponse->getStatusCode());
        $data = json_decode($jsonResponse->getContent(), true);
        self::assertArrayHasKey('error', $data);
    }

    public function test_send_returns_500_on_generic_exception_without_leaking_message(): void
    {
        $this->agent->method('conversation')->willReturnCallback(fn () => $this->createConversationMock());
        $this->agent->method('sendInConversation')->willThrowException(new \RuntimeException('secret api error detail'));

        $jsonResponse = $this->controller->send($this->jsonRequest(['message' => 'test']));

        self::assertSame(500, $jsonResponse->getStatusCode());
        $content = $jsonResponse->getContent();
        self::assertStringNotContainsString('secret api error detail', $content);
    }

    public function test_send_json_error_never_leaks_exception_message(): void
    {
        $this->agent->method('conversation')->willReturnCallback(fn () => $this->createConversationMock());
        $this->agent->method('sendInConversation')->willThrowException(
            new \RuntimeException('API_KEY=sk-abc123 is invalid'),
        );

        $jsonResponse = $this->controller->send($this->jsonRequest(['message' => 'test']));
        $content = $jsonResponse->getContent();

        self::assertStringNotContainsString('sk-abc123', $content);
        self::assertStringNotContainsString('API_KEY', $content);
    }

    private function createConversationMock(): Conversation
    {
        return new Conversation('01H2345678901234567890ABCD', [], new \DateTimeImmutable);
    }

    private function createTurnMock(AgentResponse $response): ConversationTurn
    {
        return new ConversationTurn($response, $this->createConversationMock());
    }

    public function test_send_reports_the_real_tool_input_and_result(): void
    {
        $bridge = new StreamEventBridge;
        $controller = new ApiController($this->agent, $bridge);

        $this->agent->method('conversation')->willReturn($this->createConversationMock());
        $this->agent->method('sendInConversation')->willReturnCallback(
            function () use ($bridge): ConversationTurn {
                $bridge->handle([
                    'event' => LifecycleEvent::ToolAfter->value,
                    'tool_name' => 'shell_exec',
                    'tool_input' => ['command' => 'whoami'],
                    'tool_result' => 'akash_mac',
                ]);

                return $this->createTurnMock(new AgentResponse('done', 'ollama', 'qwen2.5:7b', 2, toolsCalled: ['shell_exec']));
            },
        );

        $body = json_decode((string) $controller->send($this->jsonRequest(['message' => 'run whoami']))->getContent(), true);

        self::assertSame([[
            'tool_name' => 'shell_exec',
            'tool_input' => ['command' => 'whoami'],
            'tool_result' => 'akash_mac',
        ]], $body['tool_calls']);
    }

    public function test_send_falls_back_to_bare_names_when_the_bridge_saw_nothing(): void
    {
        $this->agent->method('conversation')->willReturn($this->createConversationMock());
        $this->agent->method('sendInConversation')->willReturn(
            $this->createTurnMock(new AgentResponse('done', 'ollama', 'qwen2.5:7b', 1, toolsCalled: ['project_info'])),
        );

        $body = json_decode((string) $this->controller->send($this->jsonRequest(['message' => 'hi']))->getContent(), true);

        self::assertSame([[
            'tool_name' => 'project_info',
            'tool_input' => [],
            'tool_result' => '',
        ]], $body['tool_calls']);
    }

    public function test_the_controller_exposes_exactly_send_and_stream(): void
    {
        $ref = new \ReflectionClass(ApiController::class);
        $actions = [];
        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            if ($m->getDeclaringClass()->getName() === ApiController::class && $m->getName() !== '__construct') {
                $actions[] = $m->getName();
            }
        }
        sort($actions);

        self::assertSame(['send', 'stream'], $actions);
    }

    public function test_routes_declare_only_the_two_shipped_endpoints(): void
    {
        $configDir = dirname(__DIR__, 3).'/config';
        $loader = new YamlFileLoader(new FileLocator($configDir));
        $collection = $loader->load('routes.yaml');

        $declared = [];
        foreach ($collection as $name => $route) {
            $declared[$name] = [
                'path' => $route->getPath(),
                'controller' => $route->getDefault('_controller'),
                'methods' => $route->getMethods(),
            ];
        }

        self::assertSame([
            'phpclaw_send' => [
                'path' => '/phpclaw/send',
                'controller' => ApiController::class.'::send',
                'methods' => ['POST'],
            ],
            'phpclaw_chat_stream' => [
                'path' => '/phpclaw/chat/stream',
                'controller' => ApiController::class.'::stream',
                'methods' => ['POST'],
            ],
        ], $declared);
    }
}

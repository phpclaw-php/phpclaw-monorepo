<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Unit\Controller\Admin;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Drupal\Controller\Admin\ConversationHistoryTrait;
use PhpClaw\Drupal\Controller\Admin\PhpClawChatController;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Memory\MemoryRegistry;
use PhpClaw\Skills\SkillRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

final class PhpClawChatControllerTest extends TestCase
{
    private ?object $csrfTokenMock = null;

    private ?object $loggerMock = null;

    private function makeController(ClawInterface $agent): PhpClawChatController
    {
        return new PhpClawChatController($agent, $this->loggerMock, $this->csrfTokenMock);
    }

    protected function setUp(): void
    {
        HookRegistry::reset();
        GuardRegistry::reset();
        MemoryRegistry::reset();
        SkillRegistry::reset();
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
        GuardRegistry::reset();
        MemoryRegistry::reset();
        SkillRegistry::reset();

        if (class_exists(\Drupal::class)) {
            \Drupal::unsetContainer();
        }
    }

    private function bootContainer(bool $csrfValid = true): void
    {
        if (! class_exists(\Drupal::class)) {
            return;
        }

        $csrfToken = $this->createMock(CsrfTokenGenerator::class);
        $csrfToken->method('validate')->willReturn($csrfValid);
        $csrfToken->method('get')->willReturn('test-csrf-token');

        $logger = $this->createMock(LoggerInterface::class);
        $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
        $loggerFactory->method('get')->willReturn($logger);

        $this->csrfTokenMock = $csrfToken;
        $this->loggerMock = $logger;

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(function (string $id) use ($csrfToken, $loggerFactory): object {
            return match ($id) {
                'csrf_token' => $csrfToken,
                'logger.factory' => $loggerFactory,
                default => throw new \RuntimeException("Service '{$id}' not mocked."),
            };
        });
        $container->method('has')->willReturn(false);

        \Drupal::setContainer($container);
    }

    private function makeResponse(string $text = 'Hello'): AgentResponse
    {
        return new AgentResponse(
            text: $text,
            provider: 'anthropic',
            model: 'claude-haiku',
            iterations: 1,
            inputTokens: 10,
            outputTokens: 5,
        );
    }

    private function makeConversation(string $id = 'conv-1'): Conversation
    {
        return new Conversation(
            id: $id,
            history: [],
            createdAt: new \DateTimeImmutable,
        );
    }

    private function makeRequest(array $data, bool $withCsrf = true): Request
    {
        $request = Request::create('/', 'POST', [], [], [], [],
            json_encode($data)
        );
        if ($withCsrf) {
            $request->headers->set('X-CSRF-Token', 'test-token');
        }

        return $request;
    }

    public function test_send_returns_403_when_csrf_invalid(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootContainer(csrfValid: false);

        $agent = $this->createMock(ClawInterface::class);
        $controller = $this->makeController($agent);
        $request = $this->makeRequest(['message' => 'hello']);

        $response = $controller->send($request);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_send_returns_400_when_message_empty(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootContainer();

        $agent = $this->createMock(ClawInterface::class);
        $controller = $this->makeController($agent);
        $request = $this->makeRequest(['message' => '']);

        $response = $controller->send($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function test_send_returns_400_when_message_too_long(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootContainer();

        $agent = $this->createMock(ClawInterface::class);
        $controller = $this->makeController($agent);
        $request = $this->makeRequest(['message' => str_repeat('x', 10001)]);

        $response = $controller->send($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function test_send_returns_ok_response_on_success(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootContainer();

        $conv = $this->makeConversation('conv-send-ok');
        $turn = new ConversationTurn($this->makeResponse('reply text'), $conv);

        $agent = $this->createMock(ClawInterface::class);
        $agent->method('conversation')->willReturn($conv);
        $agent->method('streamInConversation')->willReturn($turn);

        $controller = $this->makeController($agent);
        $request = $this->makeRequest(['message' => 'Hello agent']);

        $response = $controller->send($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['ok']);
        $this->assertSame('reply text', $data['text']);
        $this->assertSame('conv-send-ok', $data['conversation_id']);
    }

    public function test_send_returns_422_on_guard_exception(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootContainer();

        $conv = $this->makeConversation();
        $agent = $this->createMock(ClawInterface::class);
        $agent->method('conversation')->willReturn($conv);
        $agent->method('streamInConversation')->willThrowException(new GuardException('blocked'));

        $controller = $this->makeController($agent);
        $request = $this->makeRequest(['message' => 'Inject!']);

        $response = $controller->send($request);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_send_returns_502_on_provider_exception(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootContainer();

        $conv = $this->makeConversation();
        $agent = $this->createMock(ClawInterface::class);
        $agent->method('conversation')->willReturn($conv);
        $agent->method('streamInConversation')->willThrowException(new ProviderException('provider error'));

        $controller = $this->makeController($agent);
        $request = $this->makeRequest(['message' => 'Hello']);

        $response = $controller->send($request);

        $this->assertSame(502, $response->getStatusCode());
    }

    public function test_send_returns_500_on_unexpected_throwable(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootContainer();

        $conv = $this->makeConversation();
        $agent = $this->createMock(ClawInterface::class);
        $agent->method('conversation')->willReturn($conv);
        $agent->method('streamInConversation')->willThrowException(new \RuntimeException('crash'));

        $controller = $this->makeController($agent);
        $request = $this->makeRequest(['message' => 'Hello']);

        $response = $controller->send($request);

        $this->assertSame(500, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['ok']);
        $this->assertStringNotContainsString('crash', $response->getContent());
    }

    public function test_load_returns_403_when_csrf_invalid(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootContainer(csrfValid: false);

        $agent = $this->createMock(ClawInterface::class);
        $controller = $this->makeController($agent);
        $request = $this->makeRequest(['conversation_id' => 'abc']);

        $response = $controller->load($request);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_load_returns_400_when_no_conversation_id(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootContainer();

        $agent = $this->createMock(ClawInterface::class);
        $controller = $this->makeController($agent);
        $request = $this->makeRequest(['conversation_id' => '']);

        $response = $controller->load($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function test_load_returns_empty_messages_when_not_found(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootContainer();

        $memory = $this->createMock(MemoryInterface::class);
        $memory->method('get')->willReturn(null);

        $agent = $this->createMock(ClawInterface::class);
        $agent->method('memory')->willReturn($memory);

        $controller = $this->makeController($agent);
        $request = $this->makeRequest(['conversation_id' => 'conv-xyz']);

        $response = $controller->load($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['ok']);
        $this->assertSame([], $data['messages']);
    }

    public function test_load_returns_messages_for_valid_conversation(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootContainer();

        $stored = [
            'title' => 'My Chat',
            'history' => [
                ['role' => 'user',      'content' => 'Hello'],
                ['role' => 'assistant', 'content' => 'Hi there'],
                ['role' => 'system',    'content' => 'ignored'],
            ],
        ];

        $memory = $this->createMock(MemoryInterface::class);
        $memory->method('get')->willReturn($stored);

        $agent = $this->createMock(ClawInterface::class);
        $agent->method('memory')->willReturn($memory);

        $controller = $this->makeController($agent);
        $request = $this->makeRequest(['conversation_id' => 'conv-abc']);

        $response = $controller->load($request);

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['ok']);
        $this->assertSame('My Chat', $data['title']);
        $this->assertCount(2, $data['messages']);
    }

    public function test_load_includes_tool_messages(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootContainer();

        $stored = [
            'title' => 'Chat with tools',
            'history' => [
                ['role' => 'user',      'content' => 'Query DB'],
                ['role' => 'tool',      'content' => '[{"id":1}]', 'tool_name' => 'db_query', 'tool_input' => ['query' => 'SELECT 1']],
                ['role' => 'assistant', 'content' => 'Done'],
            ],
        ];

        $memory = $this->createMock(MemoryInterface::class);
        $memory->method('get')->willReturn($stored);

        $agent = $this->createMock(ClawInterface::class);
        $agent->method('memory')->willReturn($memory);

        $controller = $this->makeController($agent);
        $request = $this->makeRequest(['conversation_id' => 'conv-tools']);

        $response = $controller->load($request);

        $data = json_decode($response->getContent(), true);
        $toolMsgs = array_filter($data['messages'], fn ($m) => $m['role'] === 'tool');
        $this->assertCount(1, $toolMsgs);
    }

    public function test_load_returns_500_on_throwable(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootContainer();

        $agent = $this->createMock(ClawInterface::class);
        $agent->method('memory')->willThrowException(new \RuntimeException('memory error'));

        $controller = $this->makeController($agent);
        $request = $this->makeRequest(['conversation_id' => 'conv-err']);

        $response = $controller->load($request);

        $this->assertSame(500, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['ok']);
        $this->assertStringNotContainsString('memory error', $response->getContent());
    }

    public function test_delete_returns_403_when_csrf_invalid(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootContainer(csrfValid: false);

        $agent = $this->createMock(ClawInterface::class);
        $controller = $this->makeController($agent);
        $request = $this->makeRequest(['conversation_id' => 'abc']);

        $response = $controller->delete($request);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_delete_returns_400_when_no_conversation_id(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootContainer();

        $agent = $this->createMock(ClawInterface::class);
        $controller = $this->makeController($agent);
        $request = $this->makeRequest([]);

        $response = $controller->delete($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function test_delete_returns_ok_when_successful(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootContainer();

        $memory = $this->createMock(MemoryInterface::class);
        $memory->expects($this->once())->method('forget')->with('conv-del', 'conversations');

        $agent = $this->createMock(ClawInterface::class);
        $agent->method('memory')->willReturn($memory);

        $controller = $this->makeController($agent);
        $request = $this->makeRequest(['conversation_id' => 'conv-del']);

        $response = $controller->delete($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['ok']);
    }

    public function test_delete_returns_500_on_throwable(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootContainer();

        $agent = $this->createMock(ClawInterface::class);
        $agent->method('memory')->willThrowException(new \RuntimeException('delete error'));

        $controller = $this->makeController($agent);
        $request = $this->makeRequest(['conversation_id' => 'conv-err']);

        $response = $controller->delete($request);

        $this->assertSame(500, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertStringNotContainsString('delete error', $response->getContent());
    }

    private function bootContainerWithConfig(array $configValues = []): void
    {
        if (! class_exists(\Drupal::class)) {
            return;
        }

        $csrfToken = $this->createMock(CsrfTokenGenerator::class);
        $csrfToken->method('validate')->willReturn(true);
        $csrfToken->method('get')->willReturn('test-csrf-token');

        $logger = $this->createMock(LoggerInterface::class);
        $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
        $loggerFactory->method('get')->willReturn($logger);

        $config = $this->createMock(ImmutableConfig::class);
        $config->method('get')->willReturnCallback(fn (string $k) => $configValues[$k] ?? '');

        $this->csrfTokenMock = $csrfToken;
        $this->loggerMock = $logger;

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            function (string $id) use ($csrfToken, $loggerFactory, $config): object {
                return match ($id) {
                    'csrf_token' => $csrfToken,
                    'logger.factory' => $loggerFactory,
                    'config.factory' => (function () use ($config) {
                        $factory = $this->createMock(ConfigFactoryInterface::class);
                        $factory->method('get')->willReturn($config);

                        return $factory;
                    })(),
                    default => throw new \RuntimeException("Service '{$id}' not mocked."),
                };
            }
        );
        $container->method('has')->willReturn(false);

        \Drupal::setContainer($container);
    }

    public function test_index_returns_render_array_with_no_conversations(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootContainerWithConfig(['provider' => 'anthropic', 'model' => 'claude-haiku']);

        $memory = $this->createMock(MemoryInterface::class);
        $memory->method('all')->willReturn([]);

        $agent = $this->createMock(ClawInterface::class);
        $agent->method('memory')->willReturn($memory);

        $controller = $this->makeController($agent);
        $result = $controller->index();

        $this->assertSame('phpclaw_admin_chat', $result['#theme']);
        $this->assertSame(0, $result['#cache']['max-age']);
        $this->assertArrayHasKey('#conversations', $result);
        $this->assertSame([], $result['#conversations']);
        $this->assertStringContainsString('What can I help you with?', $result['#init_messages_html']);
    }

    public function test_index_with_conversations_renders_first_conversation(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootContainerWithConfig(['provider' => 'openai', 'model' => 'gpt-4o-mini']);

        $convData = [
            'title' => 'Test Chat',
            'updated_at' => '2026-01-01 10:00:00',
            'created_at' => '2026-01-01 10:00:00',
            'history' => [
                ['role' => 'user',      'content' => 'Hello'],
                ['role' => 'assistant', 'content' => 'Hi there'],
            ],
        ];

        $memory = $this->createMock(MemoryInterface::class);
        $memory->method('all')->willReturn(['conv-1' => $convData]);
        $memory->method('get')->willReturn($convData);

        $agent = $this->createMock(ClawInterface::class);
        $agent->method('memory')->willReturn($memory);

        $controller = $this->makeController($agent);
        $result = $controller->index();

        $this->assertSame('phpclaw_admin_chat', $result['#theme']);
        $this->assertSame('conv-1', $result['#init_conv_id']);
        $this->assertSame('Test Chat', $result['#init_title']);
        $this->assertArrayHasKey('conv-1', $result['#conversations']);
        $this->assertStringContainsString('phpclaw-bubble', $result['#init_messages_html']);
    }

    public function test_index_with_conversations_but_no_stored_history(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootContainerWithConfig([]);

        $convData = [
            'title' => 'Empty Chat',
            'updated_at' => '2026-01-01 10:00:00',
        ];

        $memory = $this->createMock(MemoryInterface::class);
        $memory->method('all')->willReturn(['conv-empty' => $convData]);
        $memory->method('get')->willReturn(null);

        $agent = $this->createMock(ClawInterface::class);
        $agent->method('memory')->willReturn($memory);

        $controller = $this->makeController($agent);
        $result = $controller->index();

        $this->assertStringContainsString('Conversation started', $result['#init_messages_html']);
    }

    public function test_index_memory_exception_renders_welcome(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootContainerWithConfig([]);

        $agent = $this->createMock(ClawInterface::class);
        $agent->method('memory')->willThrowException(new \RuntimeException('memory fail'));

        $controller = $this->makeController($agent);
        $result = $controller->index();

        $this->assertSame('phpclaw_admin_chat', $result['#theme']);
        $this->assertStringContainsString('What can I help you with?', $result['#init_messages_html']);
    }

    public function test_send_splices_tool_calls_via_before_persist(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootContainer();

        $conv = $this->makeConversation('conv-tools');
        $turn = new ConversationTurn($this->makeResponse('done'), $conv);

        $stored = [
            'history' => [
                ['role' => 'user',      'content' => 'Query DB'],
                ['role' => 'assistant', 'content' => 'Done'],
            ],
        ];

        $memory = $this->createMock(MemoryInterface::class);
        $memory->expects($this->once())->method('set');

        $agent = $this->createMock(ClawInterface::class);
        $agent->method('conversation')->willReturn($conv);
        $agent->method('memory')->willReturn($memory);
        $agent->method('streamInConversation')->willReturnCallback(
            function (object $_conv, string $_msg, callable $_onToken, ?callable $beforePersist) use ($turn, $stored, $memory): ConversationTurn {
                HookRegistry::fire('tool.after', [
                    'tool_name' => 'db_query',
                    'tool_input' => ['query' => 'SELECT 1'],
                    'tool_result' => '[{"id":1}]',
                ]);
                $payload = $beforePersist !== null ? $beforePersist($stored) : $stored;
                $memory->set($turn->conversation->id, $payload, 'conversations');

                return $turn;
            }
        );

        $controller = $this->makeController($agent);
        $request = $this->makeRequest(['message' => 'Query the DB', 'conversation_id' => 'conv-tools']);

        $response = $controller->send($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertNotEmpty($data['tool_calls']);
    }

    public function test_create_builds_controller_from_container(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $agent = $this->createMock(ClawInterface::class);
        $csrfToken = $this->createMock(CsrfTokenGenerator::class);
        $logger = $this->createMock(LoggerInterface::class);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(fn (string $id): object => match ($id) {
            'phpclaw.agent.chat' => $agent,
            'logger.channel.phpclaw' => $logger,
            'csrf_token' => $csrfToken,
            'datetime.time' => $this->createMock(TimeInterface::class),
            default => throw new \RuntimeException("Service '{$id}' not mocked."),
        });

        $controller = PhpClawChatController::create($container);

        $this->assertInstanceOf(PhpClawChatController::class, $controller);
    }

    public function test_send_tool_calls_spliced_before_first_message_when_no_assistant_in_history(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootContainer();

        $conv = $this->makeConversation('conv-no-assistant');
        $turn = new ConversationTurn($this->makeResponse('done'), $conv);

        $stored = [
            'history' => [
                ['role' => 'user', 'content' => 'Query DB'],
            ],
        ];

        $memory = $this->createMock(MemoryInterface::class);
        $memory->expects($this->once())->method('set');

        $agent = $this->createMock(ClawInterface::class);
        $agent->method('conversation')->willReturn($conv);
        $agent->method('memory')->willReturn($memory);
        $agent->method('streamInConversation')->willReturnCallback(
            function (object $_conv, string $_msg, callable $_onToken, ?callable $beforePersist) use ($turn, $stored, $memory): ConversationTurn {
                HookRegistry::fire('tool.after', [
                    'tool_name' => 'node_tool',
                    'tool_input' => [],
                    'tool_result' => 'result',
                ]);
                $payload = $beforePersist !== null ? $beforePersist($stored) : $stored;
                $memory->set($turn->conversation->id, $payload, 'conversations');

                return $turn;
            }
        );

        $controller = $this->makeController($agent);
        $request = $this->makeRequest(['message' => 'Query the DB', 'conversation_id' => 'conv-no-assistant']);

        $response = $controller->send($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertNotEmpty($data['tool_calls']);
    }

    public function test_index_with_non_array_conv_value_uses_empty_title(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootContainerWithConfig(['provider' => 'anthropic', 'model' => 'haiku']);

        $memory = $this->createMock(MemoryInterface::class);
        $memory->method('all')->willReturn(['conv-scalar' => 'some-string-value']);
        $memory->method('get')->willReturn(null);

        $agent = $this->createMock(ClawInterface::class);
        $agent->method('memory')->willReturn($memory);

        $controller = $this->makeController($agent);
        $result = $controller->index();

        $this->assertSame('phpclaw_admin_chat', $result['#theme']);
        $this->assertArrayHasKey('conv-scalar', $result['#conversations']);
        $this->assertSame('New conversation', $result['#conversations']['conv-scalar']['display']);
    }

    public function test_index_inner_memory_get_exception_renders_no_messages(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootContainerWithConfig([]);

        $convData = [
            'title' => 'Failing Chat',
            'updated_at' => '2026-01-01 10:00:00',
        ];

        $memory = $this->createMock(MemoryInterface::class);
        $memory->method('all')->willReturn(['conv-inner-fail' => $convData]);
        $memory->method('get')->willThrowException(new \RuntimeException('inner fail'));

        $agent = $this->createMock(ClawInterface::class);
        $agent->method('memory')->willReturn($memory);

        $controller = $this->makeController($agent);
        $result = $controller->index();

        $this->assertSame('phpclaw_admin_chat', $result['#theme']);
        $this->assertStringContainsString('Conversation started', $result['#init_messages_html']);
    }

    public function test_splice_tool_calls_into_payload_returns_payload_unchanged_when_no_tool_calls(): void
    {
        $payload = ['history' => [['role' => 'user', 'content' => 'Hello']]];
        $accessor = new class
        {
            use ConversationHistoryTrait;

            public function call(array $payload, array $calls): array
            {
                return self::spliceToolCallsIntoPayload($payload, $calls);
            }
        };

        $result = $accessor->call($payload, []);

        $this->assertSame($payload, $result);
    }

    public function test_index_with_old_datetime_covers_gmdate_branch(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootContainerWithConfig(['provider' => 'anthropic', 'model' => 'haiku']);

        $oldDate = gmdate('Y-m-d H:i:s', strtotime('-30 days'));
        $convData = [
            'title' => 'Old Chat',
            'updated_at' => $oldDate,
            'created_at' => $oldDate,
        ];

        $memory = $this->createMock(MemoryInterface::class);
        $memory->method('all')->willReturn(['conv-old' => $convData]);
        $memory->method('get')->willReturn(null);

        $agent = $this->createMock(ClawInterface::class);
        $agent->method('memory')->willReturn($memory);

        $controller = $this->makeController($agent);
        $result = $controller->index();

        $this->assertSame('phpclaw_admin_chat', $result['#theme']);
        $when = $result['#conversations']['conv-old']['when'];
        $this->assertMatchesRegularExpression('/^[A-Z][a-z]+ \d+$/', $when);
    }
}

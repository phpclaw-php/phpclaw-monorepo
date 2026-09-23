<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Unit\Controller\Admin;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Drupal\Controller\Admin\PhpClawChatStreamController;
use PhpClaw\Drupal\Exceptions\ConversationAccessDeniedException;
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
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PhpClawChatStreamControllerTest extends TestCase
{
    private ?object $csrfTokenMock = null;

    private ?object $loggerMock = null;

    private function makeController(ClawInterface $agent): PhpClawChatStreamController
    {
        return new PhpClawChatStreamController($agent, $this->loggerMock, $this->csrfTokenMock);
    }

    private function runStreamCallback(StreamedResponse $response): void
    {
        $level = ob_get_level();
        ($response->getCallback())();
        while (ob_get_level() < $level) {
            ob_start();
        }
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
            default => throw new \RuntimeException("Service '{$id}' not mocked."),
        });

        $controller = PhpClawChatStreamController::create($container);

        $this->assertInstanceOf(PhpClawChatStreamController::class, $controller);
    }

    public function test_stream_returns_streamed_response_with_sse_headers_when_csrf_invalid(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootContainer(csrfValid: false);

        $agent = $this->createMock(ClawInterface::class);
        $controller = $this->makeController($agent);
        $request = $this->makeRequest(['message' => 'hello']);

        $response = $controller->stream($request);

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertSame('text/event-stream; charset=utf-8', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
        $this->assertNotNull($response->getCallback());
    }

    public function test_stream_returns_streamed_response_when_message_empty(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootContainer(csrfValid: true);

        $agent = $this->createMock(ClawInterface::class);
        $controller = $this->makeController($agent);
        $request = $this->makeRequest(['message' => '']);

        $response = $controller->stream($request);

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertNotNull($response->getCallback());
    }

    public function test_stream_returns_streamed_response_when_message_too_long(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootContainer(csrfValid: true);

        $agent = $this->createMock(ClawInterface::class);
        $controller = $this->makeController($agent);
        $request = $this->makeRequest(['message' => str_repeat('x', 10001)]);

        $response = $controller->stream($request);

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertNotNull($response->getCallback());
    }

    public function test_stream_answers_403_when_the_csrf_token_is_invalid(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootContainer(csrfValid: false);

        $response = $this->makeController($this->createMock(ClawInterface::class))
            ->stream($this->makeRequest(['message' => 'hello']));

        $this->assertSame(
            403,
            $response->getStatusCode(),
            'A rejected stream must not answer 200: a client reading the status alone would treat it as success.',
        );
    }

    public function test_stream_answers_400_when_the_message_is_empty(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootContainer(csrfValid: true);

        $response = $this->makeController($this->createMock(ClawInterface::class))
            ->stream($this->makeRequest(['message' => '']));

        $this->assertSame(400, $response->getStatusCode());
    }

    public function test_stream_answers_400_when_the_message_is_too_long(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootContainer(csrfValid: true);

        $response = $this->makeController($this->createMock(ClawInterface::class))
            ->stream($this->makeRequest(['message' => str_repeat('x', 10001)]));

        $this->assertSame(400, $response->getStatusCode());
    }

    public function test_stream_answers_200_when_the_request_is_accepted(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootContainer(csrfValid: true);

        $agent = $this->createMock(ClawInterface::class);
        $agent->method('conversation')->willReturn($this->makeConversation('conv-stream-accepted'));

        $response = $this->makeController($agent)
            ->stream($this->makeRequest(['message' => 'hello']));

        $this->assertSame(
            200,
            $response->getStatusCode(),
            'A stream that passes every gate must still answer 200.',
        );
    }

    public function test_stream_answers_403_when_the_conversation_belongs_to_someone_else(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootContainer(csrfValid: true);

        $agent = $this->createMock(ClawInterface::class);
        $agent->method('conversation')->willThrowException(new ConversationAccessDeniedException('denied'));
        $agent->expects($this->never())->method('streamInConversation');

        $response = $this->makeController($agent)
            ->stream($this->makeRequest(['message' => 'hi', 'conversation_id' => 'someone-elses']));

        $this->assertSame(
            403,
            $response->getStatusCode(),
            'A foreign conversation must be refused with 403 before streaming starts, not 200 with an error frame.',
        );

        $this->assertNotNull($response->getCallback(), 'The refusal must still emit an SSE denial frame.');
        $this->runStreamCallback($response);
    }

    public function test_stream_callback_calls_stream_in_conversation_on_success(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootContainer(csrfValid: true);

        $conv = $this->makeConversation('conv-stream-ok');
        $turn = new ConversationTurn($this->makeResponse('streamed reply'), $conv);

        $memory = $this->createMock(MemoryInterface::class);
        $memory->method('get')->willReturn(null);

        $agent = $this->createMock(ClawInterface::class);
        $agent->method('conversation')->willReturn($conv);
        $agent->expects($this->once())->method('streamInConversation')->willReturn($turn);
        $agent->method('memory')->willReturn($memory);

        $controller = $this->makeController($agent);
        $request = $this->makeRequest(['message' => 'stream me', 'conversation_id' => 'conv-stream-ok']);

        $response = $controller->stream($request);

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->runStreamCallback($response);
    }

    public function test_stream_callback_splices_tool_calls_via_before_persist(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootContainer(csrfValid: true);

        $conv = $this->makeConversation('conv-stream-tools');
        $stored = [
            'history' => [
                ['role' => 'user',      'content' => 'run query'],
                ['role' => 'assistant', 'content' => 'Done'],
            ],
        ];
        $turn = new ConversationTurn($this->makeResponse('done with tools'), $conv);

        $memory = $this->createMock(MemoryInterface::class);
        $memory->expects($this->once())->method('set');

        $agent = $this->createMock(ClawInterface::class);
        $agent->method('conversation')->willReturn($conv);
        $agent->method('memory')->willReturn($memory);
        $agent->method('streamInConversation')->willReturnCallback(
            function (object $_conv, string $_msg, callable $_onToken, ?callable $beforePersist) use ($turn, $stored, $memory): ConversationTurn {
                HookRegistry::fire('tool.before', [
                    'tool_name' => 'db_query',
                    'tool_input' => ['query' => 'SELECT 1'],
                ]);
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
        $request = $this->makeRequest(['message' => 'run query', 'conversation_id' => 'conv-stream-tools']);

        $response = $controller->stream($request);

        $this->runStreamCallback($response);
    }

    public function test_stream_callback_handles_provider_token_hook(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootContainer(csrfValid: true);

        $conv = $this->makeConversation('conv-stream-chunk');
        $turn = new ConversationTurn($this->makeResponse('chunk reply'), $conv);
        $memory = $this->createMock(MemoryInterface::class);
        $memory->method('get')->willReturn(null);

        $tokenFired = false;
        $agent = $this->createMock(ClawInterface::class);
        $agent->method('conversation')->willReturn($conv);
        $agent->method('memory')->willReturn($memory);
        $agent->method('streamInConversation')->willReturnCallback(
            function () use ($turn, &$tokenFired): ConversationTurn {
                HookRegistry::fire('provider.token', ['token' => 'Hello']);
                HookRegistry::fire('provider.token', ['token' => '']);
                $tokenFired = true;

                return $turn;
            }
        );

        $controller = $this->makeController($agent);
        $request = $this->makeRequest(['message' => 'chunk test']);

        $response = $controller->stream($request);

        $this->runStreamCallback($response);
        $this->assertTrue($tokenFired);
    }

    public function test_a_guard_block_is_never_logged_as_an_internal_error(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootContainer(csrfValid: true);

        $conv = $this->makeConversation();
        $agent = $this->createMock(ClawInterface::class);
        $agent->method('conversation')->willReturn($conv);
        $agent->method('streamInConversation')->willThrowException(new GuardException('blocked'));

        $this->loggerMock->expects($this->never())->method('error');

        $response = $this->makeController($agent)->stream($this->makeRequest(['message' => 'inject!']));

        $this->runStreamCallback($response);
    }

    public function test_a_provider_failure_sends_its_real_message_to_the_log_channel(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootContainer(csrfValid: true);

        $conv = $this->makeConversation();
        $agent = $this->createMock(ClawInterface::class);
        $agent->method('conversation')->willReturn($conv);
        $agent->method('streamInConversation')->willThrowException(new ProviderException('api down'));

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with('@message', ['@message' => 'api down']);

        $response = $this->makeController($agent)->stream($this->makeRequest(['message' => 'stream fail']));

        $this->runStreamCallback($response);
    }

    public function test_an_unexpected_failure_sends_its_real_message_to_the_log_channel(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootContainer(csrfValid: true);

        $conv = $this->makeConversation();
        $agent = $this->createMock(ClawInterface::class);
        $agent->method('conversation')->willReturn($conv);
        $agent->method('streamInConversation')->willThrowException(new \RuntimeException('crash'));

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with('@message', ['@message' => 'crash']);

        $response = $this->makeController($agent)->stream($this->makeRequest(['message' => 'crash test']));

        $this->runStreamCallback($response);
    }

    public function test_no_stream_error_frame_carries_the_exception_text(): void
    {
        $probe = dirname(__DIR__, 2).'/Fixtures/sse-error-frame-probe.php';
        self::assertFileExists($probe);

        foreach (['guard', 'provider', 'unexpected'] as $kind) {
            $secret = 'SECRET-EXCEPTION-'.strtoupper($kind).'-'.bin2hex(random_bytes(4));

            $frames = (string) shell_exec(
                escapeshellarg(PHP_BINARY).' '.escapeshellarg($probe)
                .' '.escapeshellarg($secret).' '.escapeshellarg($kind).' 2>&1'
            );

            self::assertStringContainsString(
                'event: error',
                $frames,
                $kind.': the controller emitted no SSE error frame at all, so this proves nothing',
            );
            self::assertStringNotContainsString(
                $secret,
                $frames,
                $kind.': the exception text reached the browser. Only the operator log may carry it.',
            );
        }
    }

    public function test_stream_callback_tool_after_empty_name_is_skipped(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootContainer(csrfValid: true);

        $conv = $this->makeConversation('conv-empty-tool');
        $turn = new ConversationTurn($this->makeResponse('ok'), $conv);
        $memory = $this->createMock(MemoryInterface::class);
        $memory->expects($this->never())->method('set');

        $agent = $this->createMock(ClawInterface::class);
        $agent->method('conversation')->willReturn($conv);
        $agent->method('memory')->willReturn($memory);
        $agent->method('streamInConversation')->willReturnCallback(
            function () use ($turn): ConversationTurn {
                HookRegistry::fire('tool.after', [
                    'tool_name' => '',
                    'tool_input' => [],
                    'tool_result' => 'nothing',
                ]);

                return $turn;
            }
        );

        $controller = $this->makeController($agent);
        $request = $this->makeRequest(['message' => 'test empty tool name']);

        $response = $controller->stream($request);

        $this->runStreamCallback($response);
    }
}

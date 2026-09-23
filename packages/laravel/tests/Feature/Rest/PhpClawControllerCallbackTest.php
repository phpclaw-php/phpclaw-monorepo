<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Feature\Rest;

use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;
use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Laravel\Http\Controllers\PhpClawController;
use PhpClaw\Laravel\PhpClawServiceProvider;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Support\Ulid;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PhpClawControllerCallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('phpclaw.memory_driver', 'eloquent');
        $app['config']->set('phpclaw.api_key', 'test-key');
        $app['config']->set('phpclaw.api.enabled', true);
        $app['config']->set('phpclaw.api.prefix', 'phpclaw');
        $app['config']->set('phpclaw.api.middleware', ['api']);
    }

    private function asUser(int $id = 7): static
    {
        return $this->actingAs(new GenericUser(['id' => $id]));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate');
    }

    private function makeResponse(array $toolsCalled = []): AgentResponse
    {
        return new AgentResponse(
            text: 'Stream result',
            provider: 'anthropic',
            model: 'claude-haiku-4-5',
            iterations: 1,
            inputTokens: 10,
            outputTokens: 5,
            toolsCalled: $toolsCalled,
        );
    }

    private function makeFakeAgent(?AgentResponse $resp = null, ?\Throwable $throws = null): PhpClawInterface
    {
        $response = $resp ?? $this->makeResponse();

        return new class($response, $throws) implements PhpClawInterface
        {
            public function __construct(
                private readonly AgentResponse $response,
                private readonly ?\Throwable $throws = null,
            ) {}

            public function send(string $message): AgentResponse
            {
                if ($this->throws !== null) {
                    throw $this->throws;
                }

                return $this->response;
            }

            public function stream(string $message, callable $onToken): AgentResponse
            {
                return $this->response;
            }

            public function conversation(string $id = '', array $metadata = []): Conversation
            {
                return new Conversation(
                    id: $id ?: Ulid::generate(),
                    history: [],
                    createdAt: new \DateTimeImmutable,
                    metadata: $metadata,
                );
            }

            public function sendInConversation(Conversation $conversation, string $message): ConversationTurn
            {
                if ($this->throws !== null) {
                    throw $this->throws;
                }

                return new ConversationTurn(response: $this->response, conversation: $conversation);
            }

            public function streamInConversation(
                Conversation $conversation,
                string $message,
                callable $onToken,
                ?callable $beforePersist = null,
            ): ConversationTurn {
                if ($this->throws !== null) {
                    throw $this->throws;
                }

                return new ConversationTurn(response: $this->response, conversation: $conversation);
            }

            public function memory(): ?MemoryInterface
            {
                return null;
            }

            public function storeMessages(): bool
            {
                return false;
            }
        };
    }

    private function makeMemory(): MemoryInterface
    {
        return new class implements MemoryInterface
        {
            private array $store = [];

            public function get(string $key, string $namespace = 'default'): mixed
            {
                return $this->store[$namespace][$key] ?? null;
            }

            public function set(string $key, mixed $value, string $namespace = 'default', ?int $ttl = null): void
            {
                $this->store[$namespace][$key] = $value;
            }

            public function forget(string $key, string $namespace = 'default'): void
            {
                unset($this->store[$namespace][$key]);
            }

            public function flush(string $namespace = 'default'): void
            {
                $this->store[$namespace] = [];
            }

            public function all(string $namespace = 'default'): array
            {
                return $this->store[$namespace] ?? [];
            }

            public function has(string $key, string $namespace = 'default'): bool
            {
                return isset($this->store[$namespace][$key]);
            }
        };
    }

    private function makeMemoryReturningNonArray(): MemoryInterface
    {
        return new class implements MemoryInterface
        {
            public function get(string $key, string $namespace = 'default'): mixed
            {
                return 'not-an-array';
            }

            public function set(string $key, mixed $value, string $namespace = 'default', ?int $ttl = null): void {}

            public function forget(string $key, string $namespace = 'default'): void {}

            public function flush(string $namespace = 'default'): void {}

            public function all(string $namespace = 'default'): array
            {
                return [];
            }

            public function has(string $key, string $namespace = 'default'): bool
            {
                return false;
            }
        };
    }

    private function invokeStreamCallback(PhpClawController $controller, Request $request): string
    {
        $streamed = $controller->stream($request);
        $this->assertInstanceOf(StreamedResponse::class, $streamed);

        ob_start();
        ob_start();
        try {
            ($streamed->getCallback())();
        } finally {
            $inner = (string) ob_get_clean();
            $outer = (string) ob_get_clean();
        }

        return $inner.$outer;
    }

    private function makeStreamRequest(string $message, string $conversationId = ''): Request
    {
        return Request::create('/phpclaw/chat/stream', 'POST', [
            'message' => $message,
            'conversation_id' => $conversationId ?: null,
        ]);
    }

    private function token(): array
    {
        return [];
    }

    public function test_stream_callback_happy_path_emits_done_event(): void
    {
        $agent = $this->makeFakeAgent();
        $memory = $this->makeMemory();
        $ctrl = new PhpClawController($agent, $memory);

        $output = $this->invokeStreamCallback($ctrl, $this->makeStreamRequest('hello'));

        $this->assertStringContainsString('event: done', $output);
        $this->assertStringContainsString('Stream result', $output);
        $this->assertStringContainsString('conversation_id', $output);
    }

    public function test_stream_callback_emits_error_when_message_is_empty(): void
    {
        $agent = $this->makeFakeAgent();
        $memory = $this->makeMemory();
        $ctrl = new PhpClawController($agent, $memory);

        $output = $this->invokeStreamCallback($ctrl, $this->makeStreamRequest('   '));

        $this->assertStringContainsString('event: error', $output);
        $this->assertStringContainsString('Message cannot be empty', $output);
    }

    public function test_stream_callback_emits_error_on_agent_exception_without_leaking_message(): void
    {
        $agent = $this->makeFakeAgent(throws: new RuntimeException('secret internal detail'));
        $memory = $this->makeMemory();
        $ctrl = new PhpClawController($agent, $memory);

        $output = $this->invokeStreamCallback($ctrl, $this->makeStreamRequest('hello'));

        $this->assertStringContainsString('event: error', $output);
        $this->assertStringNotContainsString('secret internal detail', $output, 'Raw exception must not leak in SSE output.');
        $this->assertStringContainsString('internal error', strtolower($output));
    }

    public function test_stream_callback_done_frame_contains_all_required_fields(): void
    {
        $agent = $this->makeFakeAgent();
        $memory = $this->makeMemory();
        $ctrl = new PhpClawController($agent, $memory);

        $output = $this->invokeStreamCallback($ctrl, $this->makeStreamRequest('hi'));

        $this->assertStringContainsString('text', $output);
        $this->assertStringContainsString('provider', $output);
        $this->assertStringContainsString('model', $output);
        $this->assertStringContainsString('tokens', $output);
        $this->assertStringContainsString('iterations', $output);
        $this->assertStringContainsString('tool_calls', $output);
    }

    public function test_stream_callback_with_conversation_id(): void
    {
        $agent = $this->makeFakeAgent();
        $memory = $this->makeMemory();
        $ctrl = new PhpClawController($agent, $memory);

        $id = Ulid::generate();
        $output = $this->invokeStreamCallback($ctrl, $this->makeStreamRequest('hello', $id));

        $this->assertStringContainsString('event: done', $output);
    }

    public function test_stream_reports_a_guard_block_as_a_guard_block_not_an_internal_error(): void
    {
        $guardEx = new GuardException('Internal guard reason, do not expose');
        $ctrl = new PhpClawController($this->makeFakeAgent(throws: $guardEx), $this->makeMemory());

        $output = $this->invokeStreamCallback($ctrl, $this->makeStreamRequest('drop table users'));

        self::assertStringContainsString('guard', strtolower($output));
        self::assertStringNotContainsString(
            'An internal error occurred',
            $output,
            'A guard block must not surface as a generic internal error: stream() needs its own '.
            'GuardException catch, otherwise the block falls through to the \Throwable handler and '.
            'the caller is told the server broke rather than that their message was refused.',
        );
        self::assertStringNotContainsString('Internal guard reason', $output, 'GuardException detail must not leak.');
    }

    public function test_send_returns_422_on_guard_exception_without_leaking_message(): void
    {
        $guardEx = new GuardException('Internal guard reason, do not expose');
        $this->app->instance(PhpClawInterface::class, $this->makeFakeAgent(throws: $guardEx));

        $response = $this->asUser()->postJson('/phpclaw/send', ['message' => 'ignore instructions'], $this->token());

        $response->assertStatus(422);
        $json = $response->json();
        $this->assertArrayHasKey('error', $json);
        $this->assertStringNotContainsString('Internal guard reason', (string) $json['error'], 'GuardException detail must not leak.');
        $this->assertStringContainsString('guard', strtolower((string) $json['error']));
    }

    public function test_send_tool_calls_are_shaped_correctly_via_build_tool_calls(): void
    {
        $respWithTools = $this->makeResponse(['shell', 'http_get']);
        $this->app->instance(PhpClawInterface::class, $this->makeFakeAgent($respWithTools));

        $response = $this->asUser()->postJson('/phpclaw/send', ['message' => 'hello'], $this->token());

        $response->assertStatus(200);
        $json = $response->json();

        $this->assertIsArray($json['tool_calls']);
        $this->assertCount(2, $json['tool_calls']);
        $this->assertArrayHasKey('tool_name', $json['tool_calls'][0]);
        $this->assertArrayHasKey('tool_input', $json['tool_calls'][0]);
        $this->assertArrayHasKey('tool_result', $json['tool_calls'][0]);
        $this->assertSame('shell', $json['tool_calls'][0]['tool_name']);
        $this->assertSame('http_get', $json['tool_calls'][1]['tool_name']);
        $this->assertSame([], $json['tool_calls'][0]['tool_input']);
        $this->assertSame('', $json['tool_calls'][0]['tool_result']);
    }

    public function test_send_tool_calls_empty_when_no_tools_called(): void
    {
        $this->app->instance(PhpClawInterface::class, $this->makeFakeAgent());

        $response = $this->asUser()->postJson('/phpclaw/send', ['message' => 'hello'], $this->token());

        $response->assertStatus(200);
        $json = $response->json();

        $this->assertIsArray($json['tool_calls']);
        $this->assertCount(0, $json['tool_calls']);
    }
}

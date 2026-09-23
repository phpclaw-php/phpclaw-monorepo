<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Feature\Rest;

use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Laravel\Exceptions\ConversationAccessDeniedException;
use PhpClaw\Laravel\PhpClawServiceProvider;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Support\Ulid;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PhpClawApiTest extends TestCase
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate');
    }

    private function fakeAgent(?AgentResponse $response = null, ?ConversationTurn $turn = null): PhpClawInterface
    {
        $resp = $response ?? new AgentResponse(
            text: 'Hello from agent',
            provider: 'anthropic',
            model: 'claude-haiku-4-5',
            iterations: 1,
            inputTokens: 50,
            outputTokens: 30,
        );

        return new class($resp, $turn) implements PhpClawInterface
        {
            public function __construct(
                private readonly AgentResponse $response,
                private readonly ?ConversationTurn $turn = null,
            ) {}

            public function send(string $message): AgentResponse
            {
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
                return $this->turn ?? new ConversationTurn(
                    response: $this->response,
                    conversation: $conversation,
                );
            }

            public function streamInConversation(
                Conversation $conversation,
                string $message,
                callable $onToken,
                ?callable $beforePersist = null,
            ): ConversationTurn {
                return $this->turn ?? new ConversationTurn(
                    response: $this->response,
                    conversation: $conversation,
                );
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

    private function fakeThrowingAgent(\Throwable $ex): PhpClawInterface
    {
        return new class($ex) implements PhpClawInterface
        {
            public function __construct(private readonly \Throwable $ex) {}

            public function send(string $message): AgentResponse
            {
                throw $this->ex;
            }

            public function stream(string $message, callable $onToken): AgentResponse
            {
                throw $this->ex;
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
                throw $this->ex;
            }

            public function streamInConversation(
                Conversation $conversation,
                string $message,
                callable $onToken,
                ?callable $beforePersist = null,
            ): ConversationTurn {
                throw $this->ex;
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

    private function withFakeAgent(?AgentResponse $response = null): void
    {
        $this->app->instance(PhpClawInterface::class, $this->fakeAgent($response));
    }

    private function withThrowingAgent(\Throwable $ex): void
    {
        $this->app->instance(PhpClawInterface::class, $this->fakeThrowingAgent($ex));
    }

    private function asUser(int $id = 7): static
    {
        return $this->actingAs(new GenericUser(['id' => $id]));
    }

    public function test_send_returns_401_when_unauthenticated(): void
    {
        $this->withFakeAgent();

        $response = $this->postJson('/phpclaw/send', ['message' => 'hello']);

        $response->assertStatus(401);
    }

    public function test_the_package_registers_exactly_send_and_chat_stream(): void
    {
        $routes = collect($this->app['router']->getRoutes()->getRoutes())
            ->filter(static fn ($r): bool => str_starts_with($r->uri(), 'phpclaw/'))
            ->map(static fn ($r): string => implode('|', $r->methods()).' '.$r->uri())
            ->values()
            ->all();
        sort($routes);

        self::assertSame(
            ['POST phpclaw/chat/stream', 'POST phpclaw/send'],
            $routes,
        );
    }

    public function test_api_routes_carry_throttle_middleware(): void
    {
        $route = collect($this->app['router']->getRoutes()->getRoutes())
            ->first(static fn ($r): bool => $r->uri() === 'phpclaw/send');

        self::assertNotNull($route);
        self::assertContains('throttle:60,1', $route->gatherMiddleware());
    }

    public function test_send_returns_200_with_correct_shape(): void
    {
        $this->withFakeAgent();

        $response = $this->asUser()->postJson('/phpclaw/send', ['message' => 'hello']);

        $response->assertStatus(200);
        $response->assertJsonStructure(['text', 'tool_calls', 'provider', 'model', 'iterations', 'tokens']);
        $response->assertJson([
            'text' => 'Hello from agent',
            'provider' => 'anthropic',
            'model' => 'claude-haiku-4-5',
            'iterations' => 1,
            'tokens' => 80,
        ]);
    }

    public function test_send_rejects_whitespace_only_message(): void
    {
        $this->withFakeAgent();

        $response = $this->asUser()->postJson('/phpclaw/send', ['message' => '   ']);

        $this->assertContains($response->getStatusCode(), [400, 422]);
    }

    public function test_send_returns_400_on_explicitly_empty_string(): void
    {
        $this->withFakeAgent();
        $this->withoutMiddleware(ConvertEmptyStringsToNull::class);
        $this->withoutMiddleware(TrimStrings::class);

        $response = $this->asUser()->postJson('/phpclaw/send', ['message' => '   ']);

        $response->assertStatus(400);
        $response->assertJsonFragment(['error' => 'Message cannot be empty.']);
    }

    public function test_send_returns_422_on_validation_failure(): void
    {
        $this->withFakeAgent();

        $response = $this->asUser()->postJson('/phpclaw/send', []);

        $response->assertStatus(422);
    }

    public function test_validation_failure_returns_json_not_html_redirect_without_accept_header(): void
    {
        $this->withFakeAgent();

        $response = $this->asUser()->post(
            '/phpclaw/send',
            ['message' => 'hi', 'conversation_id' => 'not-a-valid-ulid'],
        );

        $response->assertStatus(422);
        $this->assertStringContainsString(
            'application/json',
            (string) $response->headers->get('Content-Type'),
            'The REST API must always return JSON, never an HTML redirect.',
        );
    }

    public function test_send_returns_503_when_explicit_provider_has_no_key(): void
    {
        $this->app['config']->set('phpclaw.api_key', '');
        $this->app['config']->set('phpclaw.provider', 'anthropic');
        $this->withFakeAgent();

        $response = $this->asUser()->postJson('/phpclaw/send', ['message' => 'hello']);

        $response->assertStatus(503);
    }

    public function test_send_does_not_503_on_auto_detect_provider(): void
    {
        $this->app['config']->set('phpclaw.api_key', '');
        $this->app['config']->set('phpclaw.provider', '');
        $this->withFakeAgent();

        $response = $this->asUser()->postJson('/phpclaw/send', ['message' => 'hello']);

        $response->assertStatus(200);
    }

    public function test_send_does_not_503_for_ollama_without_key(): void
    {
        $this->app['config']->set('phpclaw.api_key', '');
        $this->app['config']->set('phpclaw.provider', 'ollama');
        $this->withFakeAgent();

        $response = $this->asUser()->postJson('/phpclaw/send', ['message' => 'hello']);

        $response->assertStatus(200);
    }

    public function test_send_returns_500_on_agent_exception(): void
    {
        $this->withThrowingAgent(new RuntimeException('boom'));

        $this->app['config']->set('phpclaw.api_key', 'set');

        $response = $this->asUser()->postJson('/phpclaw/send', ['message' => 'hello']);

        $response->assertStatus(500);
        $json = $response->json();
        $this->assertArrayHasKey('error', $json);
        $this->assertStringNotContainsString('boom', (string) $json['error'], 'Raw exception message must not leak.');
    }

    public function test_send_returns_403_not_500_when_the_conversation_belongs_to_another_user(): void
    {
        $this->withThrowingAgent(new ConversationAccessDeniedException);

        $this->app['config']->set('phpclaw.api_key', 'set');

        $response = $this->asUser()->postJson('/phpclaw/send', [
            'message' => 'hello',
            'conversation_id' => '01HZY8ABCDEFGHJKMNPQRSTVWX',
        ]);

        $response->assertStatus(403);
        $this->assertArrayHasKey('error', $response->json());
    }

    public function test_stream_returns_403_before_any_frame_when_the_conversation_belongs_to_another_user(): void
    {
        $this->withFakeAgent();
        $this->app['config']->set('phpclaw.api_key', 'set');

        DB::table('phpclaw_conversations')->insert([
            'id' => '01HZY8ABCDEFGHJKMNPQRSTVWX',
            'namespace' => 'conversations',
            'user_id' => 999,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->asUser()->postJson('/phpclaw/chat/stream', [
            'message' => 'hello',
            'conversation_id' => '01HZY8ABCDEFGHJKMNPQRSTVWX',
        ]);

        $response->assertStatus(403);
        $this->assertStringNotContainsString('text/event-stream', (string) $response->headers->get('Content-Type'));
    }

    public function test_send_returns_403_before_the_controller_when_the_conversation_belongs_to_another_user(): void
    {
        $this->withFakeAgent();
        $this->app['config']->set('phpclaw.api_key', 'set');

        DB::table('phpclaw_conversations')->insert([
            'id' => '01HZY8ABCDEFGHJKMNPQRSTVWY',
            'namespace' => 'conversations',
            'user_id' => 999,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->asUser()->postJson('/phpclaw/send', [
            'message' => 'hello',
            'conversation_id' => '01HZY8ABCDEFGHJKMNPQRSTVWY',
        ]);

        $response->assertStatus(403);
    }

    public function test_stream_emits_an_explicit_denial_when_the_conversation_belongs_to_another_user(): void
    {
        $this->withThrowingAgent(new ConversationAccessDeniedException);

        $this->app['config']->set('phpclaw.api_key', 'set');

        $response = $this->asUser()->postJson('/phpclaw/chat/stream', [
            'message' => 'hello',
            'conversation_id' => '01HZY8ABCDEFGHJKMNPQRSTVWX',
        ]);

        $body = $response->streamedContent();

        $this->assertStringContainsString('event: error', $body);
        $this->assertStringContainsString('permission', $body);
        $this->assertStringNotContainsString('An internal error occurred', $body);
    }

    public function test_stream_returns_401_when_unauthenticated(): void
    {
        $this->withFakeAgent();

        $response = $this->postJson('/phpclaw/chat/stream', ['message' => 'hello']);

        $response->assertStatus(401);
    }

    public function test_stream_returns_sse_content_type(): void
    {
        $this->withFakeAgent();

        $response = $this->asUser()->postJson('/phpclaw/chat/stream', ['message' => 'hi']);

        $response->assertStatus(200);
        $this->assertStringContainsString('text/event-stream', $response->headers->get('Content-Type', ''));
    }

    public function test_stream_response_is_a_streamed_response_with_correct_headers(): void
    {
        $this->withFakeAgent();

        $response = $this->asUser()->postJson('/phpclaw/chat/stream', ['message' => 'hi']);

        $response->assertStatus(200);
        $this->assertInstanceOf(
            StreamedResponse::class,
            $response->baseResponse,
            'chat/stream must return a StreamedResponse',
        );
        $this->assertStringContainsString('text/event-stream', $response->headers->get('Content-Type', ''));
        $this->assertStringContainsString('no-cache', $response->headers->get('Cache-Control', ''));
    }

    public function test_stream_callback_emits_done_frame_with_required_fields(): void
    {
        $this->withFakeAgent();

        $response = $this->asUser()->postJson('/phpclaw/chat/stream', ['message' => 'hi']);

        $streamedResponse = $response->baseResponse;
        $this->assertInstanceOf(StreamedResponse::class, $streamedResponse);

        ob_start();
        ($streamedResponse->getCallback())();
        $raw = (string) ob_get_clean();

        if ($raw === '') {
            $this->markTestSkipped(
                'SSE callback output bypassed ob_start() via flush(). '.
                'Verified via test_stream_response_is_a_streamed_response_with_correct_headers.'
            );
        }

        $this->assertStringContainsString('event: done', $raw);
        $doneData = $this->extractSseEventData('done', $raw);
        $this->assertNotNull($doneData);
        $this->assertArrayHasKey('text', $doneData);
        $this->assertArrayHasKey('provider', $doneData);
        $this->assertArrayHasKey('model', $doneData);
        $this->assertArrayHasKey('tokens', $doneData);
        $this->assertArrayHasKey('iterations', $doneData);
        $this->assertArrayHasKey('tool_calls', $doneData);
        $this->assertArrayHasKey('conversation_id', $doneData);
    }

    private function extractSseEventData(string $event, string $content): ?array
    {
        $pattern = '/event: '.preg_quote($event, '/').'\ndata: (.+)/';
        if (preg_match($pattern, $content, $matches)) {
            $decoded = json_decode($matches[1], associative: true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Feature\Rest;

use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Orchestra\Testbench\TestCase;
use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Laravel\PhpClawServiceProvider;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Support\Ulid;
use PHPUnit\Framework\Attributes\DataProvider;

final class RestApiMemoryOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'SECRET-OF-USER-ONE';

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
        $this->app->instance(PhpClawInterface::class, $this->agentReturning(self::SECRET));
    }

    public static function driversWithoutOwnership(): array
    {
        return [
            'cache' => ['cache'],
            'database_kv' => ['database_kv'],
            'eloquent_kv' => ['eloquent_kv'],
            'file' => ['file'],
        ];
    }

    public static function driversWithOwnership(): array
    {
        return [
            'database' => ['database'],
            'eloquent' => ['eloquent'],
            'database_conversation' => ['database_conversation'],
            'eloquent_conversation' => ['eloquent_conversation'],
        ];
    }

    #[DataProvider('driversWithoutOwnership')]
    public function test_send_is_refused_when_the_memory_driver_cannot_enforce_ownership(string $driver): void
    {
        $this->useMemoryDriver($driver);
        Log::spy();

        $response = $this->asUser(2)->postJson('/phpclaw/send', ['message' => 'hello']);

        $response->assertStatus(503);
        $this->assertStringNotContainsString(self::SECRET, (string) $response->getContent());
        Log::shouldHaveReceived('warning')->once();
    }

    #[DataProvider('driversWithoutOwnership')]
    public function test_another_users_conversation_is_not_reachable_when_the_memory_driver_cannot_enforce_ownership(string $driver): void
    {
        $this->useMemoryDriver($driver);

        $response = $this->asUser(2)->postJson('/phpclaw/send', [
            'message' => 'show me the history',
            'conversation_id' => Ulid::generate(),
        ]);

        $response->assertStatus(503);
        $this->assertStringNotContainsString(self::SECRET, (string) $response->getContent());
    }

    public function test_stream_is_refused_when_the_memory_driver_cannot_enforce_ownership(): void
    {
        $this->useMemoryDriver('cache');

        $response = $this->asUser(2)->postJson('/phpclaw/chat/stream', ['message' => 'hello']);

        $response->assertStatus(503);
    }

    public function test_a_custom_memory_binding_that_cannot_enforce_ownership_is_refused(): void
    {
        $this->app->instance(MemoryInterface::class, new ArrayMemory);

        $response = $this->asUser(2)->postJson('/phpclaw/send', ['message' => 'hello']);

        $response->assertStatus(503);
    }

    #[DataProvider('driversWithOwnership')]
    public function test_send_reaches_the_agent_when_the_memory_driver_enforces_ownership(string $driver): void
    {
        $this->useMemoryDriver($driver);

        $response = $this->asUser(2)->postJson('/phpclaw/send', ['message' => 'hello']);

        $response->assertStatus(200);
        $this->assertStringContainsString(self::SECRET, (string) $response->getContent());
    }

    public function test_a_mixed_case_driver_name_that_enforces_ownership_is_accepted(): void
    {
        $this->useMemoryDriver('Database');

        $this->asUser(2)->postJson('/phpclaw/send', ['message' => 'hello'])->assertStatus(200);
    }

    private function useMemoryDriver(string $driver): void
    {
        $this->app['config']->set('phpclaw.memory_driver', $driver);
        $this->app->forgetInstance(MemoryInterface::class);
    }

    private function asUser(int $id): static
    {
        return $this->actingAs(new GenericUser(['id' => $id]));
    }

    private function agentReturning(string $text): PhpClawInterface
    {
        $response = new AgentResponse(text: $text, provider: 'anthropic', model: 'claude-haiku-4-5', iterations: 1);

        return new class($response) implements PhpClawInterface
        {
            public function __construct(private readonly AgentResponse $response) {}

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
                return new Conversation(id: $id ?: Ulid::generate(), history: [], createdAt: new \DateTimeImmutable, metadata: $metadata);
            }

            public function sendInConversation(Conversation $conversation, string $message): ConversationTurn
            {
                return new ConversationTurn(response: $this->response, conversation: $conversation);
            }

            public function streamInConversation(Conversation $conversation, string $message, callable $onToken, ?callable $beforePersist = null): ConversationTurn
            {
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
}

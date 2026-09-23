<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Feature\Jobs;

use Orchestra\Testbench\TestCase;
use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Laravel\Jobs\RunAgentJob;
use PhpClaw\Laravel\PhpClawServiceProvider;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Memory\Contracts\MemoryInterface;
use RuntimeException;

final class RunAgentJobTest extends TestCase
{
    private ArrayMemory $memory;

    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->memory = new ArrayMemory;
        $this->app->instance(MemoryInterface::class, $this->memory);
    }

    public function test_handle_stores_done_result_on_success(): void
    {
        $agent = $this->fakeAgentReturning(new AgentResponse(
            text: 'hello from the agent',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            iterations: 2,
            inputTokens: 100,
            outputTokens: 50,
            durationMs: 1234,
        ));

        $job = new RunAgentJob(jobId: 'job_1', message: 'ping', ttl: 60);
        $job->handle($agent, $this->memory);

        $stored = $this->memory->get('job_1', RunAgentJob::NAMESPACE);

        $this->assertIsArray($stored);
        $this->assertSame('done', $stored['status']);
        $this->assertSame('hello from the agent', $stored['text']);
        $this->assertSame('anthropic', $stored['provider']);
        $this->assertSame('claude-haiku-4-5-20251001', $stored['model']);
        $this->assertSame(150, $stored['tokens']);
        $this->assertSame(2, $stored['iterations']);
        $this->assertSame(1234, $stored['duration_ms']);
        $this->assertArrayHasKey('at', $stored);
    }

    public function test_handle_handles_null_token_counts(): void
    {
        $agent = $this->fakeAgentReturning(new AgentResponse(
            text: 'no usage data',
            provider: 'groq',
            model: 'llama',
            iterations: 1,
            inputTokens: null,
            outputTokens: null,
        ));

        $job = new RunAgentJob(jobId: 'job_nul', message: 'ping');
        $job->handle($agent, $this->memory);

        $stored = $this->memory->get('job_nul', RunAgentJob::NAMESPACE);

        $this->assertIsArray($stored);
        $this->assertSame(0, $stored['tokens']);
    }

    public function test_handle_stores_failed_result_and_rethrows(): void
    {
        $agent = new class implements PhpClawInterface
        {
            public function send(string $message): AgentResponse
            {
                throw new RuntimeException('boom');
            }

            public function stream(string $message, callable $onToken): AgentResponse
            {
                throw new RuntimeException('not used');
            }

            public function conversation(string $id = '', array $metadata = []): Conversation
            {
                throw new RuntimeException('not used');
            }

            public function sendInConversation(Conversation $conversation, string $message): ConversationTurn
            {
                throw new RuntimeException('not used');
            }

            public function streamInConversation(Conversation $conversation, string $message, callable $onToken, ?callable $beforePersist = null): ConversationTurn
            {
                throw new RuntimeException('not used');
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

        $job = new RunAgentJob(jobId: 'job_boom', message: 'ping');

        $thrown = null;
        try {
            $job->handle($agent, $this->memory);
        } catch (RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'handle() must re-throw so the queue can retry');
        $this->assertSame('boom', $thrown->getMessage());

        $stored = $this->memory->get('job_boom', RunAgentJob::NAMESPACE);

        $this->assertIsArray($stored);
        $this->assertSame('failed', $stored['status']);
        $this->assertSame('Job failed, see application log', $stored['error']);
        $this->assertNotSame('boom', $stored['error'], 'Raw exception message must not be stored (Point M leak)');
        $this->assertArrayHasKey('at', $stored);
    }

    public function test_namespace_constant_is_phpclaw_jobs(): void
    {
        $this->assertSame('phpclaw_jobs', RunAgentJob::NAMESPACE);
    }

    private function fakeAgentReturning(AgentResponse $response): PhpClawInterface
    {
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
                throw new RuntimeException('not used');
            }

            public function sendInConversation(Conversation $conversation, string $message): ConversationTurn
            {
                throw new RuntimeException('not used');
            }

            public function streamInConversation(Conversation $conversation, string $message, callable $onToken, ?callable $beforePersist = null): ConversationTurn
            {
                throw new RuntimeException('not used');
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

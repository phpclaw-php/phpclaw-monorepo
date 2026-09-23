<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Feature\Jobs;

use Orchestra\Testbench\TestCase;
use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Laravel\Jobs\RunAgentJob;
use PhpClaw\Laravel\PhpClawServiceProvider;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Memory\Contracts\MemoryInterface;
use RuntimeException;

final class RunAgentJobRunIdTest extends TestCase
{
    private ArrayMemory $memory;

    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        HookRegistry::reset();
        $this->memory = new ArrayMemory;
        $this->app->instance(MemoryInterface::class, $this->memory);
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
        parent::tearDown();
    }

    public function test_job_completed_carries_run_id(): void
    {
        $captured = [];

        HookRegistry::on('job.completed', static function (array $ctx) use (&$captured): void {
            $captured = $ctx;
        });

        $agent = $this->fakeAgentReturning(new AgentResponse(
            text: 'done',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            iterations: 1,
            runId: 'run-abc-123',
        ));

        $job = new RunAgentJob(jobId: 'job_1', message: 'ping');
        $job->handle($agent, $this->memory);

        $this->assertArrayHasKey('run_id', $captured);
        $this->assertSame('run-abc-123', $captured['run_id']);
        $this->assertSame('job_1', $captured['job_id']);
    }

    public function test_job_started_has_no_run_id(): void
    {
        $captured = [];

        HookRegistry::on('job.started', static function (array $ctx) use (&$captured): void {
            $captured = $ctx;
        });

        $agent = $this->fakeAgentReturning(new AgentResponse(
            text: 'done',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            iterations: 1,
            runId: 'run-xyz',
        ));

        $job = new RunAgentJob(jobId: 'job_2', message: 'ping');
        $job->handle($agent, $this->memory);

        $this->assertArrayNotHasKey('run_id', $captured);
    }

    public function test_job_failed_has_no_run_id(): void
    {
        $captured = [];

        HookRegistry::on('job.failed', static function (array $ctx) use (&$captured): void {
            $captured = $ctx;
        });

        $agent = $this->fakeAgentThrowing(new RuntimeException('boom'));

        $job = new RunAgentJob(jobId: 'job_3', message: 'ping');

        try {
            $job->handle($agent, $this->memory);
        } catch (RuntimeException) {
        }

        $this->assertArrayNotHasKey('run_id', $captured);
        $this->assertSame('job_3', $captured['job_id']);
    }

    public function test_job_completed_omits_run_id_when_empty(): void
    {
        $captured = [];

        HookRegistry::on('job.completed', static function (array $ctx) use (&$captured): void {
            $captured = $ctx;
        });

        $agent = $this->fakeAgentReturning(new AgentResponse(
            text: 'done',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            iterations: 1,
        ));

        $job = new RunAgentJob(jobId: 'job_4', message: 'ping');
        $job->handle($agent, $this->memory);

        $this->assertArrayNotHasKey('run_id', $captured);
    }

    private function fakeAgentReturning(AgentResponse $response): PhpClawInterface
    {
        return new class($response) implements PhpClawInterface
        {
            public function __construct(private readonly AgentResponse $r) {}

            public function send(string $message): AgentResponse
            {
                return $this->r;
            }

            public function stream(string $message, callable $onToken): AgentResponse
            {
                return $this->r;
            }

            public function conversation(string $id = '', array $metadata = []): Conversation
            {
                throw new RuntimeException('not used');
            }

            public function sendInConversation(Conversation $c, string $message): ConversationTurn
            {
                throw new RuntimeException('not used');
            }

            public function streamInConversation(Conversation $c, string $message, callable $onToken, ?callable $beforePersist = null): ConversationTurn
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

    private function fakeAgentThrowing(\Throwable $e): PhpClawInterface
    {
        return new class($e) implements PhpClawInterface
        {
            public function __construct(private readonly \Throwable $e) {}

            public function send(string $message): AgentResponse
            {
                throw $this->e;
            }

            public function stream(string $message, callable $onToken): AgentResponse
            {
                throw $this->e;
            }

            public function conversation(string $id = '', array $metadata = []): Conversation
            {
                throw new RuntimeException('not used');
            }

            public function sendInConversation(Conversation $c, string $message): ConversationTurn
            {
                throw new RuntimeException('not used');
            }

            public function streamInConversation(Conversation $c, string $message, callable $onToken, ?callable $beforePersist = null): ConversationTurn
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

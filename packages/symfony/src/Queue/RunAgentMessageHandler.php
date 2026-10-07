<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Queue;

use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Exceptions\RunSuspendedException;
use PhpClaw\Hooks\HookDispatcher;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Symfony\SymfonyIdentityResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Consumes RunAgentMessage, runs PhpClaw::send(), and persists the result under phpclaw_jobs.
 */
#[AsMessageHandler]
final class RunAgentMessageHandler
{
    /**
     * Constructs the handler with the phpClaw engine, memory driver, and optional logger.
     *
     * @param  PhpClawInterface  $phpclaw  The phpClaw engine for agent execution.
     * @param  MemoryInterface  $memory  Memory driver for persisting job results.
     * @param  LoggerInterface|null  $logger  Optional PSR-3 logger for failure records.
     * @param  SymfonyIdentityResolver|null  $identity  Resolver the run acts through; null leaves the worker unidentified.
     */
    public function __construct(
        private readonly PhpClawInterface $phpclaw,
        private readonly MemoryInterface $memory,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?SymfonyIdentityResolver $identity = null,
    ) {}

    /**
     * Run the agent and store the result; a run that pauses is stored as suspended and not retried.
     *
     * @param  RunAgentMessage  $msg
     * @return void
     */
    public function __invoke(RunAgentMessage $msg): void
    {
        $this->identity?->actAs($msg->userId);

        HookDispatcher::jobStarted($msg->jobId, $msg->message);

        try {
            $response = $this->phpclaw->send($msg->message);
            $totalTokens = ($response->inputTokens ?? 0) + ($response->outputTokens ?? 0);

            HookDispatcher::jobCompleted(
                jobId: $msg->jobId,
                message: $msg->message,
                provider: $response->provider,
                model: $response->model,
                iterations: $response->iterations,
                durationMs: $response->durationMs,
                tokens: $totalTokens,
                runId: $response->runId,
            );

            $this->storeResult($msg, [
                'status' => 'done',
                'text' => $response->text,
                'provider' => $response->provider,
                'model' => $response->model,
                'tokens' => $totalTokens,
                'iterations' => $response->iterations,
                'duration_ms' => $response->durationMs,
                'at' => gmdate('c'),
                'run_id' => $response->runId,
            ]);
        } catch (RunSuspendedException $e) {
            $this->storeResult($msg, [
                'status' => 'suspended',
                'run_id' => $e->runId,
                'run_status' => $e->status->value,
                'at' => gmdate('c'),
            ]);
        } catch (\Throwable $e) {
            $this->logger?->error('RunAgentMessageHandler failed.', [
                'job_id' => $msg->jobId,
                'exception' => $e,
            ]);

            HookDispatcher::jobFailed($msg->jobId, $msg->message, $e->getMessage(), $e::class);

            $this->storeResult($msg, [
                'status' => 'failed',
                'error' => 'Job failed, see application log.',
                'at' => gmdate('c'),
            ]);

            throw $e;
        } finally {
            $this->identity?->stopActing();
        }
    }

    /**
     * Store the job's result under its id, with the owner, for as long as the job asked.
     *
     * @param  RunAgentMessage  $msg  The job.
     * @param  array<string, mixed>  $result  Result fields.
     * @return void
     */
    private function storeResult(RunAgentMessage $msg, array $result): void
    {
        $this->memory->set(
            key: $msg->jobId,
            value: [...$result, RunAgentMessage::OWNER_KEY => $msg->userId],
            namespace: RunAgentMessage::NAMESPACE,
            ttl: $msg->ttl,
        );
    }
}

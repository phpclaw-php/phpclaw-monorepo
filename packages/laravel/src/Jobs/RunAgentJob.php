<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Hooks\HookDispatcher;
use PhpClaw\Laravel\Enums\JobStatus;
use PhpClaw\Memory\Contracts\MemoryInterface;

/**
 * Queueable job that runs PhpClaw::send() asynchronously and stores the result under the `phpclaw_jobs` memory namespace.
 */
final class RunAgentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const NAMESPACE = 'phpclaw_jobs';

    public const OWNER_KEY = 'user_id';

    /**
     * Bind the job id and message this queued run stores its result under.
     *
     * @param  string  $jobId  ULID identifying this job's stored result.
     * @param  string  $message  The message to send to the agent.
     * @param  int  $ttl  Result lifetime in seconds.
     * @param  string  $userId  Owner captured at dispatch; the worker has no authenticated user.
     * @return void
     */
    public function __construct(
        public readonly string $jobId,
        public readonly string $message,
        public readonly int $ttl = 3600,
        public readonly string $userId = '',
    ) {}

    /**
     * Run the agent and persist the outcome under the `phpclaw_jobs` namespace.
     *
     * @param  PhpClawInterface  $agent  The resolved phpClaw engine.
     * @param  MemoryInterface  $memory  The resolved memory driver.
     * @return void
     */
    public function handle(PhpClawInterface $agent, MemoryInterface $memory): void
    {
        $this->actAsQueueingUser();

        HookDispatcher::jobStarted($this->jobId, $this->message);

        try {
            $response = $agent->send($this->message);

            HookDispatcher::jobCompleted(
                jobId: $this->jobId,
                message: $this->message,
                provider: $response->provider,
                model: $response->model,
                iterations: $response->iterations,
                durationMs: $response->durationMs,
                tokens: ($response->inputTokens ?? 0) + ($response->outputTokens ?? 0),
                runId: $response->runId,
            );

            $memory->set(
                key: $this->jobId,
                value: [
                    'status' => JobStatus::Done->value,
                    'text' => $response->text,
                    'provider' => $response->provider,
                    'model' => $response->model,
                    'tokens' => ($response->inputTokens ?? 0) + ($response->outputTokens ?? 0),
                    'iterations' => $response->iterations,
                    'duration_ms' => $response->durationMs,
                    'at' => date('c'),
                    'run_id' => $response->runId,
                    self::OWNER_KEY => $this->userId,
                ],
                namespace: self::NAMESPACE,
                ttl: $this->ttl,
            );
        } catch (\Throwable $e) {
            report($e);

            HookDispatcher::jobFailed($this->jobId, $this->message, $e->getMessage(), get_class($e));

            $memory->set(
                key: $this->jobId,
                value: [
                    'status' => JobStatus::Failed->value,
                    'error' => 'Job failed, see application log',
                    'at' => date('c'),
                    self::OWNER_KEY => $this->userId,
                ],
                namespace: self::NAMESPACE,
                ttl: $this->ttl,
            );

            throw $e;
        } finally {
            $this->stopActingAsQueueingUser();
        }
    }

    /**
     * Authenticate the worker as the user who queued this job, so every capability check the
     * run makes answers for that user rather than for nobody.
     *
     * @return void
     */
    private function actAsQueueingUser(): void
    {
        if ($this->userId === '') {
            return;
        }

        try {
            Auth::onceUsingId($this->userId);
        } catch (\Throwable) {
            return;
        }
    }

    /**
     * Drop the acting user again so a long-lived worker never carries one job's identity
     * into the next job it picks up.
     *
     * @return void
     */
    private function stopActingAsQueueingUser(): void
    {
        try {
            Auth::forgetUser();
        } catch (\Throwable) {
            return;
        }
    }
}

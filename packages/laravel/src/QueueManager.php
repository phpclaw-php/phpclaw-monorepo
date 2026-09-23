<?php

declare(strict_types=1);

namespace PhpClaw\Laravel;

use PhpClaw\Laravel\Jobs\RunAgentJob;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Support\Ulid;

/**
 * Dispatches phpClaw agent calls onto a queue and provides polling for their results.
 */
final class QueueManager
{
    /**
     * Bind the memory driver this manager stores and reads job results through.
     *
     * @param  MemoryInterface  $memory  The memory driver used to store job results.
     * @return void
     */
    public function __construct(
        private readonly MemoryInterface $memory,
    ) {}

    /**
     * Dispatch a phpClaw send() onto the queue and return the job's ULID.
     *
     * @param  string  $message  The message to send to the agent.
     * @param  int  $ttl  Seconds to retain the result after completion.
     * @return string The ULID assigned to the queued job.
     */
    public function dispatchSend(string $message, int $ttl = 3600): string
    {
        $jobId = Ulid::generate();

        RunAgentJob::dispatch($jobId, $message, $ttl, LaravelIdentityResolver::actingUserId());

        return $jobId;
    }

    /**
     * Retrieve a job's result, or null while it is pending or expired.
     *
     * @param  string  $jobId  The ULID returned by dispatchSend().
     * @return array<string, mixed>|null
     */
    public function pollResult(string $jobId): ?array
    {
        $value = $this->memory->get($jobId, RunAgentJob::NAMESPACE);

        if (! is_array($value) || ! $this->ownedByActingUser($value)) {
            return null;
        }

        return $value;
    }

    /**
     * List every job result currently held under the phpclaw_jobs namespace.
     *
     * @return array<string, array<string, mixed>> Keyed by jobId.
     */
    public function listJobs(): array
    {
        $all = array_filter($this->memory->all(RunAgentJob::NAMESPACE), 'is_array');

        return array_filter($all, fn (array $job): bool => $this->ownedByActingUser($job));
    }

    /**
     * Forget a single job's result (no-op if the jobId is unknown).
     *
     * @param  string  $jobId  The ULID of the job result to forget.
     * @return void
     */
    public function forgetJob(string $jobId): void
    {
        if ($this->pollResult($jobId) === null) {
            return;
        }

        $this->memory->forget($jobId, RunAgentJob::NAMESPACE);
    }

    /**
     * Whether a stored job result belongs to the acting user. Results predating ownership
     * carry no owner key and are treated as the empty no-logged-in-user sentinel.
     *
     * @param  array<string, mixed>  $job  A stored job result payload.
     * @return bool
     */
    private function ownedByActingUser(array $job): bool
    {
        if (LaravelIdentityResolver::manageAll()) {
            return true;
        }

        return (string) ($job[RunAgentJob::OWNER_KEY] ?? '') === LaravelIdentityResolver::actingUserId();
    }
}

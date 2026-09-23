<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Queue;

/**
 * DTO dispatched onto Symfony Messenger carrying only scalar data.
 */
final class RunAgentMessage
{
    public const NAMESPACE = 'phpclaw_jobs';

    public const OWNER_KEY = 'user_id';

    /**
     * Constructs a new agent run message with the given job ID, message, and TTL.
     *
     * @param  string  $jobId  ULID identifying this job in the result store.
     * @param  string  $message  The message or task to send to the agent.
     * @param  int  $ttl  Seconds until the job result expires (default: 3600).
     * @param  string  $userId  Owner captured at dispatch; the worker has no authenticated user.
     */
    public function __construct(
        public readonly string $jobId,
        public readonly string $message,
        public readonly int $ttl = 3600,
        public readonly string $userId = '',
    ) {}
}

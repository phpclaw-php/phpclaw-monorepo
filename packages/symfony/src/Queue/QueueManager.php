<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Queue;

use PhpClaw\Exceptions\AdapterException;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Support\Ulid;
use PhpClaw\Symfony\Console\ConsoleContext;
use PhpClaw\Symfony\SymfonyIdentityResolver;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Dispatches phpClaw agent calls onto Symfony Messenger and polls for results.
 */
final class QueueManager
{
    /**
     * Constructs the queue manager with a memory driver and optional Messenger bus.
     *
     * @param  MemoryInterface  $memory  Memory driver for job result storage.
     * @param  MessageBusInterface|null  $bus  Optional Symfony Messenger bus for dispatching.
     * @param  SymfonyIdentityResolver|null  $identity  Resolver naming the acting user; null scopes every read to the empty sentinel.
     * @param  ConsoleContext|null  $console  Marks an interactive console run, which reads every job; null is not console.
     */
    public function __construct(
        private readonly MemoryInterface $memory,
        private readonly ?MessageBusInterface $bus = null,
        private readonly ?SymfonyIdentityResolver $identity = null,
        private readonly ?ConsoleContext $console = null,
    ) {}

    /**
     * Dispatches an agent task onto the Messenger bus and returns the job ID.
     *
     * @param  string  $message
     * @param  int  $ttl
     * @return string
     *
     * @throws AdapterException when symfony/messenger is not installed.
     */
    public function dispatchSend(string $message, int $ttl = 3600): string
    {
        if ($this->bus === null) {
            throw new AdapterException(
                'phpClaw queue dispatch requires symfony/messenger. '
                .'Install it with: composer require symfony/messenger. '
                .'Then configure a transport in config/packages/messenger.yaml.',
            );
        }

        $jobId = Ulid::generate();

        $this->bus->dispatch(new RunAgentMessage($jobId, $message, $ttl, $this->actingUserId()));

        return $jobId;
    }

    /**
     * Returns the stored job result array, or null when the job is not yet complete.
     *
     * @param  string  $jobId  Job ULID returned by dispatchSend().
     * @return array<string, mixed>|null
     */
    public function pollResult(string $jobId): ?array
    {
        $value = $this->memory->get($jobId, RunAgentMessage::NAMESPACE);

        if (! is_array($value) || ! $this->ownedByActingUser($value)) {
            return null;
        }

        return $value;
    }

    /**
     * Returns all stored job result arrays, keyed by job ID.
     *
     * @return array<string, array<string, mixed>>
     */
    public function listJobs(): array
    {
        $all = array_filter($this->memory->all(RunAgentMessage::NAMESPACE), 'is_array');

        return array_filter($all, fn (array $job): bool => $this->ownedByActingUser($job));
    }

    /**
     * Deletes a job result from memory.
     *
     * @param  string  $jobId
     * @return void
     */
    public function forgetJob(string $jobId): void
    {
        if ($this->pollResult($jobId) === null) {
            return;
        }

        $this->memory->forget($jobId, RunAgentMessage::NAMESPACE);
    }

    /**
     * The acting user identifier, or the empty no-logged-in-user sentinel.
     *
     * @return string
     */
    private function actingUserId(): string
    {
        return $this->identity?->actingUserId() ?? '';
    }

    /**
     * Whether a stored job result belongs to the acting user; an interactive console run reads every
     * job, and a queue worker never counts as console so it stays scoped to its own job's owner.
     *
     * @param  array<string, mixed>  $job  A stored job result payload.
     * @return bool
     */
    private function ownedByActingUser(array $job): bool
    {
        if ($this->console?->isConsole() === true) {
            return true;
        }

        if ($this->identity?->manageAll() === true) {
            return true;
        }

        return (string) ($job[RunAgentMessage::OWNER_KEY] ?? '') === $this->actingUserId();
    }

    /**
     * Returns true when symfony/messenger is available in the current environment.
     *
     * @return bool
     */
    public static function messengerInstalled(): bool
    {
        return interface_exists(MessageBusInterface::class);
    }
}

<?php

declare(strict_types=1);

namespace PhpClaw\Agent;

use PhpClaw\Exceptions\RunStateException;

/**
 * Everything needed to continue a run in another process, as plain data with no closures, tools or providers.
 */
final class RunState
{
    /**
     * Build a run state.
     *
     * @param  RunTask  $task  What the run was asked to do, with its id.
     * @param  RunStatus  $status  Lifecycle status.
     * @param  RunProgress  $progress  Where its loop stands.
     * @param  PausedBatch|null  $paused  Tool batch the run is waiting in, if any.
     * @param  int  $version  Save counter for the stale-copy check.
     * @return void
     */
    public function __construct(
        public readonly RunTask $task,
        public readonly RunStatus $status,
        public readonly RunProgress $progress,
        public readonly ?PausedBatch $paused = null,
        public readonly int $version = 0,
    ) {}

    /**
     * A new running run whose history is $history plus the message.
     *
     * @param  string  $runId  Run id.
     * @param  string  $message  Message the loop runs on.
     * @param  string  $routingMessage  Original user message.
     * @param  Message[]  $history  Prior conversation history.
     * @param  string|null  $conversationId  Conversation the finished turn is appended to; null for a send() run.
     * @return self
     */
    public static function start(string $runId, string $message, string $routingMessage, array $history = [], ?string $conversationId = null): self
    {
        $task = new RunTask($runId, $message, $routingMessage, conversationId: $conversationId);

        return new self($task, RunStatus::Running, new RunProgress([...$history, Message::user($message)]));
    }

    /**
     * The run id, also the storage key.
     *
     * @return string
     */
    public function runId(): string
    {
        return $this->task->runId;
    }

    /**
     * Refuse a run that cannot continue: a terminal or running one, or one still waiting for a decision.
     *
     * @return void
     *
     * @throws RunStateException When the run is not resumable or its paused call has no decision.
     */
    public function assertResumable(): void
    {
        if (! $this->status->isResumable()) {
            throw new RunStateException("Run {$this->runId()} cannot be resumed from status {$this->status->value}.");
        }

        if ($this->isAwaitingDecision()) {
            throw new RunStateException("Run {$this->runId()} is waiting for a decision on its paused call.");
        }
    }

    /**
     * Whether the run is paused for approval and nobody has approved or denied the call yet.
     *
     * @return bool
     */
    public function isAwaitingDecision(): bool
    {
        return $this->status === RunStatus::AwaitingApproval && $this->paused?->decision === null;
    }

    /**
     * Copy with another status, and another paused batch when one is given.
     *
     * @param  RunStatus  $status  New status.
     * @param  PausedBatch|null  $paused  Paused batch to carry instead of the current one.
     * @return self
     */
    public function withStatus(RunStatus $status, ?PausedBatch $paused = null): self
    {
        return new self($this->task, $status, $this->progress, $paused ?? $this->paused, $this->version);
    }

    /**
     * Copy carrying another save counter.
     *
     * @param  int  $version  Version to carry.
     * @return self
     */
    public function withVersion(int $version): self
    {
        return new self($this->task, $this->status, $this->progress, $this->paused, $version);
    }

    /**
     * Serialise to plain data for the memory driver.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'task' => $this->task->toArray(),
            'status' => $this->status->value,
            'progress' => $this->progress->toArray(),
            'paused' => $this->paused?->toArray(),
            'version' => $this->version,
        ];
    }

    /**
     * Rebuild from toArray() output.
     *
     * @param  mixed  $data  Stored row.
     * @return self
     *
     * @throws RunStateException When the row is missing, malformed or holds an unknown status.
     */
    public static function fromArray(mixed $data): self
    {
        $runId = is_array($data) ? (string) ($data['task']['run_id'] ?? '') : '';
        if ($runId === '') {
            throw new RunStateException('The stored run is invalid: no run id.');
        }

        $status = RunStatus::tryFrom((string) ($data['status'] ?? ''));
        if ($status === null) {
            throw new RunStateException("The stored run {$runId} is invalid: unknown status.");
        }

        try {
            return new self(
                task: RunTask::fromArray((array) $data['task']),
                status: $status,
                progress: RunProgress::fromArray((array) ($data['progress'] ?? [])),
                paused: is_array($data['paused'] ?? null) ? PausedBatch::fromArray($data['paused']) : null,
                version: (int) ($data['version'] ?? 0),
            );
        } catch (\Exception $exception) {
            throw new RunStateException("The stored run {$runId} is invalid: ".$exception::class, 0, $exception);
        }
    }
}

<?php

declare(strict_types=1);

namespace PhpClaw\Agent;

use PhpClaw\Exceptions\RunConflictException;
use PhpClaw\Exceptions\RunStateException;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Support\Log;

/**
 * Saves durable runs in the memory driver's `runs` namespace, with a stale-copy check and a seven-day expiry.
 */
final class RunStore
{
    public const NAMESPACE = 'runs';

    public const TTL_SECONDS = 604800;

    public const STALE_RUNNING_SECONDS = 900;

    private const SAVED_AT = 'saved_at';

    /**
     * Build the store.
     *
     * @param  MemoryInterface  $memory  Memory driver runs are saved through.
     * @return void
     */
    public function __construct(
        private readonly MemoryInterface $memory,
    ) {}

    /**
     * Load a saved run.
     *
     * @param  string  $runId  Run id.
     * @return RunState
     *
     * @throws RunStateException When the run is missing or malformed.
     */
    public function load(string $runId): RunState
    {
        $row = $this->memory->get($runId, self::NAMESPACE);

        if ($row === null) {
            throw new RunStateException("No saved run {$runId}.");
        }

        return RunState::fromArray($row);
    }

    /**
     * Save a run unless another process saved a newer version since it was read.
     *
     * @param  RunState  $state  State to save, carrying the version it was read at.
     * @return void
     *
     * @throws RunConflictException When the stored version differs from $state->version.
     */
    public function save(RunState $state): void
    {
        $stored = $this->memory->get($state->runId(), self::NAMESPACE);
        $storedVersion = is_array($stored) ? (int) ($stored['version'] ?? 0) : $state->version;

        if ($storedVersion !== $state->version) {
            throw new RunConflictException("Run {$state->runId()} was saved by another process: stored version {$storedVersion}, expected {$state->version}.");
        }

        $row = [...$state->withVersion($state->version + 1)->toArray(), self::SAVED_AT => time()];
        $this->memory->set($state->runId(), $row, self::NAMESPACE, self::TTL_SECONDS);
    }

    /**
     * Whether the stored run has been cancelled.
     *
     * @param  string  $runId  Run id.
     * @return bool
     */
    public function isCancelled(string $runId): bool
    {
        $stored = $this->memory->get($runId, self::NAMESPACE);

        return is_array($stored) && ($stored['status'] ?? '') === RunStatus::Cancelled->value;
    }

    /**
     * Find saved runs waiting for a human decision, skipping malformed rows.
     *
     * @param  int  $limit  Most runs to return.
     * @return list<RunState>
     */
    public function findPending(int $limit): array
    {
        $pending = array_filter(array_column($this->savedRuns(), 1), static fn (RunState $state): bool => $state->isAwaitingDecision());

        return array_slice(array_values($pending), 0, max(0, $limit));
    }

    /**
     * Saved runs ready to continue, oldest save first; malformed rows are skipped.
     *
     * @param  int  $limit  Most runs to return.
     * @return list<RunState>
     */
    public function findDue(int $limit): array
    {
        $due = array_values(array_filter($this->savedRuns(), static fn (array $run): bool => self::isDue($run[1], $run[0])));

        usort($due, static fn (array $first, array $second): int => $first[0] <=> $second[0]);

        return array_column(array_slice($due, 0, max(0, $limit)), 1);
    }

    /**
     * Mark a saved run Completed or Failed, leaving an unsaved or cancelled run alone.
     *
     * @param  string  $runId  Run id.
     * @param  RunStatus  $status  Completed or Failed.
     * @return void
     */
    public function finish(string $runId, RunStatus $status): void
    {
        try {
            $stored = $this->memory->get($runId, self::NAMESPACE);
            if ($stored === null) {
                return;
            }

            $state = RunState::fromArray($stored);
            if ($state->status !== RunStatus::Cancelled) {
                $this->save($state->withStatus($status));
            }
        } catch (RunStateException|RunConflictException $e) {
            Log::warning("[phpClaw] Run {$runId} could not be marked {$status->value}: ".$e::class);
        }
    }

    /**
     * Whether a saved run is ready to continue: suspended, paused with a decision, or running but silent too long.
     *
     * @param  RunState  $state  Saved run.
     * @param  int  $savedAt  Unix time of its last save; 0 when unknown.
     * @return bool
     */
    private static function isDue(RunState $state, int $savedAt): bool
    {
        return match ($state->status) {
            RunStatus::Suspended => true,
            RunStatus::AwaitingApproval => ! $state->isAwaitingDecision(),
            RunStatus::Running => $savedAt <= time() - self::STALE_RUNNING_SECONDS,
            default => false,
        };
    }

    /**
     * Every readable saved run with the time of its last save (0 when unknown); malformed rows are skipped.
     *
     * @return list<array{0: int, 1: RunState}>
     */
    private function savedRuns(): array
    {
        $runs = [];

        foreach ($this->memory->all(self::NAMESPACE) as $row) {
            try {
                $state = RunState::fromArray($row);
            } catch (RunStateException) {
                continue;
            }

            $runs[] = [(int) ($row[self::SAVED_AT] ?? 0), $state];
        }

        return $runs;
    }
}

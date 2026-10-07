<?php

declare(strict_types=1);

namespace PhpClaw\Laravel;

use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\PausedBatch;
use PhpClaw\Agent\RunState;
use PhpClaw\Agent\RunStore;
use PhpClaw\Claw;
use PhpClaw\Exceptions\RunConflictException;
use PhpClaw\Exceptions\RunStateException;
use PhpClaw\Exceptions\RunSuspendedException;
use PhpClaw\Laravel\Memory\DatabaseConversationMemory;

/**
 * Load, describe and decide saved durable runs for their owner or a manage-all user.
 */
final class RunApprovals
{
    /**
     * Load a saved run through the engine's memory.
     *
     * @param  Claw  $claw  Engine whose memory holds the run.
     * @param  string  $runId  Run id.
     * @return RunState
     *
     * @throws RunStateException When the run is missing or malformed, or no memory driver is configured.
     */
    public static function load(Claw $claw, string $runId): RunState
    {
        $memory = $claw->memory();

        if ($memory === null) {
            throw new RunStateException('Durable runs need a memory driver: none is configured.');
        }

        return (new RunStore($memory))->load($runId);
    }

    /**
     * The run as JSON-ready data: id, status, conversation and the paused call when one waits for a decision.
     *
     * @param  RunState  $state  Saved run.
     * @return array{run_id: string, status: string, conversation_id: string|null, pending: array{call_id: string, tool_name: string, tool_input: array<string, mixed>}|null}
     */
    public static function describe(RunState $state): array
    {
        $paused = $state->isAwaitingDecision() ? $state->paused : null;

        return [
            'run_id' => $state->runId(),
            'status' => $state->status->value,
            'conversation_id' => $state->task->conversationId,
            'pending' => $paused instanceof PausedBatch
                ? ['call_id' => $paused->callId(), 'tool_name' => $paused->toolName(), 'tool_input' => $paused->input()]
                : null,
        ];
    }

    /**
     * The user who started the run's conversation; null for a run with no stored conversation.
     *
     * @param  RunState  $state  Saved run.
     * @return string|null
     */
    public static function ownerOf(RunState $state): ?string
    {
        $conversationId = $state->task->conversationId;

        return $conversationId === null ? null : DatabaseConversationMemory::ownerOf($conversationId);
    }

    /**
     * Whether the current user may approve, deny or resume the run: its owner, or a manage-all user.
     *
     * @param  RunState  $state  Saved run.
     * @return bool
     */
    public static function canDecide(RunState $state): bool
    {
        if (LaravelIdentityResolver::manageAll()) {
            return true;
        }

        $owner = self::ownerOf($state);

        return $owner !== null && $owner === LaravelIdentityResolver::actingUserId();
    }

    /**
     * Run $work as the run's owner, so its finished turn is saved to the owner's conversation.
     *
     * @param  RunState  $state  Saved run.
     * @param  callable(): mixed  $work  Work to run.
     * @return mixed What $work returns.
     */
    public static function asOwner(RunState $state, callable $work): mixed
    {
        return LaravelIdentityResolver::actingAs(self::ownerOf($state) ?? '', $work);
    }

    /**
     * Approve or deny the run's paused call, then resume the run in this process.
     *
     * @param  Claw  $claw  Engine the run is resumed on.
     * @param  RunState  $state  Saved run.
     * @param  string  $callId  Id of the paused call.
     * @param  string|null  $denyReason  Null approves; a string denies with that reason.
     * @return AgentResponse The run's final answer.
     *
     * @throws RunStateException When the run has no undecided paused call with that id.
     * @throws RunSuspendedException When the resumed run pauses again or spends its budget.
     * @throws RunConflictException When another process saved the run meanwhile.
     */
    public static function decide(Claw $claw, RunState $state, string $callId, ?string $denyReason): AgentResponse
    {
        if ($denyReason === null) {
            $claw->approve($state->runId(), $callId);
        } else {
            $claw->deny($state->runId(), $callId, $denyReason);
        }

        return $claw->resume($state->runId());
    }
}

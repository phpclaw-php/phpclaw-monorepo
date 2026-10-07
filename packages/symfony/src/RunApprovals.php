<?php

declare(strict_types=1);

namespace PhpClaw\Symfony;

use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\PausedBatch;
use PhpClaw\Agent\RunState;
use PhpClaw\Agent\RunStore;
use PhpClaw\Claw;
use PhpClaw\Exceptions\RunConflictException;
use PhpClaw\Exceptions\RunStateException;
use PhpClaw\Exceptions\RunSuspendedException;
use PhpClaw\Symfony\Memory\DoctrineConversationMemory;

/**
 * Load, describe and decide saved durable runs for their owner or a manage-all user.
 */
final class RunApprovals
{
    /**
     * Bind the identity that decides and the conversation store that names each run's owner.
     *
     * @param  SymfonyIdentityResolver  $identity  Acting user and manage-all role.
     * @param  DoctrineConversationMemory|null  $conversations  Owner lookup; null without Doctrine DBAL.
     */
    public function __construct(
        private readonly SymfonyIdentityResolver $identity,
        private readonly ?DoctrineConversationMemory $conversations = null,
    ) {}

    /**
     * Load a saved run through the engine's memory driver.
     *
     * @param  Claw  $claw  Durable engine.
     * @param  string  $runId  Saved run id.
     * @return RunState
     *
     * @throws RunStateException When there is no memory driver or no saved run with that id.
     */
    public function load(Claw $claw, string $runId): RunState
    {
        $memory = $claw->memory();

        if ($memory === null) {
            throw new RunStateException('Durable runs need a memory driver: none is configured.');
        }

        return (new RunStore($memory))->load($runId);
    }

    /**
     * The run as the API returns it: id, status, conversation and the paused call, if any.
     *
     * @param  RunState  $state  Saved run.
     * @return array{run_id: string, status: string, conversation_id: string|null, pending: array{call_id: string, tool_name: string, tool_input: array<string, mixed>}|null}
     */
    public function describe(RunState $state): array
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
     * The user who owns the run's conversation, or null for a run with no stored conversation.
     *
     * @param  RunState  $state  Saved run.
     * @return string|null
     */
    public function ownerOf(RunState $state): ?string
    {
        $conversationId = $state->task->conversationId;

        return $conversationId === null ? null : $this->conversations?->ownerOf($conversationId);
    }

    /**
     * Whether the acting user may approve, deny or resume the run: its owner, or a manage-all user.
     *
     * @param  RunState  $state  Saved run.
     * @return bool
     */
    public function canDecide(RunState $state): bool
    {
        if ($this->identity->manageAll()) {
            return true;
        }

        $owner = $this->ownerOf($state);

        return $owner !== null && $owner === $this->identity->actingUserId();
    }

    /**
     * Run the work as the run's owner, then restore whoever was acting before.
     *
     * @template T
     *
     * @param  RunState  $state  Saved run.
     * @param  callable(): T  $work  Work to run as the owner.
     * @return T
     */
    public function asOwner(RunState $state, callable $work): mixed
    {
        $previous = $this->identity->isImpersonating() ? $this->identity->actingUserId() : null;
        $this->identity->actAs($this->ownerOf($state) ?? '');

        try {
            return $work();
        } finally {
            if ($previous === null) {
                $this->identity->stopActing();
            } else {
                $this->identity->actAs($previous);
            }
        }
    }

    /**
     * Record an approval, or a denial when a reason is given, then resume the run in this process.
     *
     * @param  Claw  $claw  Durable engine.
     * @param  RunState  $state  Saved run waiting for a decision.
     * @param  string  $callId  Paused call id.
     * @param  string|null  $denyReason  Null approves; a string denies with that reason.
     * @return AgentResponse
     *
     * @throws RunStateException When the call is not the one waiting for a decision.
     * @throws RunConflictException When another process saved the run meanwhile.
     * @throws RunSuspendedException When the resumed run pauses or suspends again.
     */
    public function decide(Claw $claw, RunState $state, string $callId, ?string $denyReason): AgentResponse
    {
        if ($denyReason === null) {
            $claw->approve($state->runId(), $callId);
        } else {
            $claw->deny($state->runId(), $callId, $denyReason);
        }

        return $claw->resume($state->runId());
    }
}

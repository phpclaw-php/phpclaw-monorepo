<?php

declare(strict_types=1);

namespace PhpClaw\Agent;

use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\RunSuspendedException;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Hooks\HookDispatcher;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Support\Ulid;

/**
 * Wraps every public-API invocation in the standard lifecycle: run id → withRun → augment → guard scan → agent.before → invoke → agent.after → return.
 */
final class InvocationPipeline
{
    /**
     * Build an InvocationPipeline.
     *
     * @param  MessageAugmenter  $augmenter  Augments messages with memory and skill context.
     * @param  MemoryInterface|null  $memory  Memory driver used to persist conversations, or null.
     */
    public function __construct(
        private readonly MessageAugmenter $augmenter,
        private readonly ?MemoryInterface $memory,
    ) {}

    /**
     * Run a non-conversational invocation (`send` / `stream`).
     *
     * @param  string  $message  User message.
     * @param  bool  $streaming  Whether the request is streaming.
     * @param  callable  $invoke  Callable that runs the agent and returns AgentResponse.
     * @return AgentResponse The result.
     */
    public function execute(string $message, bool $streaming, callable $invoke): AgentResponse
    {
        $runId = Ulid::generate();

        return HookDispatcher::withRun($runId, function () use ($message, $streaming, $invoke, $runId): AgentResponse {
            $augmented = $this->augmenter->augment($message);
            $this->scanOrThrow($augmented, $message);

            return $this->invokeWithHooks(static fn (): AgentResponse => $invoke($augmented, $runId, $message), $message, $streaming, $runId);
        }, parentRunId: HookDispatcher::currentRunId());
    }

    /**
     * Continue a saved run under its own run id, firing the agent hooks without re-augmenting the message.
     *
     * @param  string  $runId  Persisted run id.
     * @param  string  $message  Original user message, for the hook payloads and the appended turn.
     * @param  callable  $invoke  Callable that resumes the agent and returns AgentResponse.
     * @param  Conversation|null  $conversation  Stored conversation the run belongs to; null for a send() run.
     * @return AgentResponse The result.
     */
    public function resume(string $runId, string $message, callable $invoke, ?Conversation $conversation = null): AgentResponse
    {
        return HookDispatcher::withRun($runId, function () use ($runId, $message, $invoke, $conversation): AgentResponse {
            $response = $this->invokeWithHooks($invoke, $message, streaming: false, runId: $runId, conversationId: $conversation->id ?? '');

            if ($conversation !== null) {
                $this->fireConversationEnd($this->appendTurn($conversation, $message, $response, beforePersist: null), $message, $response);
            }

            return $response;
        }, parentRunId: HookDispatcher::currentRunId());
    }

    /**
     * Fire agent.before, run the invocation, report a failure through agent.error, and fire agent.after.
     *
     * @param  callable(): AgentResponse  $invoke  Runs the agent.
     * @param  string  $message  Original user message, for the hook payloads.
     * @param  bool  $streaming  Whether the request is streaming.
     * @param  string  $runId  Active run id.
     * @param  string  $conversationId  Conversation the run belongs to; empty for a send() run.
     * @return AgentResponse The result.
     */
    private function invokeWithHooks(callable $invoke, string $message, bool $streaming, string $runId, string $conversationId = ''): AgentResponse
    {
        HookDispatcher::agentBefore($message, conversationId: $conversationId, streaming: $streaming, runId: $runId);

        try {
            $response = $invoke();
        } catch (\Throwable $e) {
            $this->reportErrorAndRethrow($e, $message, $streaming, $runId, $conversationId);
        }

        $this->fireAgentAfter($message, $response, $streaming, conversationId: $conversationId, runId: $runId);

        return $response;
    }

    /**
     * Run a conversational invocation (`sendInConversation` / `streamInConversation`).
     *
     * @param  Conversation  $conversation  Conversation context whose history is fed to the agent.
     * @param  string  $message  User message.
     * @param  bool  $streaming  Whether the request is streaming.
     * @param  (callable(array<string, mixed>): (array<string, mixed>|mixed))|null  $beforePersist  Optional mutator applied to the serialized conversation before persistence.
     * @param  callable  $invoke  Callable that runs the agent and returns AgentResponse.
     * @return ConversationTurn The result.
     */
    public function executeInConversation(
        Conversation $conversation,
        string $message,
        bool $streaming,
        ?callable $beforePersist,
        callable $invoke,
    ): ConversationTurn {
        $runId = Ulid::generate();

        return HookDispatcher::withRun($runId, function () use ($conversation, $message, $streaming, $beforePersist, $invoke, $runId): ConversationTurn {
            if ($conversation->history === []) {
                HookDispatcher::conversationStart($conversation->id, $conversation->metadata);
            }

            $augmented = $this->augmenter->augment($message);
            $this->scanOrThrow($augmented, $message);

            HookDispatcher::agentBefore($message, conversationId: $conversation->id, streaming: $streaming, runId: $runId);

            try {
                $response = $invoke($augmented, $conversation->history, $runId, $message);
            } catch (\Throwable $e) {
                $this->reportErrorAndRethrow($e, $message, $streaming, $runId, $conversation->id);
            }

            $updated = $this->appendTurn($conversation, $message, $response, $beforePersist);

            $this->fireAgentAfter($message, $response, $streaming, conversationId: $updated->id, runId: $runId);
            $this->fireConversationEnd($updated, $message, $response);

            return new ConversationTurn(
                response: $response,
                conversation: $updated,
            );
        }, parentRunId: HookDispatcher::currentRunId());
    }

    /**
     * Fire the agent.error hook and rethrow the caught exception; a durable run that paused is rethrown without it.
     *
     * @param  \Throwable  $e  Exception caught during the agent invocation.
     * @param  string  $message  Original user message reported in the hook payload.
     * @param  bool  $streaming  Whether the invocation was in streaming mode.
     * @param  string  $runId  Active run identifier propagated to the hook.
     * @param  string  $conversationId  Conversation identifier when this is a conversational call; empty otherwise.
     * @return never Always rethrows $e after firing the hook.
     *
     * @throws \Throwable Always.
     */
    private function reportErrorAndRethrow(\Throwable $e, string $message, bool $streaming, string $runId, string $conversationId = ''): never
    {
        if ($e instanceof RunSuspendedException) {
            throw $e;
        }

        HookDispatcher::agentError($message, $e->getMessage(), get_class($e), conversationId: $conversationId, streaming: $streaming, runId: $runId);
        throw $e;
    }

    /**
     * Run guards over the message and convert a GuardException into a `guard.blocked` hook before re-throwing.
     *
     * @param  string  $scanText  Text scanned by content guards, the augmented message (memory + skill context + user message).
     * @param  string  $reportMessage  Original user message: reported in the guard.blocked hook and scanned by raw-input guards (e.g. length).
     * @return void
     *
     * @throws GuardException When the text matches any registered guard.
     */
    private function scanOrThrow(string $scanText, string $reportMessage): void
    {
        try {
            GuardRegistry::scan($scanText, $reportMessage);
        } catch (GuardException $e) {
            HookDispatcher::guardBlocked($reportMessage, $e->getMessage(), self::guardShortName($e));
            throw $e;
        }
    }

    /**
     * Derive the short class name of the guard that threw, for use in the guard.blocked hook payload.
     *
     * @param  GuardException  $e  Caught exception.
     * @return string|null Short class name (e.g. "InjectionGuard"), or null when the guard is not attributed.
     */
    private static function guardShortName(GuardException $e): ?string
    {
        $fqcn = $e->guardClass();

        return $fqcn !== null ? basename(str_replace('\\', '/', $fqcn)) : null;
    }

    /**
     * Append the user message and the answer to the conversation and save it.
     *
     * @param  Conversation  $conversation  Conversation the turn belongs to.
     * @param  string  $message  User message.
     * @param  AgentResponse  $response  Final response.
     * @param  (callable(array<string, mixed>): (array<string, mixed>|mixed))|null  $beforePersist  Optional mutator applied to the payload before writing to memory.
     * @return Conversation The conversation with the turn appended.
     */
    private function appendTurn(Conversation $conversation, string $message, AgentResponse $response, ?callable $beforePersist): Conversation
    {
        $updated = $conversation
            ->withMessage(Message::user($message))
            ->withMessage(Message::assistant($response->text));

        $this->persistConversation($updated, $beforePersist);

        return $updated;
    }

    /**
     * Fire conversation.end for a finished turn.
     *
     * @param  Conversation  $updated  Conversation with the turn appended.
     * @param  string  $message  User message.
     * @param  AgentResponse  $response  Final response.
     * @return void
     */
    private function fireConversationEnd(Conversation $updated, string $message, AgentResponse $response): void
    {
        HookDispatcher::conversationEnd(
            conversationId: $updated->id,
            turnCount: count($updated->history),
            message: $message,
            response: $response->text,
            durationMs: $response->durationMs,
        );
    }

    /**
     * Apply the optional beforePersist mutator and write the conversation to memory.
     *
     * @param  Conversation  $updated  Conversation with the latest turn appended.
     * @param  (callable(array<string, mixed>): (array<string, mixed>|mixed))|null  $beforePersist  Optional mutator applied to the payload before writing to memory.
     * @return void
     */
    private function persistConversation(Conversation $updated, ?callable $beforePersist): void
    {
        $payload = $updated->toArray();

        if ($beforePersist !== null) {
            $mutated = $beforePersist($payload);
            if (is_array($mutated)) {
                $payload = $mutated;
            }
        }

        if ($this->memory !== null) {
            $this->memory->set($updated->id, $payload, Conversation::MEMORY_NAMESPACE);
        }
    }

    /**
     * Fire the agent.after hook with the shared payload shape used by every public invocation path.
     *
     * @param  string  $message  User message.
     * @param  AgentResponse  $response  Final response text.
     * @param  bool  $streaming  Whether the request is streaming.
     * @param  string  $conversationId  Conversation identifier, if any.
     * @param  string  $runId  Active run ID, if any.
     * @return void
     */
    private function fireAgentAfter(
        string $message,
        AgentResponse $response,
        bool $streaming,
        string $conversationId,
        string $runId,
    ): void {
        HookDispatcher::agentAfter(
            message: $message,
            text: $response->text,
            provider: $response->provider,
            model: $response->model,
            iterations: $response->iterations,
            durationMs: $response->durationMs,
            toolsCalled: $response->toolsCalled,
            conversationId: $conversationId,
            streaming: $streaming,
            cacheReadTokens: $response->cacheReadTokens,
            cacheWriteTokens: $response->cacheWriteTokens,
            runId: $runId,
        );
    }
}

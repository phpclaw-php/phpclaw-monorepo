<?php

declare(strict_types=1);

namespace PhpClaw\Hooks\Dispatchers;

use PhpClaw\Hooks\EventPayload;
use PhpClaw\Hooks\LifecycleEvent;

/**
 * Typed dispatchers for agent-lifecycle and context events.
 *
 * @internal
 */
final class AgentEventDispatcher
{
    /**
     * Fires at the very start of an agent run, before any provider call.
     *
     * @param  string  $message  User message.
     * @param  bool  $streaming  Whether the request is streaming.
     * @param  string  $conversationId  Conversation identifier, if any.
     * @param  string  $runId  Active run ID, if any.
     * @return void
     */
    public static function before(
        string $message,
        bool $streaming = false,
        string $conversationId = '',
        string $runId = '',
    ): void {
        EventPayload::fire(
            LifecycleEvent::AgentBefore->value,
            ['message' => $message],
            runId: $runId,
            streaming: $streaming,
            conversationId: $conversationId,
        );
    }

    /**
     * Fires once per ReAct loop iteration before the provider call.
     *
     * @param  int  $iteration  ReAct loop iteration number.
     * @param  string  $message  User message.
     * @param  string  $provider  Provider name.
     * @param  string  $model  Model identifier.
     * @param  bool  $streaming  Whether the request is streaming.
     * @param  string  $runId  Active run ID, if any.
     * @param  array<int, mixed>  $history  History.
     * @param  string  $parentRunId  Parent run ID, if any.
     * @return void
     */
    public static function iteration(
        int $iteration,
        string $message,
        string $provider,
        string $model,
        bool $streaming = false,
        string $runId = '',
        array $history = [],
        string $parentRunId = '',
    ): void {
        EventPayload::fire(
            LifecycleEvent::AgentIteration->value,
            [
                'iteration' => $iteration,
                'message' => $message,
                'provider' => $provider,
                'model' => $model,
                'history' => $history,
            ],
            runId: $runId,
            parentRunId: $parentRunId,
            streaming: $streaming,
        );
    }

    /**
     * Fires after a successful agent run, before returning AgentResponse.
     *
     * @param  string  $message  User message.
     * @param  string  $text  Assistant response text.
     * @param  string  $provider  Provider name.
     * @param  string  $model  Model identifier.
     * @param  int  $iterations  Total iterations run.
     * @param  int  $durationMs  Duration in milliseconds.
     * @param  array<int, mixed>  $toolsCalled  Tools called.
     * @param  bool  $streaming  Whether the request is streaming.
     * @param  string  $conversationId  Conversation identifier, if any.
     * @param  int|null  $cacheReadTokens  Cache-read token count, if reported.
     * @param  int|null  $cacheWriteTokens  Cache-write token count, if reported.
     * @param  string  $runId  Active run ID, if any.
     * @return void
     */
    public static function after(
        string $message,
        string $text,
        string $provider,
        string $model,
        int $iterations,
        int $durationMs,
        array $toolsCalled,
        bool $streaming = false,
        string $conversationId = '',
        ?int $cacheReadTokens = null,
        ?int $cacheWriteTokens = null,
        string $runId = '',
    ): void {
        EventPayload::fire(
            LifecycleEvent::AgentAfter->value,
            [
                'message' => $message,
                'text' => $text,
                'provider' => $provider,
                'model' => $model,
                'iterations' => $iterations,
                'duration_ms' => $durationMs,
                'tools_called' => $toolsCalled,
                'cache_read_tokens' => $cacheReadTokens,
                'cache_write_tokens' => $cacheWriteTokens,
            ],
            runId: $runId,
            streaming: $streaming,
            conversationId: $conversationId,
        );
    }

    /**
     * Fires when an uncaught exception terminates an agent run.
     *
     * @param  string  $message  User message.
     * @param  string  $error  Error message.
     * @param  string  $class  Exception class name.
     * @param  bool  $streaming  Whether the request is streaming.
     * @param  string  $conversationId  Conversation identifier, if any.
     * @param  string  $runId  Active run ID, if any.
     * @return void
     */
    public static function error(
        string $message,
        string $error,
        string $class,
        bool $streaming = false,
        string $conversationId = '',
        string $runId = '',
    ): void {
        EventPayload::fire(
            LifecycleEvent::AgentError->value,
            [
                'message' => $message,
                'error' => $error,
                'class' => $class,
            ],
            runId: $runId,
            streaming: $streaming,
            conversationId: $conversationId,
        );
    }

    /**
     * Fires when the agent loop hits the configured iteration cap.
     *
     * @param  string  $message  User message.
     * @param  int  $maxIterations  Configured maximum iterations.
     * @param  string  $provider  Provider name.
     * @param  string  $model  Model identifier.
     * @param  string  $runId  Active run ID, if any.
     * @return void
     */
    public static function maxIterations(
        string $message,
        int $maxIterations,
        string $provider,
        string $model,
        string $runId = '',
    ): void {
        EventPayload::fire(
            LifecycleEvent::AgentMaxIterations->value,
            [
                'message' => $message,
                'max_iterations' => $maxIterations,
                'provider' => $provider,
                'model' => $model,
            ],
            runId: $runId,
        );
    }

    /**
     * Fires when conversation history exceeds the configured maxHistoryLength.
     *
     * @param  string  $message  User message.
     * @param  int  $historyLength  Current history length.
     * @param  int  $maxHistoryLength  Configured maximum history length.
     * @param  string  $provider  Provider name.
     * @param  string  $model  Model identifier.
     * @param  string  $runId  Active run ID, if any.
     * @param  string  $parentRunId  Parent run ID, if any.
     * @return void
     */
    public static function contextOverflow(
        string $message,
        int $historyLength,
        int $maxHistoryLength,
        string $provider,
        string $model,
        string $runId = '',
        string $parentRunId = '',
    ): void {
        EventPayload::fire(
            LifecycleEvent::ContextOverflow->value,
            [
                'message' => $message,
                'history_length' => $historyLength,
                'max_history_length' => $maxHistoryLength,
                'provider' => $provider,
                'model' => $model,
            ],
            runId: $runId,
            parentRunId: $parentRunId,
        );
    }

    /**
     * Fire the compaction.before event before the history-summary LLM call.
     *
     * @param  string  $provider  Provider name.
     * @param  string  $model  Model identifier.
     * @param  string  $runId  Active run ID, if any.
     * @param  string  $parentRunId  Parent run ID, if any.
     * @return void
     */
    public static function compactionBefore(
        string $provider,
        string $model,
        string $runId = '',
        string $parentRunId = '',
    ): void {
        EventPayload::fire(
            LifecycleEvent::CompactionBefore->value,
            [
                'provider' => $provider,
                'model' => $model,
            ],
            runId: $runId,
            parentRunId: $parentRunId,
        );
    }

    /**
     * Fire the compaction.after event once the history summary is produced.
     *
     * @param  string  $summary  The generated conversation summary.
     * @param  string  $runId  Active run ID, if any.
     * @param  string  $parentRunId  Parent run ID, if any.
     * @return void
     */
    public static function compactionAfter(
        string $summary,
        string $runId = '',
        string $parentRunId = '',
    ): void {
        EventPayload::fire(
            LifecycleEvent::CompactionAfter->value,
            [
                'summary' => $summary,
            ],
            runId: $runId,
            parentRunId: $parentRunId,
        );
    }
}

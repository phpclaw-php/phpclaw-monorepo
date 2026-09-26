<?php

declare(strict_types=1);

namespace PhpClaw\Hooks\Dispatchers;

use PhpClaw\Hooks\EventPayload;
use PhpClaw\Hooks\LifecycleEvent;

/**
 * Typed dispatchers for streaming-flow lifecycle events.
 */
final class StreamEventDispatcher
{
    /**
     * Fires at the very start of Agent::stream(), before any provider call.
     *
     * @param  string  $message  User message.
     * @param  string  $provider  Provider name.
     * @param  string  $model  Model identifier.
     * @param  string  $runId  Active run ID, if any.
     * @return void
     */
    public static function start(string $message, string $provider, string $model, string $runId = ''): void
    {
        EventPayload::fire(
            LifecycleEvent::StreamStart->value,
            [
                'message' => $message,
                'provider' => $provider,
                'model' => $model,
            ],
            runId: $runId,
        );
    }

    /**
     * Fires once streaming completes successfully, just before AgentResponse is returned.
     *
     * @param  string  $message  User message.
     * @param  string  $provider  Provider name.
     * @param  string  $model  Model identifier.
     * @param  int  $durationMs  Duration in milliseconds.
     * @param  int  $chars  Total character count of the streamed response.
     * @param  string  $runId  Active run ID, if any.
     * @return void
     */
    public static function end(
        string $message,
        string $provider,
        string $model,
        int $durationMs,
        int $chars,
        string $runId = '',
    ): void {
        EventPayload::fire(
            LifecycleEvent::StreamEnd->value,
            [
                'message' => $message,
                'provider' => $provider,
                'model' => $model,
                'duration_ms' => $durationMs,
                'chars' => $chars,
            ],
            runId: $runId,
        );
    }

    /**
     * Fires when provider->stream() throws during the fast path (true SSE).
     *
     * @param  string  $provider  Provider name.
     * @param  string  $model  Model identifier.
     * @param  string  $error  Error message.
     * @param  string  $runId  Active run ID, if any.
     * @return void
     */
    public static function abort(string $provider, string $model, string $error, string $runId = ''): void
    {
        EventPayload::fire(
            LifecycleEvent::StreamAbort->value,
            [
                'provider' => $provider,
                'model' => $model,
                'error' => $error,
            ],
            runId: $runId,
        );
    }
}

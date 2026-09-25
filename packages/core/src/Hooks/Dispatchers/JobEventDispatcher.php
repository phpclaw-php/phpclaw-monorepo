<?php

declare(strict_types=1);

namespace PhpClaw\Hooks\Dispatchers;

use PhpClaw\Hooks\EventPayload;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;

/**
 * Typed dispatchers for queue-job lifecycle events.
 */
final class JobEventDispatcher
{
    /**
     * Fires at the very start of a job's execute method, before agent->send().
     *
     * @param  string  $jobId  Job identifier.
     * @param  string  $message  User message.
     * @return void
     */
    public static function started(string $jobId, string $message): void
    {
        HookRegistry::fire(LifecycleEvent::JobStarted->value, [
            'job_id' => $jobId,
            'message' => $message,
        ]);
    }

    /**
     * Fires after agent->send() succeeds, before persisting the result to memory.
     *
     * @param  string  $jobId  Job identifier.
     * @param  string  $message  User message.
     * @param  string  $provider  Provider name.
     * @param  string  $model  Model identifier.
     * @param  int  $iterations  Total iterations run.
     * @param  int  $durationMs  Duration in milliseconds.
     * @param  int|null  $tokens  Token count, if reported.
     * @param  string  $runId  Active run ID, if any.
     * @return void
     */
    public static function completed(
        string $jobId,
        string $message,
        string $provider,
        string $model,
        int $iterations,
        int $durationMs,
        ?int $tokens = null,
        string $runId = '',
    ): void {
        EventPayload::fire(
            LifecycleEvent::JobCompleted->value,
            [
                'job_id' => $jobId,
                'message' => $message,
                'provider' => $provider,
                'model' => $model,
                'iterations' => $iterations,
                'duration_ms' => $durationMs,
                'tokens' => $tokens,
            ],
            runId: $runId,
        );
    }

    /**
     * Fires when agent->send() throws, before persisting the failure to memory.
     *
     * @param  string  $jobId  Job identifier.
     * @param  string  $message  User message.
     * @param  string  $error  Error message.
     * @param  string  $class  Exception class name.
     * @return void
     */
    public static function failed(
        string $jobId,
        string $message,
        string $error,
        string $class,
    ): void {
        HookRegistry::fire(LifecycleEvent::JobFailed->value, [
            'job_id' => $jobId,
            'message' => $message,
            'error' => $error,
            'class' => $class,
        ]);
    }
}

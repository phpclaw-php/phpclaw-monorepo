<?php

declare(strict_types=1);

namespace PhpClaw\Hooks;

/**
 * Active run-ID context for the current call stack, consumed by {@see HookDispatcher} for typed event dispatch.
 */
final class HookRunContext
{
    private static string $currentRunId = '';

    private static string $currentParentRunId = '';

    /**
     * Run $work with $runId + $parentRunId set as the active context; restore on exit (try/finally).
     *
     * @template T
     *
     * @param  string  $runId  Run ID active for the duration of $work.
     * @param  callable(): T  $work  The callable to execute under the new run context.
     * @param  string  $parentRunId  Parent run ID for nested runs (empty for root).
     * @return T
     */
    public static function withRun(string $runId, callable $work, string $parentRunId = ''): mixed
    {
        $previousRun = self::$currentRunId;
        $previousParent = self::$currentParentRunId;

        self::$currentRunId = $runId;
        self::$currentParentRunId = $parentRunId;

        try {
            return $work();
        } finally {
            self::$currentRunId = $previousRun;
            self::$currentParentRunId = $previousParent;
        }
    }

    /**
     * Get the run ID active for the current call stack.
     *
     * @return string Empty string when no run context is active.
     */
    public static function currentRunId(): string
    {
        return self::$currentRunId;
    }

    /**
     * Get the parent run ID active for the current call stack.
     *
     * @return string Empty string when no parent context is active.
     */
    public static function currentParentRunId(): string
    {
        return self::$currentParentRunId;
    }
}

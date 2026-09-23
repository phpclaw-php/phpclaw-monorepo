<?php

declare(strict_types=1);

namespace PhpClaw\Hooks\Dispatchers;

use PhpClaw\Hooks\EventPayload;
use PhpClaw\Hooks\LifecycleEvent;

/**
 * Typed dispatchers for tool-execution lifecycle events.
 *
 * @internal
 */
final class ToolEventDispatcher
{
    /**
     * Fires immediately before a tool is executed.
     *
     * @param  string  $toolName  Tool name.
     * @param  array<string, mixed>  $toolInput  Tool input.
     * @param  int  $iteration  ReAct loop iteration number.
     * @param  string  $runId  Active run ID, if any.
     * @param  string  $parentRunId  Parent run ID, if any.
     * @return void
     */
    public static function before(
        string $toolName,
        array $toolInput,
        int $iteration,
        string $runId = '',
        string $parentRunId = '',
    ): void {
        EventPayload::fire(
            LifecycleEvent::ToolBefore->value,
            [
                'tool_name' => $toolName,
                'tool_input' => $toolInput,
                'iteration' => $iteration,
            ],
            runId: $runId,
            parentRunId: $parentRunId,
        );
    }

    /**
     * Fires immediately after a tool execution completes successfully.
     *
     * @param  string  $toolName  Tool name.
     * @param  array<string, mixed>  $toolInput  Tool input.
     * @param  string  $toolResult  Result returned by the tool.
     * @param  int  $iteration  ReAct loop iteration number.
     * @param  string  $runId  Active run ID, if any.
     * @param  string  $parentRunId  Parent run ID, if any.
     * @return void
     */
    public static function after(
        string $toolName,
        array $toolInput,
        string $toolResult,
        int $iteration,
        string $runId = '',
        string $parentRunId = '',
    ): void {
        EventPayload::fire(
            LifecycleEvent::ToolAfter->value,
            [
                'tool_name' => $toolName,
                'tool_input' => $toolInput,
                'tool_result' => $toolResult,
                'iteration' => $iteration,
            ],
            runId: $runId,
            parentRunId: $parentRunId,
        );
    }

    /**
     * Fires when a tool execution throws a ToolException.
     *
     * @param  string  $toolName  Tool name.
     * @param  array<string, mixed>  $toolInput  Tool input.
     * @param  string  $error  Error message.
     * @param  string  $runId  Active run ID, if any.
     * @param  string  $parentRunId  Parent run ID, if any.
     * @return void
     */
    public static function error(
        string $toolName,
        array $toolInput,
        string $error,
        string $runId = '',
        string $parentRunId = '',
    ): void {
        EventPayload::fire(
            LifecycleEvent::ToolError->value,
            [
                'tool_name' => $toolName,
                'tool_input' => $toolInput,
                'error' => $error,
            ],
            runId: $runId,
            parentRunId: $parentRunId,
        );
    }

    /**
     * Fires when a tool call with an unrecognised name is attempted.
     *
     * @param  string  $toolName  Tool name.
     * @param  array<string, mixed>  $toolInput  Tool input.
     * @param  array<int, string>  $availableTools  Available tools.
     * @param  string  $runId  Active run ID, if any.
     * @param  string  $parentRunId  Parent run ID, if any.
     * @return void
     */
    public static function notFound(
        string $toolName,
        array $toolInput,
        array $availableTools,
        string $runId = '',
        string $parentRunId = '',
    ): void {
        EventPayload::fire(
            LifecycleEvent::ToolNotFound->value,
            [
                'tool_name' => $toolName,
                'tool_input' => $toolInput,
                'available_tools' => $availableTools,
            ],
            runId: $runId,
            parentRunId: $parentRunId,
        );
    }
}

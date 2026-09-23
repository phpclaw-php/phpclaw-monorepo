<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Telescope;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;

/**
 * Records phpClaw activity (agent runs, tool calls, guard blocks) into the Laravel Telescope dashboard for local debugging.
 */
final class PhpClawWatcher
{
    public const TYPE_AGENT_RUN = 'phpclaw_agent_run';

    public const TYPE_TOOL_CALL = 'phpclaw_tool_call';

    public const TYPE_GUARD_BLOCKED = 'phpclaw_guard_blocked';

    /**
     * Subscribe to the phpClaw lifecycle events emitted by HookEventBridge.
     *
     * @param  Dispatcher  $events  Laravel's event dispatcher.
     * @return void
     */
    public static function register(Dispatcher $events): void
    {
        $events->listen('phpclaw.agent.after', [self::class, 'recordAgentRun']);
        $events->listen('phpclaw.tool.after', [self::class, 'recordToolCall']);
        $events->listen('phpclaw.guard.blocked', [self::class, 'recordGuardBlocked']);
    }

    /**
     * Record a completed agent run as a Telescope entry.
     *
     * @param  array<string, mixed>  $ctx  HookRegistry agent.after context.
     * @return void
     */
    public static function recordAgentRun(array $ctx): void
    {
        self::recordEntry(
            type: self::TYPE_AGENT_RUN,
            payload: self::agentRunPayload($ctx),
        );
    }

    /**
     * Record a tool invocation as a Telescope entry.
     *
     * @param  array<string, mixed>  $ctx  HookRegistry tool.after context.
     * @return void
     */
    public static function recordToolCall(array $ctx): void
    {
        self::recordEntry(
            type: self::TYPE_TOOL_CALL,
            payload: self::toolCallPayload($ctx),
        );
    }

    /**
     * Record a guard-blocked prompt as a Telescope entry.
     *
     * @param  array<string, mixed>  $ctx  HookRegistry guard.blocked context.
     * @return void
     */
    public static function recordGuardBlocked(array $ctx): void
    {
        self::recordEntry(
            type: self::TYPE_GUARD_BLOCKED,
            payload: self::guardBlockedPayload($ctx),
        );
    }

    /**
     * Build the metadata-only payload for a completed agent run (no prompt/completion text).
     *
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    public static function agentRunPayload(array $ctx): array
    {
        $inputTokens = (int) ($ctx['input_tokens'] ?? 0);
        $outputTokens = (int) ($ctx['output_tokens'] ?? 0);

        return [
            'provider' => $ctx['provider'] ?? null,
            'model' => $ctx['model'] ?? null,
            'iterations' => $ctx['iterations'] ?? null,
            'duration_ms' => $ctx['duration_ms'] ?? null,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $inputTokens + $outputTokens,
            'tools_called' => $ctx['tools_called'] ?? [],
        ];
    }

    /**
     * Build the metadata payload for a single tool invocation.
     *
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    public static function toolCallPayload(array $ctx): array
    {
        return [
            'tool_name' => $ctx['tool_name'] ?? null,
            'iteration' => $ctx['iteration'] ?? null,
            'tool_input' => $ctx['tool_input'] ?? null,
        ];
    }

    /**
     * Build the payload for a guard-blocked prompt (raw message omitted by design).
     *
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    public static function guardBlockedPayload(array $ctx): array
    {
        return [
            'reason' => $ctx['reason'] ?? null,
        ];
    }

    /**
     * Record a typed Telescope entry when Telescope is installed.
     *
     * @param  string  $type  The Telescope entry type.
     * @param  array<string, mixed>  $payload  The entry payload.
     * @return void
     */
    private static function recordEntry(string $type, array $payload): void
    {
        if (! class_exists(Telescope::class)) {
            return;
        }

        Telescope::recordEvent(
            IncomingEntry::make($payload)->type($type),
        );
    }
}

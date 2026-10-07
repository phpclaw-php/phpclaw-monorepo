<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Telescope;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;

/**
 * Records phpClaw agent runs, model calls, tool calls and guard blocks as named Telescope event entries.
 */
final class PhpClawWatcher
{
    public const TYPE_AGENT_RUN = 'phpclaw_agent_run';

    public const TYPE_TOOL_CALL = 'phpclaw_tool_call';

    public const TYPE_GUARD_BLOCKED = 'phpclaw_guard_blocked';

    public const TYPE_PROVIDER_CALL = 'phpclaw_provider_call';

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
        $events->listen('phpclaw.provider.response', [self::class, 'recordProviderCall']);
    }

    /**
     * Record a completed agent run as a Telescope entry.
     *
     * @param  array<string, mixed>  $ctx  HookRegistry agent.after context.
     * @return void
     */
    public static function recordAgentRun(array $ctx): void
    {
        self::recordEntry(self::agentRunPayload($ctx));
    }

    /**
     * Record a tool invocation as a Telescope entry.
     *
     * @param  array<string, mixed>  $ctx  HookRegistry tool.after context.
     * @return void
     */
    public static function recordToolCall(array $ctx): void
    {
        self::recordEntry(self::toolCallPayload($ctx));
    }

    /**
     * Record a guard-blocked prompt as a Telescope entry.
     *
     * @param  array<string, mixed>  $ctx  HookRegistry guard.blocked context.
     * @return void
     */
    public static function recordGuardBlocked(array $ctx): void
    {
        self::recordEntry(self::guardBlockedPayload($ctx));
    }

    /**
     * Record one model call with the token counts the provider reported.
     *
     * @param  array<string, mixed>  $ctx  HookRegistry provider.response context.
     * @return void
     */
    public static function recordProviderCall(array $ctx): void
    {
        self::recordEntry(self::providerCallPayload($ctx));
    }

    /**
     * Build the metadata-only payload for a completed agent run; its tokens are on the run's provider call entries.
     *
     * @param  array<string, mixed>  $ctx  HookRegistry agent.after context.
     * @return array<string, mixed>
     */
    public static function agentRunPayload(array $ctx): array
    {
        return [
            'name' => self::TYPE_AGENT_RUN,
            'run_id' => $ctx['run_id'] ?? null,
            'provider' => $ctx['provider'] ?? null,
            'model' => $ctx['model'] ?? null,
            'iterations' => $ctx['iterations'] ?? null,
            'duration_ms' => $ctx['duration_ms'] ?? null,
            'tools_called' => $ctx['tools_called'] ?? [],
        ];
    }

    /**
     * Build the payload for one model call: provider, model and the token counts it reported.
     *
     * @param  array<string, mixed>  $ctx  HookRegistry provider.response context.
     * @return array<string, mixed>
     */
    public static function providerCallPayload(array $ctx): array
    {
        $inputTokens = (int) ($ctx['input_tokens'] ?? 0);
        $outputTokens = (int) ($ctx['output_tokens'] ?? 0);

        return [
            'name' => self::TYPE_PROVIDER_CALL,
            'run_id' => $ctx['run_id'] ?? null,
            'provider' => $ctx['provider'] ?? null,
            'model' => $ctx['model'] ?? null,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $inputTokens + $outputTokens,
        ];
    }

    /**
     * Build the metadata payload for a single tool invocation.
     *
     * @param  array<string, mixed>  $ctx  HookRegistry tool.after context.
     * @return array<string, mixed>
     */
    public static function toolCallPayload(array $ctx): array
    {
        return [
            'name' => self::TYPE_TOOL_CALL,
            'run_id' => $ctx['run_id'] ?? null,
            'tool_name' => $ctx['tool_name'] ?? null,
            'iteration' => $ctx['iteration'] ?? null,
            'tool_input' => $ctx['tool_input'] ?? null,
        ];
    }

    /**
     * Build the payload for a guard-blocked prompt (raw message omitted by design).
     *
     * @param  array<string, mixed>  $ctx  HookRegistry guard.blocked context.
     * @return array<string, mixed>
     */
    public static function guardBlockedPayload(array $ctx): array
    {
        return [
            'name' => self::TYPE_GUARD_BLOCKED,
            'reason' => $ctx['reason'] ?? null,
        ];
    }

    /**
     * Record the payload as a Telescope event entry, found in the Events tab by its `name`, when Telescope is installed.
     *
     * @param  array<string, mixed>  $payload  The entry payload, carrying its `name`.
     * @return void
     */
    private static function recordEntry(array $payload): void
    {
        if (! class_exists(Telescope::class)) {
            return;
        }

        Telescope::recordEvent(IncomingEntry::make($payload));
    }
}

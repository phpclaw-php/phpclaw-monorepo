<?php

declare(strict_types=1);

namespace PhpClaw\Hooks\Dispatchers;

use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;

/**
 * Typed dispatchers for security-guard events.
 */
final class GuardEventDispatcher
{
    /**
     * Fires when a guard blocks a message and throws GuardException.
     *
     * @param  string  $message  User message.
     * @param  string  $reason  Reason for the event.
     * @param  string|null  $guard  Short class name of the guard that blocked, when known.
     * @return void
     */
    public static function blocked(string $message, string $reason, ?string $guard = null): void
    {
        HookRegistry::fire(LifecycleEvent::GuardBlocked->value, [
            'message' => $message,
            'reason' => $reason,
            'guard' => $guard,
        ]);
    }

    /**
     * Fires when a caller exceeds the configured rate limit.
     *
     * @param  string  $callerId  Caller identifier.
     * @param  int  $count  Current request count in the window.
     * @param  int  $maxRequests  Maximum requests allowed.
     * @param  int  $windowSeconds  Rate-limit window in seconds.
     * @return void
     */
    public static function rateLimitExceeded(
        string $callerId,
        int $count,
        int $maxRequests,
        int $windowSeconds,
    ): void {
        HookRegistry::fire(LifecycleEvent::GuardRateLimitExceeded->value, [
            'caller_id' => $callerId,
            'count' => $count,
            'max_requests' => $maxRequests,
            'window_seconds' => $windowSeconds,
        ]);
    }

    /**
     * Fires when ToolOutputGuard redacts a sensitive pattern from a tool result.
     *
     * @param  string  $toolName  Tool name.
     * @param  string  $pattern  Matched pattern.
     * @return void
     */
    public static function toolOutputRedacted(string $toolName, string $pattern): void
    {
        HookRegistry::fire(LifecycleEvent::GuardToolOutputRedacted->value, [
            'tool_name' => $toolName,
            'pattern' => $pattern,
        ]);
    }

    /**
     * Fires when OutputSanitiser strips a PHP code tag from the LLM response.
     *
     * @param  string  $tag  PHP tag removed from the output.
     * @return void
     */
    public static function outputPhpTagRemoved(string $tag): void
    {
        HookRegistry::fire(LifecycleEvent::GuardOutputPhpTagRemoved->value, ['tag' => $tag]);
    }

    /**
     * Fires when OutputSanitiser replaces a dangerous function call with [REDACTED] in the LLM response.
     *
     * @param  string  $function  Function name redacted from the output.
     * @return void
     */
    public static function outputFunctionRedacted(string $function): void
    {
        HookRegistry::fire(LifecycleEvent::GuardOutputFunctionRedacted->value, ['function' => $function]);
    }
}

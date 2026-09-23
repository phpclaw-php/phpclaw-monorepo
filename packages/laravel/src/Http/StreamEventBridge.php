<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Http;

use PhpClaw\Hooks\Contracts\HookInterface;
use PhpClaw\Hooks\LifecycleEvent;

/**
 * Class-based hook listener that relays tool lifecycle events to the active SSE stream, holding SSE state in static properties by design for persistent-worker runtimes (Octane/RoadRunner) where a hook callback has no injection point.
 */
final class StreamEventBridge implements HookInterface
{
    private static ?\Closure $emitter = null;

    private static array $toolCalls = [];

    /**
     * Begin a stream: set the active emitter and reset the collected tool calls.
     *
     * @param  \Closure(string, array<string, mixed>): void  $emitter
     * @return void
     */
    public static function begin(\Closure $emitter): void
    {
        self::$emitter = $emitter;
        self::$toolCalls = [];
    }

    /**
     * End a stream: clear the active emitter so the listener no-ops between requests.
     *
     * @return void
     */
    public static function end(): void
    {
        self::$emitter = null;
        self::$toolCalls = [];
    }

    /**
     * Return the tool calls collected during the current/last stream.
     *
     * @return array<int, array{tool_name: string, tool_input: array<mixed>, tool_result: string}>
     */
    public static function toolCalls(): array
    {
        return self::$toolCalls;
    }

    /**
     * Relay a ToolBefore/ToolAfter event to the active SSE emitter.
     *
     * @param  array<string, mixed>  $context  Event context (carries the fired event name under 'event').
     * @return void
     */
    public function handle(array $context): void
    {
        $emit = self::$emitter;
        if ($emit === null) {
            return;
        }

        $event = (string) ($context['event'] ?? '');

        if ($event === LifecycleEvent::ToolBefore->value) {
            $emit('tool_before', [
                'tool_name' => (string) ($context['tool_name'] ?? ''),
                'tool_input' => (array) ($context['tool_input'] ?? []),
            ]);

            return;
        }

        if ($event === LifecycleEvent::ToolAfter->value) {
            $name = (string) ($context['tool_name'] ?? '');
            if ($name === '') {
                return;
            }

            $entry = [
                'tool_name' => $name,
                'tool_input' => (array) ($context['tool_input'] ?? []),
                'tool_result' => (string) ($context['tool_result'] ?? ''),
            ];

            self::$toolCalls[] = $entry;
            $emit('tool_after', $entry);
        }
    }
}

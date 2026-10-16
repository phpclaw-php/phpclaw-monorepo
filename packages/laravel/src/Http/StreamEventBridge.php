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
     * Build the beforePersist callback that stores the tool calls collected since begin() as tool rows before the
     * last assistant message.
     *
     * @return \Closure(array<string, mixed>): array<string, mixed>
     */
    public static function toolCallPersister(): \Closure
    {
        return static function (array $payload): array {
            if (self::$toolCalls === []) {
                return $payload;
            }

            $history = (array) ($payload['history'] ?? []);
            $rows = array_map(static fn (array $call): array => [
                'role' => 'tool',
                'content' => $call['tool_result'],
                'tool_name' => $call['tool_name'],
                'tool_input' => $call['tool_input'],
            ], self::$toolCalls);

            array_splice($history, self::lastAssistantIndex($history), 0, $rows);
            $payload['history'] = $history;

            return $payload;
        };
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

    /**
     * Index of the last assistant message, or the history length when there is none.
     *
     * @param  array<int, mixed>  $history  Conversation history.
     * @return int
     */
    private static function lastAssistantIndex(array $history): int
    {
        for ($index = count($history) - 1; $index >= 0; $index--) {
            if (is_array($history[$index]) && ($history[$index]['role'] ?? '') === 'assistant') {
                return $index;
            }
        }

        return count($history);
    }
}

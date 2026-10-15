<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Support;

use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;

/**
 * Splices tool-call steps into a conversation's history before it is persisted.
 */
final class ToolHistorySplicer
{
    /**
     * Register a listener that collects every tool call for the current turn.
     *
     * @param  array<int, array{tool_name: string, tool_input: array<string, mixed>, tool_result: string}>  $calls
     * @return \Closure The registered listener, for a caller that removes it after the turn.
     */
    public static function collect(array &$calls): \Closure
    {
        $listener = static function (array $context) use (&$calls): void {
            $calls[] = [
                'tool_name' => (string) ($context['tool_name'] ?? ''),
                'tool_input' => (array) ($context['tool_input'] ?? []),
                'tool_result' => (string) ($context['tool_result'] ?? ''),
            ];
        };
        HookRegistry::on(LifecycleEvent::ToolAfter->value, $listener);

        return $listener;
    }

    /**
     * Build a beforePersist callback that inserts the collected tool calls into the payload's history.
     *
     * @param  array<int, array{tool_name: string, tool_input: array<string, mixed>, tool_result: string}>  $calls
     * @return callable(array<string, mixed>): array<string, mixed>
     */
    public static function beforePersist(array &$calls): callable
    {
        return function (array $payload) use (&$calls): array {
            $toolCalls = array_values(array_filter(
                $calls,
                static fn (array $c): bool => $c['tool_name'] !== '',
            ));

            if ($toolCalls === []) {
                return $payload;
            }

            $history = (array) ($payload['history'] ?? $payload['messages'] ?? []);
            $insertAt = self::findLastAssistantIndex($history);
            $toolEntries = array_map(static fn (array $c): array => [
                'role' => 'tool',
                'content' => $c['tool_result'],
                'tool_name' => $c['tool_name'],
                'tool_input' => $c['tool_input'],
            ], $toolCalls);
            array_splice($history, $insertAt, 0, $toolEntries);
            $payload['history'] = $history;

            return $payload;
        };
    }

    /**
     * Find the index of the last assistant message, or the history length when there is none.
     *
     * @param  array<int, mixed>  $messages
     * @return int
     */
    private static function findLastAssistantIndex(array $messages): int
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (is_array($messages[$i]) && ($messages[$i]['role'] ?? '') === 'assistant') {
                return $i;
            }
        }

        return count($messages);
    }
}

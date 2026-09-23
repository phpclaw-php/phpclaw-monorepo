<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Support;

/**
 * Shared helper for splicing tool-call rows into conversation history payloads, used by
 * both DebugPanel and PhpClawCommand so rows persist identically on either surface.
 */
final class ToolHistorySplicer
{
    /**
     * Splice tool-call rows collected during a streamed turn into the persistence payload,
     * before the last assistant message so history reads user, then tools, then assistant.
     *
     * @param  array<string, mixed>  $payload  Conversation payload about to be persisted.
     * @param  list<array<string, mixed>>  $collectedToolCalls  All tool calls captured via HookRegistry during this turn.
     * @return array<string, mixed> Mutated payload (or original if no tool calls).
     */
    public static function splice(array $payload, array $collectedToolCalls): array
    {
        $toolCalls = array_values(array_filter(
            $collectedToolCalls,
            static fn (array $c): bool => ($c['tool_name'] ?? '') !== '',
        ));

        if ($toolCalls === []) {
            return $payload;
        }

        $history = (array) ($payload['history'] ?? $payload['messages'] ?? []);
        $payload['history'] = self::insert($history, $toolCalls);

        return $payload;
    }

    /**
     * Insert tool entries into history at the correct position.
     *
     * @param  array<int, array<string, mixed>>  $history  Existing history rows.
     * @param  list<array<string, mixed>>  $toolCalls  Validated (non-empty tool_name) tool call entries.
     * @return array<int, array<string, mixed>>
     */
    private static function insert(array $history, array $toolCalls): array
    {
        $insertAt = self::lastAssistantIndex($history);
        $toolEntries = array_map(static fn (array $c): array => [
            'role' => 'tool',
            'content' => $c['tool_result'],
            'tool_name' => $c['tool_name'],
            'tool_input' => $c['tool_input'],
        ], $toolCalls);

        array_splice($history, $insertAt, 0, $toolEntries);

        return $history;
    }

    /**
     * Find the index of the last assistant message, or count($messages) if none found.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @return int
     */
    private static function lastAssistantIndex(array $messages): int
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (is_array($messages[$i]) && ($messages[$i]['role'] ?? '') === 'assistant') {
                return $i;
            }
        }

        return count($messages);
    }
}

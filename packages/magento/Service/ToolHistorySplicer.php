<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Service;

/**
 * Splices tool-call entries into a conversation history array.
 */
final class ToolHistorySplicer
{
    /**
     * Insert $toolCalls entries into $history at $insertIndex.
     *
     * @param  array<int, array<string, mixed>>  $history  Full conversation history.
     * @param  array<int, array<string, mixed>>  $toolCalls  Collected tool-call entries to splice in.
     * @param  int  $insertIndex  Position after which the entries are inserted.
     * @return array<int, array<string, mixed>> Updated history with tool entries spliced in.
     */
    public function splice(array $history, array $toolCalls, int $insertIndex): array
    {
        $toolEntries = array_map(static fn (array $c): array => [
            'role' => 'tool',
            'content' => $c['tool_result'],
            'tool_name' => $c['tool_name'],
            'tool_input' => $c['tool_input'],
        ], $toolCalls);

        array_splice($history, $insertIndex, 0, $toolEntries);

        return $history;
    }

    /**
     * Return the index of the last assistant entry in $history.
     *
     * @param  array<int, array<string, mixed>>  $history  Ordered message array.
     * @return int Index of the last assistant entry, or count($history) when absent.
     */
    public function findLastAssistantIndex(array $history): int
    {
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if (($history[$i]['role'] ?? '') === 'assistant') {
                return $i;
            }
        }

        return count($history);
    }
}

<?php

declare(strict_types=1);

namespace PhpClaw\Agent;

use PhpClaw\Hooks\HookDispatcher;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Support\Log;

/**
 * Summarises the oldest half of conversation history when it grows past a configured ceiling, then fires the `context.overflow` hook.
 */
final class HistoryCompactor
{
    private const CHARS_PER_TOKEN_ESTIMATE = 4;

    /**
     * Build a HistoryCompactor.
     *
     * @param  ProviderInterface  $provider  Provider used to summarise overflowing history.
     * @param  int  $maxHistoryLength  History-length ceiling that triggers compaction (0 disables).
     * @param  bool  $enabled  Whether history compaction runs (false disables the summary call).
     * @param  int  $maxTokens  Estimated-token ceiling that triggers compaction (0 = count-based only).
     */
    public function __construct(
        private readonly ProviderInterface $provider,
        private readonly int $maxHistoryLength,
        private readonly bool $enabled = true,
        private readonly int $maxTokens = 0,
    ) {}

    /**
     * Compact history when it exceeds the configured ceiling; fires `context.overflow`.
     *
     * @param  Message[]  $history  Current conversation history.
     * @param  string  $message  Original user message; included in the overflow hook payload.
     * @param  string  $runId  Run identifier propagated to hooks for run correlation.
     * @param  string  $iterationId  Parent run id for nesting the overflow hook.
     * @return Message[] Either the original history or the compacted history.
     */
    public function applyIfOversized(array $history, string $message, string $runId, string $iterationId): array
    {
        if (! $this->enabled) {
            return $history;
        }

        $isCountOverflow = $this->maxHistoryLength > 0 && count($history) > $this->maxHistoryLength;
        $isTokenOverflow = $this->maxTokens > 0 && $this->estimateTokens($history) > $this->maxTokens;

        if (! $isCountOverflow && ! $isTokenOverflow) {
            return $history;
        }

        $history = $this->summariseOldestHalf($history, $runId, $iterationId);

        HookDispatcher::contextOverflow(
            message: $message,
            historyLength: count($history),
            maxHistoryLength: $this->maxHistoryLength,
            provider: $this->provider->name(),
            model: $this->provider->model(),
            runId: $runId,
            parentRunId: $iterationId,
        );

        return $history;
    }

    /**
     * Summarise the oldest half of history into a single assistant message, then prepend it to the recent half.
     *
     * @param  Message[]  $history  Full conversation history to compact.
     * @param  string  $runId  Run identifier propagated to the compaction hooks.
     * @param  string  $iterationId  Parent run id for nesting the compaction hooks.
     * @return Message[] One summary message followed by the recent half.
     */
    private function summariseOldestHalf(array $history, string $runId, string $iterationId): array
    {
        $total = count($history);
        $keep = (int) ceil($total / 2);
        $old = array_slice($history, 0, $total - $keep);
        $recent = array_slice($history, $total - $keep);

        $summaryRequest = array_merge($old, [
            Message::user('Summarise the conversation above in 2–3 sentences. Be concise.'),
        ]);

        HookDispatcher::compactionBefore(
            provider: $this->provider->name(),
            model: $this->provider->model(),
            runId: $runId,
            parentRunId: $iterationId,
        );

        try {
            $response = $this->provider->send($summaryRequest, []);
            $summary = (string) ($response['text'] ?? '');
        } catch (\Throwable $e) {
            Log::warning('[phpClaw] HistoryCompactor: summary request failed: '.$e->getMessage());
            $summary = '(summary unavailable)';
        }

        HookDispatcher::compactionAfter(
            summary: $summary,
            runId: $runId,
            parentRunId: $iterationId,
        );

        return array_merge(
            [Message::assistant("[Conversation summary]\n{$summary}")],
            $recent,
        );
    }

    /**
     * Rough token estimate for the history using a chars/4 heuristic over the bulky fields.
     *
     * @param  Message[]  $history  Conversation history.
     * @return int Estimated token count.
     */
    private function estimateTokens(array $history): int
    {
        $chars = 0;

        foreach ($history as $message) {
            $chars += strlen($message->content);

            if ($message->toolInput !== null) {
                $chars += strlen((string) json_encode($message->toolInput));
            }

            if ($message->batchCalls !== null) {
                $chars += strlen((string) json_encode($message->batchCalls));
            }

            if ($message->batchResults !== null) {
                $chars += array_sum(array_map('strlen', $message->batchResults));
            }
        }

        return (int) ($chars / self::CHARS_PER_TOKEN_ESTIMATE);
    }
}

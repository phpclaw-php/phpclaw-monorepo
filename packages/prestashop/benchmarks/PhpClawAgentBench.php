<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Benchmarks;

/**
 * Micro-benchmarks for agent-loop message construction, payload serialization, and history processing.
 *
 * @Iterations(3)
 *
 * @Revs(50)
 */
class PhpClawAgentBench
{
    /**
     * @Subject
     *
     * @return void
     */
    public function bench_simple_message_construction(): void
    {
        $message = [
            'role' => 'user',
            'content' => 'How many orders were placed this week?',
        ];
        $payload = ['id' => '01JABC', 'history' => [$message]];
        unset($payload);
    }

    /**
     * @Subject
     *
     * @return void
     */
    public function bench_payload_serialization(): void
    {
        $payload = [
            'id' => '01JABCDEF',
            'namespace' => 'default',
            'title' => 'Weekly order review',
            'created_at' => '2024-01-15 10:00:00',
            'history' => array_fill(0, 10, [
                'role' => 'assistant',
                'content' => 'There were 12 orders totalling EUR 1,847.50.',
            ]),
        ];
        json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @Subject
     *
     * @return void
     */
    public function bench_history_array_processing(): void
    {
        $history = array_fill(0, 20, ['role' => 'user', 'content' => 'x']);
        $kept = array_values(array_filter(
            $history,
            static fn (array $m): bool => in_array($m['role'] ?? '', ['user', 'assistant', 'tool'], true),
        ));
        unset($kept);
    }
}

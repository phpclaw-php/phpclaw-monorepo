<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Benchmarks;

/**
 * @Iterations(3)
 *
 * @Revs(50)
 */
// non-final: Magento interceptor required
class PhpClawAgentBench
{
    /**
     * @return void
     *
     * @Subject
     */
    public function bench_simple_message_construction(): void
    {
        $msgs = [];
        for ($i = 0; $i < 10; $i++) {
            $msgs[] = ['role' => 'user', 'content' => "Message {$i}"];
        }
    }

    /**
     * @return void
     *
     * @Subject
     */
    public function bench_plan_serialization(): void
    {
        $plan = ['steps' => array_fill(0, 10, ['action' => 'tool', 'tool' => 'test'])];
        json_encode($plan);
        json_decode(json_encode($plan), true);
    }

    /**
     * @return void
     *
     * @Subject
     */
    public function bench_message_array_processing(): void
    {
        $combined = [];
        for ($i = 0; $i < 50; $i++) {
            $combined[] = ['role' => 'user', 'content' => "Test message {$i}"];
        }
        $filtered = array_filter($combined, fn ($m) => isset($m['content']));
        array_values($filtered);
    }
}

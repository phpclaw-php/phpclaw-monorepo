<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Benchmarks;

/**
 * @BeforeMethods({"setUp"})
 *
 * @Iterations(3)
 *
 * @Revs(50)
 */
class PhpClawAgentBench
{
    private array $messages = [];

    public function setUp(): void
    {
        $this->messages = [
            ['role' => 'user', 'content' => 'What is 2+2?'],
        ];
    }

    /**
     * @Subject
     *
     * @return void
     */
    public function bench_simple_message_construction(): void
    {
        $msgs = [];
        for ($i = 0; $i < 10; $i++) {
            $msgs[] = ['role' => 'user', 'content' => "Message {$i}"];
        }
    }

    /**
     * @Subject
     *
     * @return void
     */
    public function bench_plan_serialization(): void
    {
        $plan = ['steps' => array_fill(0, 10, ['action' => 'tool', 'tool' => 'test'])];
        json_encode($plan);
        json_decode(json_encode($plan), true);
    }

    /**
     * @Subject
     *
     * @return void
     */
    public function bench_message_array_processing(): void
    {
        $combined = [];
        for ($i = 0; $i < 50; $i++) {
            $combined[] = ['role' => 'user', 'content' => "Test message {$i}"];
        }
        $filtered = array_filter($combined, fn ($m) => isset($m['content']));
        $sorted = array_values($filtered);
    }
}

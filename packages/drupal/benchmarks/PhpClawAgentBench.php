<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Benchmarks;

use PhpBench\Attributes as Bench;

/**
 * Benchmarks for message construction, plan serialization, and array processing.
 */
#[Bench\BeforeMethods(['setUp'])]
#[Bench\Iterations(3)]
#[Bench\Revs(50)]
final class PhpClawAgentBench
{
    private array $messages = [];

    /**
     * Seed the baseline message list before each benchmark run.
     *
     * @return void
     */
    public function setUp(): void
    {
        $this->messages = [
            ['role' => 'user', 'content' => 'What is 2+2?'],
        ];
    }

    /**
     * Benchmark building a small message array.
     *
     * @return void
     */
    #[Bench\Subject]
    public function bench_simple_message_construction(): void
    {
        $msgs = [];
        for ($i = 0; $i < 10; $i++) {
            $msgs[] = ['role' => 'user', 'content' => "Message {$i}"];
        }
    }

    /**
     * Benchmark JSON round-trip serialization of a plan.
     *
     * @return void
     */
    #[Bench\Subject]
    public function bench_plan_serialization(): void
    {
        $plan = ['steps' => array_fill(0, 10, ['action' => 'tool', 'tool' => 'test'])];
        json_encode($plan);
        json_decode(json_encode($plan), true);
    }

    /**
     * Benchmark filtering and re-indexing a message array.
     *
     * @return void
     */
    #[Bench\Subject]
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

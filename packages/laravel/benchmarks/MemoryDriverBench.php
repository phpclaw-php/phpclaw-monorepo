<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Benchmarks;

/**
 * @Iterations(3)
 *
 * @Revs(100)
 */
final class MemoryDriverBench
{
    private array $store = [];

    /**
     * Benchmark writing a value into the in-memory store.
     *
     * @return void
     *
     * @Subject
     */
    public function bench_memory_set(): void
    {
        $key = 'test:'.rand(1, 1000);
        $this->store[$key] = ['data' => str_repeat('x', 100), 'ts' => microtime(true)];
    }

    /**
     * Benchmark reading a value from the in-memory store.
     *
     * @return void
     *
     * @Subject
     */
    public function bench_memory_get(): void
    {
        $this->store['test_key'] = ['data' => 'value'];
        $val = $this->store['test_key'] ?? null;
    }

    /**
     * Benchmark the namespace-to-driver routing decision.
     *
     * @return void
     *
     * @Subject
     */
    public function bench_memory_namespace_routing(): void
    {
        foreach (['conversations', 'phpclaw_jobs', 'cache', 'default'] as $namespace) {
            $target = $namespace === 'conversations' ? 'conversation' : 'kv';
        }
    }

    /**
     * Benchmark JSON serialization round-tripping.
     *
     * @return void
     *
     * @Subject
     */
    public function bench_memory_serialize(): void
    {
        $data = ['conv_id' => 'abc123', 'messages' => array_fill(0, 20, 'msg')];
        $encoded = json_encode($data);
        $decoded = json_decode($encoded, true);
    }
}

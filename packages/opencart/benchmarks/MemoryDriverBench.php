<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Benchmarks;

/**
 * @Iterations(3)
 *
 * @Revs(100)
 */
final class MemoryDriverBench
{
    private array $store = [];

    /**
     * @Subject
     *
     * @return void
     */
    public function bench_memory_set(): void
    {
        $key = 'test:'.rand(1, 1000);
        $this->store[$key] = ['data' => str_repeat('x', 100), 'ts' => microtime(true)];
    }

    /**
     * @Subject
     *
     * @return void
     */
    public function bench_memory_get(): void
    {
        $this->store['test_key'] = ['data' => 'value'];
        $val = $this->store['test_key'] ?? null;
    }

    /**
     * @Subject
     *
     * @return void
     */
    public function bench_memory_serialize(): void
    {
        $data = ['conv_id' => 'abc123', 'messages' => array_fill(0, 20, 'msg')];
        $encoded = json_encode($data);
        $decoded = json_decode($encoded, true);
    }
}

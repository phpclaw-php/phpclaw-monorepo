<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Benchmarks;

/**
 * @Iterations(3)
 *
 * @Revs(100)
 */
// non-final: Magento interceptor required
class MemoryDriverBench
{
    private array $store = [];

    private int $counter = 0;

    /**
     * @return void
     *
     * @Subject
     */
    public function bench_memory_set(): void
    {
        $key = 'test:'.$this->counter++;
        $this->store[$key] = ['data' => str_repeat('x', 100), 'ts' => 0.0];
    }

    /**
     * @return void
     *
     * @Subject
     */
    public function bench_memory_get(): void
    {
        $this->store['test_key'] = ['data' => 'value'];
        $this->store['test_key'] ?? null;
    }

    /**
     * @return void
     *
     * @Subject
     */
    public function bench_memory_serialize(): void
    {
        $data = ['conv_id' => 'abc123', 'messages' => array_fill(0, 20, 'msg')];
        $encoded = json_encode($data);
        json_decode($encoded, true);
    }
}

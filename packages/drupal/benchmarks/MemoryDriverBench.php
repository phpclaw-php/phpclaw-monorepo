<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Benchmarks;

use PhpBench\Attributes as Bench;

/**
 * Benchmarks for in-process memory set/get/serialize operations.
 */
#[Bench\Iterations(3)]
#[Bench\Revs(100)]
final class MemoryDriverBench
{
    private array $store = [];

    private int $setCounter = 0;

    /**
     * Benchmark writing an entry to the in-process store.
     *
     * @return void
     */
    #[Bench\Subject]
    public function bench_memory_set(): void
    {
        $key = 'test:'.$this->setCounter++;
        $this->store[$key] = ['data' => str_repeat('x', 100), 'ts' => microtime(true)];
    }

    /**
     * Benchmark reading an entry from the in-process store.
     *
     * @return void
     */
    #[Bench\Subject]
    public function bench_memory_get(): void
    {
        $this->store['test_key'] = ['data' => 'value'];
        $val = $this->store['test_key'] ?? null;
    }

    /**
     * Benchmark JSON round-trip serialization of memory data.
     *
     * @return void
     */
    #[Bench\Subject]
    public function bench_memory_serialize(): void
    {
        $data = ['conv_id' => 'abc123', 'messages' => array_fill(0, 20, 'msg')];
        $encoded = json_encode($data);
        $decoded = json_decode($encoded, true);
    }
}

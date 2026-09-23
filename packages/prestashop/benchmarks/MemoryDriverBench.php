<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Benchmarks;

/**
 * Micro-benchmarks for the memory-driver encode/decode value path.
 *
 * @Iterations(3)
 *
 * @Revs(50)
 */
class MemoryDriverBench
{
    /**
     * @Subject
     *
     * @return void
     */
    public function bench_memory_set_encode(): void
    {
        $value = ['provider' => 'ollama', 'model' => 'qwen2.5:7b', 'enabled' => true];
        $envelope = json_encode(['t' => 'array', 'v' => $value], JSON_UNESCAPED_UNICODE);
        unset($envelope);
    }

    /**
     * @Subject
     *
     * @return void
     */
    public function bench_memory_get_decode(): void
    {
        $raw = '{"t":"array","v":{"provider":"ollama","model":"qwen2.5:7b","enabled":true}}';
        $decoded = json_decode($raw, true);
        $value = $decoded['v'] ?? null;
        unset($value);
    }

    /**
     * @Subject
     *
     * @return void
     */
    public function bench_memory_serialize_scalar(): void
    {
        $value = 'a stored scalar memory value';
        $out = is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE);
        unset($out);
    }
}

<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Benchmarks;

/**
 * Benchmarks the UTC expiry helpers shared by the Doctrine memory drivers.
 *
 * @Iterations(3)
 *
 * @Revs(50)
 */
final class MemoryDriverBench
{
    /**
     * Benchmark computing a UTC expiry timestamp from a TTL.
     *
     * @return void
     *
     * @Subject
     */
    public function bench_utc_expiry_compute(): void
    {
        $ttl = 300;
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $expiresAt = $now->modify('+'.$ttl.' seconds')->format('Y-m-d H:i:s');
    }

    /**
     * Benchmark the UTC-correct expiry comparison.
     *
     * @return void
     *
     * @Subject
     */
    public function bench_is_expired_check(): void
    {
        $expiresAt = '2020-01-01 00:00:00';
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $expired = new \DateTimeImmutable($expiresAt, new \DateTimeZone('UTC')) <= $now;
    }
}

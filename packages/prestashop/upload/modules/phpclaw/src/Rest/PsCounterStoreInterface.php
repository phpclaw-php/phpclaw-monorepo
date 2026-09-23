<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Rest;

/**
 * Backing store for the fixed-window rate limiter: increments a windowed counter and returns its new value.
 */
interface PsCounterStoreInterface
{
    /**
     * Increment the counter for a key within its window and return the new value.
     *
     * @param  string  $key  Window-scoped counter key.
     * @param  int  $ttl  Seconds the counter should live (the window length).
     * @return int The counter value after incrementing, or 0 when no backend is available (fail-open).
     */
    public function increment(string $key, int $ttl): int;
}

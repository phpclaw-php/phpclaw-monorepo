<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Rest;

/**
 * APCu-backed counter store; fails open (returns 0) when the APCu extension is unavailable.
 */
final class ApcuCounterStore implements PsCounterStoreInterface
{
    /**
     * Increment the APCu counter for a key and return the new value, or 0 when APCu is unavailable.
     *
     * @param  string  $key  Window-scoped counter key.
     * @param  int  $ttl  Seconds the counter should live (the window length).
     * @return int The counter value after incrementing, or 0 when APCu is disabled.
     */
    public function increment(string $key, int $ttl): int
    {
        if (! function_exists('apcu_enabled') || ! apcu_enabled()) {
            return 0;
        }

        $success = false;
        $new = apcu_inc($key, 1, $success, $ttl);

        if ($success === false || ! is_int($new)) {
            return 0;
        }

        return $new;
    }
}

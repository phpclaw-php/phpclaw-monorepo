<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Rest;

/**
 * Fixed-window, fail-open rate limiter for the phpClaw REST front controller.
 */
final class PsRateLimiter
{
    /**
     * Create a new PsRateLimiter instance.
     *
     * @param  PsCounterStoreInterface  $store  Counter backend keyed per window.
     * @param  int  $limit  Maximum requests per client per window.
     * @param  int  $window  Window length in seconds.
     */
    public function __construct(
        private readonly PsCounterStoreInterface $store,
        private readonly int $limit = 60,
        private readonly int $window = 60,
    ) {}

    /**
     * Whether the client has exceeded the window budget; fails open when no counter backend is available.
     *
     * @param  string  $clientId  Stable client identifier (typically the remote IP).
     * @return bool True when the caller must be rejected with HTTP 429.
     */
    public function tooManyRequests(string $clientId): bool
    {
        if ($this->limit <= 0) {
            return false;
        }

        $bucket = intdiv(time(), max(1, $this->window));
        $key = 'phpclaw_rl_'.sha1($clientId).'_'.$bucket;

        return $this->store->increment($key, $this->window) > $this->limit;
    }
}

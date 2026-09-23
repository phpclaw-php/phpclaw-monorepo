<?php

declare(strict_types=1);

namespace PhpClaw\Guards;

use PhpClaw\AutoDiscovery\Attributes\Guard;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Guards\Contracts\GuardInterface;
use PhpClaw\Hooks\HookDispatcher;

/**
 * Sliding-window rate limiter guard: enforces a maximum number of calls per time window per caller identity.
 */
#[Guard(priority: -1, name: 'rate_limit', label: 'Rate Limit', enabledByDefault: false, since: '1.0.0')]
final class RateLimitGuard implements GuardInterface
{
    public const DEFAULT_MAX_REQUESTS = 60;

    public const DEFAULT_WINDOW_SECONDS = 60;

    private const CLI_CALLER_ID = 'cli';

    private const KEY_COUNT = 'count';

    private const KEY_WINDOW_START = 'window_start';

    private static array $buckets = [];

    /**
     * Configure the rate-limit guard.
     *
     * @param  int  $maxRequests  Maximum requests allowed per window.
     * @param  int  $windowSeconds  Length of the sliding window in seconds.
     * @param  callable|null  $callerIdResolver  Returns a string caller ID.
     * @return void
     */
    public function __construct(
        private readonly int $maxRequests = self::DEFAULT_MAX_REQUESTS,
        private readonly int $windowSeconds = self::DEFAULT_WINDOW_SECONDS,
        private readonly mixed $callerIdResolver = null,
    ) {}

    /**
     * Increment the caller's request count for the current window, throwing when the limit is exceeded.
     *
     * @param  string  $message  The user message (ignored, only call count matters).
     * @return void
     *
     * @throws GuardException When the caller has exceeded the rate limit.
     */
    public function scan(string $message): void
    {
        $callerId = $this->resolveCallerId();
        $now = time();

        if ($this->shouldStartNewWindow($callerId, $now)) {
            self::$buckets[$callerId] = [self::KEY_COUNT => 1, self::KEY_WINDOW_START => $now];

            return;
        }

        self::$buckets[$callerId][self::KEY_COUNT]++;

        $count = self::$buckets[$callerId][self::KEY_COUNT];

        if ($count > $this->maxRequests) {
            $this->raise($callerId, $count);
        }
    }

    /**
     * Reset all rate-limit buckets. Required between tests.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$buckets = [];
    }

    /**
     * True when no bucket exists for this caller, or the existing window has expired.
     *
     * @param  string  $callerId  Unique caller identifier.
     * @param  int  $now  Current Unix timestamp.
     * @return bool
     */
    private function shouldStartNewWindow(string $callerId, int $now): bool
    {
        $bucket = self::$buckets[$callerId] ?? null;

        if ($bucket === null) {
            return true;
        }

        return ($now - $bucket[self::KEY_WINDOW_START]) >= $this->windowSeconds;
    }

    /**
     * Resolve the caller ID via the supplied resolver, or fall back to REMOTE_ADDR / 'cli'.
     *
     * @return string
     */
    private function resolveCallerId(): string
    {
        if ($this->callerIdResolver !== null) {
            return (string) ($this->callerIdResolver)();
        }

        return (string) ($_SERVER['REMOTE_ADDR'] ?? self::CLI_CALLER_ID);
    }

    /**
     * Fire the rate-limit-exceeded hook and throw a GuardException.
     *
     * @param  string  $callerId  Caller that breached the limit.
     * @param  int  $count  Number of requests in the current window.
     * @return never
     *
     * @throws GuardException Always: this method never returns normally.
     */
    private function raise(string $callerId, int $count): never
    {
        HookDispatcher::guardRateLimitExceeded(
            callerId: $callerId,
            count: $count,
            maxRequests: $this->maxRequests,
            windowSeconds: $this->windowSeconds,
        );

        throw new GuardException(
            "Rate limit exceeded: maximum {$this->maxRequests} requests"
            ." per {$this->windowSeconds} seconds.",
            guardClass: self::class,
        );
    }
}

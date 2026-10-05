<?php

declare(strict_types=1);

namespace PhpClaw\Guards;

use PhpClaw\AutoDiscovery\Attributes\Guard;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Guards\Contracts\GuardInterface;
use PhpClaw\Hooks\HookDispatcher;
use PhpClaw\Support\Log;

/**
 * Fixed-window rate limiter guard: enforces a maximum number of calls per time window per caller identity, counted in APCu when it is enabled (shared by every request on one server) and in process memory otherwise; ThrottledProvider is the outbound counterpart, throttling calls made to the provider.
 */
#[Guard(priority: -1, name: 'rate_limit', label: 'Rate Limit', enabledByDefault: false, since: '1.0.0')]
final class RateLimitGuard implements GuardInterface
{
    public const DEFAULT_MAX_REQUESTS = 60;

    public const DEFAULT_WINDOW_SECONDS = 60;

    private const CLI_CALLER_ID = 'cli';

    private const KEY_COUNT = 'count';

    private const KEY_WINDOW_START = 'window_start';

    private const APCU_PREFIX = 'phpclaw_rl_';

    private static array $buckets = [];

    private static bool $warnedNoSharedStore = false;

    /**
     * Configure the rate-limit guard.
     *
     * @param  int  $maxRequests  Maximum requests allowed per window.
     * @param  int  $windowSeconds  Duration of the fixed window in seconds.
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

        $count = self::sharedStoreAvailable()
            ? $this->countInSharedStore($callerId)
            : $this->countInProcess($callerId, time());

        if ($count > $this->maxRequests) {
            $this->raise($callerId, $count);
        }
    }

    /**
     * Reset the in-process buckets, the per-process warning flag and this guard's APCu counters. Required between tests.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$buckets = [];
        self::$warnedNoSharedStore = false;

        if (self::sharedStoreAvailable() && class_exists(\APCUIterator::class)) {
            foreach (new \APCUIterator('/^'.preg_quote(self::APCU_PREFIX, '/').'/') as $item) {
                \apcu_delete($item['key']);
            }
        }
    }

    /**
     * Whether APCu is loaded and enabled for this SAPI.
     *
     * @return bool
     */
    private static function sharedStoreAvailable(): bool
    {
        return function_exists('apcu_enabled') && \apcu_enabled() && function_exists('apcu_inc');
    }

    /**
     * Increment the caller's APCu counter for the current window; falls back to process memory when APCu refuses the write.
     *
     * @param  string  $callerId  Unique caller identifier.
     * @return int The caller's count in the current window.
     */
    private function countInSharedStore(string $callerId): int
    {
        $ok = false;
        $count = \apcu_inc(self::APCU_PREFIX.$this->windowSeconds.'_'.$callerId, 1, $ok, $this->windowSeconds);

        if ($ok && is_int($count)) {
            return $count;
        }

        return $this->countInProcess($callerId, time());
    }

    /**
     * Increment the caller's in-process counter for the current window, logging outside the CLI (once per request) that it is not shared.
     *
     * @param  string  $callerId  Unique caller identifier.
     * @param  int  $now  Current Unix timestamp.
     * @return int The caller's count in the current window.
     */
    private function countInProcess(string $callerId, int $now): int
    {
        self::warnNoSharedStoreOnce();

        if ($this->shouldStartNewWindow($callerId, $now)) {
            self::$buckets[$callerId] = [self::KEY_COUNT => 1, self::KEY_WINDOW_START => $now];

            return 1;
        }

        self::$buckets[$callerId][self::KEY_COUNT]++;

        return (int) self::$buckets[$callerId][self::KEY_COUNT];
    }

    /**
     * Log, once per request and only outside the CLI, that counts are per process without APCu.
     *
     * @return void
     */
    private static function warnNoSharedStoreOnce(): void
    {
        if (self::$warnedNoSharedStore || \PHP_SAPI === 'cli') {
            return;
        }

        self::$warnedNoSharedStore = true;
        Log::warning('[phpClaw] RateLimitGuard: APCu is not enabled, so counts are per process and do not limit calls across requests. Enable ext-apcu for a shared limit.');
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

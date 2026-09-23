<?php

declare(strict_types=1);

namespace PhpClaw\Mcp;

/**
 * Security utilities for the MCP server; the rate-limit table is static as the single-process fallback to the APCu shared store, reset via resetRateLimits() for tests.
 */
final class McpSecurity
{
    private const DEFAULT_RATE_LIMIT = 60;

    private const WINDOW_SECONDS = 60;

    private const RL_PREFIX = 'phpclaw_mcp_rl_';

    private static array $rateLimits = [];

    private static bool $warnedNoApcu = false;

    /**
     * Timing-safe token comparison.
     *
     * @param  string  $provided  Token sent by the client.
     * @param  string  $expected  Token configured on the server.
     * @return bool True when the tokens match in constant time.
     */
    public static function validateToken(string $provided, string $expected): bool
    {
        if ($provided === '' || $expected === '') {
            return false;
        }

        return hash_equals($expected, $provided);
    }

    /**
     * Check whether an IP address has exceeded the rate limit.
     *
     * @param  string  $ip  Client IP address.
     * @param  int  $limit  Maximum requests per minute (default 60).
     * @return bool True when the request is within the limit for the current window.
     */
    public static function rateLimit(string $ip, int $limit = self::DEFAULT_RATE_LIMIT): bool
    {
        if (self::apcuAvailable()) {
            $ok = false;
            $count = \apcu_inc(self::RL_PREFIX.$ip, 1, $ok, self::WINDOW_SECONDS);
            if ($ok && is_int($count)) {
                return $count <= $limit;
            }
        }

        self::warnNoSharedStoreOnce();

        return self::rateLimitMemory($ip, $limit, time());
    }

    /**
     * Get the current request count for an IP in the current window.
     *
     * @param  string  $ip  Client IP address.
     * @return int Requests recorded for this IP in the current window.
     */
    public static function getRequestCount(string $ip): int
    {
        if (self::apcuAvailable()) {
            $v = \apcu_fetch(self::RL_PREFIX.$ip);

            return is_int($v) ? $v : 0;
        }

        if (! isset(self::$rateLimits[$ip])) {
            return 0;
        }

        $now = time();
        if (($now - self::$rateLimits[$ip]['windowStart']) >= self::WINDOW_SECONDS) {
            return 0;
        }

        return self::$rateLimits[$ip]['count'];
    }

    /**
     * Check whether a tool is allowed by the given allow/deny lists.
     *
     * @param  string  $toolName  Tool name to check.
     * @param  string[]  $allow  Allow list (empty = allow all).
     * @param  string[]  $deny  Deny list (always wins over allow).
     * @return bool True when the tool passes both lists.
     */
    public static function isToolAllowed(string $toolName, array $allow = [], array $deny = []): bool
    {
        if (in_array($toolName, $deny, true)) {
            return false;
        }

        if ($allow === []) {
            return true;
        }

        return in_array($toolName, $allow, true);
    }

    /**
     * Reset all rate-limit counters. Used in tests.
     *
     * @return void
     */
    public static function resetRateLimits(): void
    {
        self::$rateLimits = [];
        self::$warnedNoApcu = false;

        if (self::apcuAvailable() && class_exists(\APCUIterator::class)) {
            foreach (new \APCUIterator('/^'.preg_quote(self::RL_PREFIX, '/').'/') as $item) {
                \apcu_delete($item['key']);
            }
        }
    }

    /**
     * In-process rate-limit fallback (stdio/single-process, or when APCu is unavailable).
     *
     * @param  string  $ip  Client IP address.
     * @param  int  $limit  Maximum requests per window.
     * @param  int  $now  Current timestamp.
     * @return bool True when the request is within the limit for the current window.
     */
    private static function rateLimitMemory(string $ip, int $limit, int $now): bool
    {
        if (! isset(self::$rateLimits[$ip]) || ($now - self::$rateLimits[$ip]['windowStart']) >= self::WINDOW_SECONDS) {
            self::$rateLimits[$ip] = [
                'count' => 1,
                'windowStart' => $now,
            ];

            return true;
        }

        self::$rateLimits[$ip]['count']++;

        return self::$rateLimits[$ip]['count'] <= $limit;
    }

    /**
     * Whether a process-shared APCu store is usable for rate limiting.
     *
     * @return bool True when every APCu function required for shared counting is available.
     */
    private static function apcuAvailable(): bool
    {
        return function_exists('apcu_enabled')
            && \apcu_enabled()
            && function_exists('apcu_inc')
            && function_exists('apcu_fetch');
    }

    /**
     * Emit a one-time warning (HTTP contexts only) that rate limiting is per-process without APCu.
     *
     * @return void
     */
    private static function warnNoSharedStoreOnce(): void
    {
        if (self::$warnedNoApcu || \PHP_SAPI === 'cli') {
            return;
        }
        self::$warnedNoApcu = true;
        error_log('phpClaw MCP: APCu unavailable, rate limiting is per-process and ineffective over HTTP/FPM. Install ext-apcu for a shared limit.');
    }
}

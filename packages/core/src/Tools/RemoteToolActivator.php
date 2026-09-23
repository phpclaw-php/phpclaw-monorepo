<?php

declare(strict_types=1);

namespace PhpClaw\Tools;

use PhpClaw\Config\EnvVars;
use PhpClaw\Support\Log;
use PhpClaw\Support\SsrfValidator;
use PhpClaw\Tools\Contracts\ToolInterface;

/**
 * Remote tool activation: fetches an HTTPS profile that only selects among already-installed local tools (SSRF-guarded, 64KB cap, 1h cache).
 */
final class RemoteToolActivator
{
    private const CACHE_SUBDIR = 'phpclaw-tool-profiles';

    private const CACHE_TTL = 3600;

    private const MAX_BYTES = 65536;

    private const TIMEOUT = 5;

    /**
     * Fetch and validate a remote tool profile.
     *
     * @param  string  $url  HTTPS URL of a tool profile JSON.
     * @return array{profile: string, tools: string[], max_tools_per_turn: int}|null Profile, or null on failure.
     */
    public static function fetch(string $url): ?array
    {
        if (! str_starts_with($url, 'https://')) {
            Log::warning("[phpClaw] RemoteToolActivator: HTTPS required, skipped: {$url}");

            return null;
        }

        try {
            $resolved = SsrfValidator::resolveValidated($url);
        } catch (\Throwable $e) {
            Log::warning('[phpClaw] RemoteToolActivator: '.$e->getMessage());

            return null;
        }

        $json = self::fetchCached($url, $resolved);
        if ($json === null) {
            return null;
        }

        $data = json_decode($json, true);
        if (! is_array($data) || ! is_array($data['tools'] ?? null)) {
            Log::warning("[phpClaw] RemoteToolActivator: invalid profile at {$url}");

            return null;
        }

        return [
            'profile' => (string) ($data['profile'] ?? 'remote'),
            'tools' => array_values(array_filter($data['tools'], 'is_string')),
            'max_tools_per_turn' => max(0, (int) ($data['max_tools_per_turn'] ?? 0)),
        ];
    }

    /**
     * Keep only the tools whose name appears in the allowed list.
     *
     * @param  ToolInterface[]  $allTools  Locally-installed tools.
     * @param  string[]  $allowed  Tool names to keep; empty means no restriction.
     * @return ToolInterface[] Filtered tools.
     */
    public static function filter(array $allTools, array $allowed): array
    {
        if (empty($allowed)) {
            return $allTools;
        }

        return array_values(array_filter(
            $allTools,
            static fn (ToolInterface $t): bool => in_array($t->name(), $allowed, true),
        ));
    }

    /**
     * Build the cURL option array for a pinned HTTPS fetch. Extracted as a public static method so tests can assert individual options (including CURLOPT_RESOLVE and CURLOPT_URL) without executing a real network request.
     *
     * @param  string  $url  Request URL: kept as the hostname URL to preserve Host header and SNI.
     * @param  array{host: string, port: int, ips: list<string>}  $resolved  Validated address set from SsrfValidator::resolveValidated().
     * @return array<int, mixed>
     */
    public static function buildCurlOptions(string $url, array $resolved): array
    {
        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_FAILONERROR => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'phpClaw-RemoteToolActivator/1.0',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ];

        $pin = SsrfValidator::pinEntry($resolved);
        if ($pin !== null) {
            $opts[CURLOPT_RESOLVE] = [$pin];
        }

        return $opts;
    }

    /**
     * Fetch the profile JSON, serving from a 1h file cache when fresh.
     *
     * @param  string  $url  HTTPS URL to fetch.
     * @param  array{host: string, port: int, ips: list<string>}  $resolved  Validated address set for IP pinning.
     * @return string|null Body, or null on fetch failure or oversize.
     */
    private static function fetchCached(string $url, array $resolved): ?string
    {
        $dir = self::cacheDir();
        $cache = $dir.'/'.md5($url).'.json';
        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (is_file($cache) && (time() - (int) filemtime($cache)) < self::CACHE_TTL) {
            $hit = file_get_contents($cache);

            return $hit === false ? null : $hit;
        }

        $raw = self::httpGet($url, $resolved);
        if ($raw === null || strlen($raw) > self::MAX_BYTES) {
            Log::warning("[phpClaw] RemoteToolActivator: fetch failed or >64KB: {$url}");

            return null;
        }

        if (@file_put_contents($cache, $raw) !== false) {
            @chmod($cache, 0600);
        }

        return $raw;
    }

    /**
     * Execute a pinned cURL GET and return the raw response body, or null on any failure.
     *
     * @param  string  $url  HTTPS URL to request.
     * @param  array{host: string, port: int, ips: list<string>}  $resolved  Validated address set for IP pinning.
     * @return string|null Response body string, or null when curl_exec fails (including FAILONERROR on 4xx/5xx).
     */
    private static function httpGet(string $url, array $resolved): ?string
    {
        $ch = curl_init();
        curl_setopt_array($ch, self::buildCurlOptions($url, $resolved));
        $raw = curl_exec($ch);
        curl_close($ch);

        if ($raw === false) {
            return null;
        }

        return is_string($raw) ? $raw : null;
    }

    /**
     * Resolve the cache directory: PHPCLAW_CACHE_DIR override, else /tmp.
     *
     * @return string Absolute cache directory path for tool profiles.
     */
    private static function cacheDir(): string
    {
        $base = EnvVars::get(EnvVars::PHPCLAW_CACHE_DIR, '/tmp');

        return rtrim($base, '/').'/'.self::CACHE_SUBDIR;
    }
}

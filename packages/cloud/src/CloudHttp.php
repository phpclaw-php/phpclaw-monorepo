<?php

declare(strict_types=1);

namespace PhpClaw\Cloud;

/**
 * Internal HTTP utility for cloud API communication.
 */
final class CloudHttp
{
    public const API_VERSION = 'v1';

    public const DEFAULT_BASE_URL = 'https://phpclaw.ai/platform/api';

    public const CLIENT_VERSION = '1.0';

    public const GET_CONNECT_TIMEOUT_S = 2;

    public const GET_TIMEOUT_S = 4;

    public const POST_CONNECT_TIMEOUT_S = 2;

    public const POST_TIMEOUT_S = 4;

    public const FIRE_CONNECT_TIMEOUT_MS = 200;

    public const FIRE_TIMEOUT_MS = 500;

    private const FIRE_PUMP_CEILING_S = 0.05;

    private const FIRE_PUMP_SELECT_S = 0.002;

    private const HTTP_OK = 200;

    private const CONTENT_TYPE_JSON = 'Content-Type: application/json';

    private const AUTHORIZATION_BEARER_PREFIX = 'Authorization: Bearer ';

    private const VERSION_HEADER_PREFIX = 'X-PhpClaw-Version: ';

    private const JSON_ENCODE_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE;

    private static ?\CurlMultiHandle $multiHandle = null;

    private static array $pendingFires = [];

    private static bool $shutdownRegistered = false;

    /**
     * Return the phpClaw Cloud API base URL. Fixed in code so no environment value can redirect
     * the trace stream away from the billing endpoint.
     *
     * @return string Absolute base URL for cloud API calls.
     */
    public static function baseUrl(): string
    {
        return self::DEFAULT_BASE_URL;
    }

    /**
     * Authenticated GET, returning the decoded response body or null on any failure.
     *
     * @param  string  $url  Absolute URL to GET.
     * @param  string  $key  Bearer token sent in the Authorization header.
     * @param  int  $connectTimeout  Connect timeout in seconds.
     * @param  int  $timeout  Total response timeout in seconds.
     * @return array<string, mixed>|null Decoded JSON response on success, null on any failure.
     */
    public static function get(
        string $url,
        string $key,
        int $connectTimeout = self::GET_CONNECT_TIMEOUT_S,
        int $timeout = self::GET_TIMEOUT_S,
    ): ?array {
        $ch = self::initHandle($url);

        if ($ch === null) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => self::authHeaders($key),
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        return self::execute($ch);
    }

    /**
     * Authenticated POST, returning the decoded response body or null on any failure.
     *
     * @param  string  $url  Absolute URL to POST to.
     * @param  string  $key  Bearer token sent in the Authorization header.
     * @param  array<string, mixed>  $body  Request body, JSON-encoded automatically.
     * @param  int  $connectTimeout  Connect timeout in seconds.
     * @param  int  $timeout  Total response timeout in seconds.
     * @return array<string, mixed>|null Decoded JSON response on success, null on any failure.
     */
    public static function post(
        string $url,
        string $key,
        array $body,
        int $connectTimeout = self::POST_CONNECT_TIMEOUT_S,
        int $timeout = self::POST_TIMEOUT_S,
    ): ?array {
        $encoded = self::encode($body);

        if ($encoded === null) {
            return null;
        }

        $ch = self::initHandle($url);

        if ($ch === null) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $encoded,
            CURLOPT_HTTPHEADER => self::jsonAuthHeaders($key),
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        return self::execute($ch);
    }

    /**
     * Fire-and-forget POST with short millisecond-level timeouts, return value discarded.
     *
     * @param  string  $url  Absolute URL to POST to.
     * @param  string  $key  Bearer token sent in the Authorization header.
     * @param  array<string, mixed>  $body  Request body, JSON-encoded automatically.
     * @param  int  $connectTimeoutMs  Connect timeout in milliseconds.
     * @param  int  $timeoutMs  Total response timeout in milliseconds.
     * @return void
     */
    public static function fire(
        string $url,
        string $key,
        array $body,
        int $connectTimeoutMs = self::FIRE_CONNECT_TIMEOUT_MS,
        int $timeoutMs = self::FIRE_TIMEOUT_MS,
    ): void {
        $encoded = self::encode($body);

        if ($encoded === null) {
            return;
        }

        $ch = self::initHandle($url);

        if ($ch === null) {
            return;
        }

        curl_setopt_array($ch, [
            CURLOPT_WRITEFUNCTION => static fn (\CurlHandle $ch, string $data): int => strlen($data),
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $encoded,
            CURLOPT_HTTPHEADER => self::jsonAuthHeaders($key),
            CURLOPT_CONNECTTIMEOUT_MS => $connectTimeoutMs,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        if (self::$multiHandle === null) {
            self::$multiHandle = curl_multi_init();
        }

        if (! self::$shutdownRegistered) {
            register_shutdown_function(static fn () => self::flushFires());
            self::$shutdownRegistered = true;
        }

        curl_multi_add_handle(self::$multiHandle, $ch);
        self::$pendingFires[] = $ch;

        self::pumpFires();
    }

    /**
     * Reset all static fire() state. Required between tests.
     *
     * @return void
     */
    public static function reset(): void
    {
        if (self::$multiHandle !== null) {
            foreach (self::$pendingFires as $ch) {
                curl_multi_remove_handle(self::$multiHandle, $ch);
            }
            curl_multi_close(self::$multiHandle);
            self::$multiHandle = null;
        }

        self::$pendingFires = [];
        self::$shutdownRegistered = false;
    }

    /**
     * Pump the multi-handle until all pending sends are flushed or FIRE_PUMP_CEILING_S elapses.
     *
     * @return void
     */
    private static function pumpFires(): void
    {
        self::drainMultiHandle(microtime(true) + self::FIRE_PUMP_CEILING_S);
    }

    /**
     * Drain all in-flight fire() sends to completion, then release resources.
     *
     * @return void
     */
    private static function flushFires(): void
    {
        if (self::$multiHandle === null || empty(self::$pendingFires)) {
            return;
        }

        self::drainMultiHandle();

        foreach (self::$pendingFires as $ch) {
            curl_multi_remove_handle(self::$multiHandle, $ch);
        }

        curl_multi_close(self::$multiHandle);
        self::$multiHandle = null;
        self::$pendingFires = [];
    }

    /**
     * Drive the curl_multi event loop until all in-flight transfers complete or $deadline passes.
     *
     * @param  float  $deadline  Absolute microtime(true) ceiling. INF = drain fully with no cap.
     * @return void
     */
    private static function drainMultiHandle(float $deadline = INF): void
    {
        if (self::$multiHandle === null) {
            return;
        }

        $remaining = null;
        do {
            $status = curl_multi_exec(self::$multiHandle, $remaining);
            if ($remaining > 0 && $status === CURLM_OK) {
                curl_multi_select(self::$multiHandle, self::FIRE_PUMP_SELECT_S);
            }
        } while ($remaining > 0 && $status === CURLM_OK && microtime(true) < $deadline);
    }

    /**
     * Initialise a cURL handle, returning null on failure (caller treats null as a no-op).
     *
     * @param  string  $url  Absolute URL to initialise the handle for.
     * @return \CurlHandle|null Initialised handle, or null when curl_init() failed.
     */
    private static function initHandle(string $url): ?\CurlHandle
    {
        if (! self::isTransportSecure($url)) {
            return null;
        }

        $ch = curl_init($url);

        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        return $ch;
    }

    /**
     * Return true when the URL uses https, or plain http only against a loopback receiver.
     *
     * @param  string  $url  Absolute request URL.
     * @return bool Whether secrets may be transmitted over this URL.
     */
    private static function isTransportSecure(string $url): bool
    {
        if (str_starts_with($url, 'https://')) {
            return true;
        }

        $host = (string) parse_url($url, PHP_URL_HOST);

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    /**
     * Execute a cURL handle and return decoded JSON body, or null on failure.
     *
     * @param  \CurlHandle  $ch  Configured cURL handle ready to execute.
     * @return array<string, mixed>|null Decoded JSON response on success, null on any failure.
     */
    private static function execute(\CurlHandle $ch): ?array
    {
        $response = curl_exec($ch);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($response === false || $curlError !== '' || $httpStatus !== self::HTTP_OK) {
            return null;
        }

        try {
            $data = json_decode((string) $response, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return $data;
    }

    /**
     * Authorization + client-version headers (no body).
     *
     * @param  string  $key  Bearer token to embed in the Authorization header.
     * @return list<string> Header strings ready for CURLOPT_HTTPHEADER.
     */
    private static function authHeaders(string $key): array
    {
        return [
            self::AUTHORIZATION_BEARER_PREFIX.$key,
            self::VERSION_HEADER_PREFIX.self::CLIENT_VERSION,
        ];
    }

    /**
     * Authorization + client-version headers prefixed with Content-Type: application/json.
     *
     * @param  string  $key  Bearer token to embed in the Authorization header.
     * @return list<string> Header strings ready for CURLOPT_HTTPHEADER.
     */
    private static function jsonAuthHeaders(string $key): array
    {
        return [self::CONTENT_TYPE_JSON, ...self::authHeaders($key)];
    }

    /**
     * JSON-encode a body array. Returns null on encoding failure.
     *
     * @param  array<string, mixed>  $body  Body to JSON-encode.
     * @return string|null Encoded JSON string, or null when encoding throws.
     */
    private static function encode(array $body): ?string
    {
        try {
            return json_encode($body, self::JSON_ENCODE_FLAGS);
        } catch (\JsonException) {
            return null;
        }
    }
}

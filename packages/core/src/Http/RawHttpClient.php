<?php

declare(strict_types=1);

namespace PhpClaw\Http;

use PhpClaw\Config\EnvVars;
use PhpClaw\Exceptions\ProviderException;

/**
 * Minimal cURL-based HTTP client with zero external dependencies; non-final so provider tests can mock it (PHPUnit createMock() requires a non-final class).
 */
class RawHttpClient
{
    private const DEFAULT_TIMEOUT_SECONDS = 120;

    private const DEFAULT_CONNECT_TIMEOUT_SECONDS = 10;

    private const CONTENT_TYPE_HEADER = 'Content-Type: application/json';

    private const HTTP_SUCCESS_MIN = 200;

    private const HTTP_SUCCESS_MAX = 300;

    private const JSON_DECODE_DEPTH = 512;

    private const SSE_LINE_TERMINATOR = "\n";

    private const SSE_LINE_TRIM_CHARS = "\r";

    private const HEADER_INJECTION_CHARS = ["\r", "\n"];

    private readonly int $timeout;

    private readonly int $connectTimeout;

    /**
     * Create a new RawHttpClient instance.
     *
     * @param  int|null  $timeout  Response timeout in seconds. Null = env PHPCLAW_HTTP_TIMEOUT or default 120.
     * @param  int|null  $connectTimeout  Connect timeout in seconds. Null = env PHPCLAW_HTTP_CONNECT_TIMEOUT or default 10.
     * @return void
     */
    public function __construct(?int $timeout = null, ?int $connectTimeout = null)
    {
        $this->timeout = $timeout ?? self::resolveEnvInt(EnvVars::PHPCLAW_HTTP_TIMEOUT, self::DEFAULT_TIMEOUT_SECONDS);
        $this->connectTimeout = $connectTimeout ?? self::resolveEnvInt(EnvVars::PHPCLAW_HTTP_CONNECT_TIMEOUT, self::DEFAULT_CONNECT_TIMEOUT_SECONDS);
    }

    /**
     * Resolved response timeout in seconds.
     *
     * @return int
     */
    public function timeout(): int
    {
        return $this->timeout;
    }

    /**
     * Resolved connect timeout in seconds.
     *
     * @return int
     */
    public function connectTimeout(): int
    {
        return $this->connectTimeout;
    }

    /**
     * Send a JSON POST request and return the decoded response body.
     *
     * @param  string  $url  The endpoint URL.
     * @param  array<string, string>  $headers  Request headers (CRLF-stripped for injection safety).
     * @param  array<string, mixed>  $body  Request body: JSON-encoded automatically.
     * @return array<string, mixed>
     *
     * @throws ProviderException On cURL error, malformed JSON, or non-2xx HTTP status.
     */
    public function post(string $url, array $headers, array $body): array
    {
        $ch = $this->prepareRequest($url, $headers, $body);

        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true]);

        $response = curl_exec($ch);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($response === false || $curlError !== '') {
            throw new ProviderException("cURL error: {$curlError}");
        }

        if (! is_string($response)) {
            throw new ProviderException('Unexpected cURL response type.');
        }

        try {
            $decoded = json_decode($response, true, self::JSON_DECODE_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ProviderException("Provider returned malformed JSON: {$e->getMessage()}");
        }

        if ($httpStatus < self::HTTP_SUCCESS_MIN || $httpStatus >= self::HTTP_SUCCESS_MAX) {
            $errorMessage = $this->extractErrorMessage($decoded, $httpStatus);
            throw new ProviderException("Provider returned HTTP {$httpStatus}: {$errorMessage}");
        }

        return $decoded;
    }

    /**
     * Send a streaming POST request, calling $onChunk for each raw SSE line received.
     *
     * @param  string  $url  The endpoint URL.
     * @param  array<string, string>  $headers  Request headers (CRLF-stripped for injection safety).
     * @param  array<string, mixed>  $body  Request body: JSON-encoded automatically.
     * @param  callable(string): void  $onChunk  Called with each raw line from the stream.
     * @return void
     *
     * @throws ProviderException On cURL error.
     */
    public function stream(string $url, array $headers, array $body, callable $onChunk): void
    {
        $ch = $this->prepareRequest($url, $headers, $body);
        $buffer = '';

        curl_setopt_array($ch, [
            CURLOPT_WRITEFUNCTION => function ($ch, string $data) use ($onChunk, &$buffer): int {
                $buffer .= $data;
                self::drainBufferedLines($buffer, $onChunk);

                return strlen($data);
            },
        ]);

        $result = curl_exec($ch);
        $curlError = curl_error($ch);

        if ($result === false || $curlError !== '') {
            throw new ProviderException("cURL stream error: {$curlError}");
        }

        if ($buffer !== '') {
            $onChunk($buffer);
        }
    }

    /**
     * Initialise the cURL handle, encode the body, and apply the shared option set.
     *
     * @param  string  $url  Endpoint URL.
     * @param  array<string, string>  $headers  Request headers.
     * @param  array<string, mixed>  $body  Request body to JSON-encode.
     * @return \CurlHandle A configured cURL handle ready for curl_exec.
     *
     * @throws ProviderException When cURL fails to initialise.
     */
    private function prepareRequest(string $url, array $headers, array $body): \CurlHandle
    {
        set_time_limit(0);

        $ch = curl_init($url);

        if ($ch === false) {
            throw new ProviderException("Failed to initialise cURL for URL: {$url}");
        }

        $encodedBody = json_encode($body, JSON_THROW_ON_ERROR);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $encodedBody,
            CURLOPT_HTTPHEADER => self::buildCurlHeaders($headers),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        return $ch;
    }

    /**
     * Build the cURL HTTPHEADER array: prepend Content-Type: application/json and CRLF-strip headers to prevent header injection.
     *
     * @param  array<string, string>  $headers  Caller-supplied headers.
     * @return list<string> cURL-formatted "Name: value" strings.
     */
    private static function buildCurlHeaders(array $headers): array
    {
        $curlHeaders = [self::CONTENT_TYPE_HEADER];

        foreach ($headers as $name => $value) {
            $safeName = str_replace(self::HEADER_INJECTION_CHARS, '', $name);
            $safeValue = str_replace(self::HEADER_INJECTION_CHARS, '', $value);
            $curlHeaders[] = "{$safeName}: {$safeValue}";
        }

        return $curlHeaders;
    }

    /**
     * Pull every complete line out of the SSE buffer and hand it to the chunk callback.
     *
     * @param  string  $buffer  Accumulating SSE buffer, mutated in place.
     * @param  callable(string): void  $onChunk  Receives each complete line.
     * @return void
     */
    private static function drainBufferedLines(string &$buffer, callable $onChunk): void
    {
        while (($pos = strpos($buffer, self::SSE_LINE_TERMINATOR)) !== false) {
            $line = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 1);
            $line = rtrim($line, self::SSE_LINE_TRIM_CHARS);

            if ($line !== '') {
                $onChunk($line);
            }
        }
    }

    /**
     * Read a positive integer from an environment variable, falling back to default if missing or invalid.
     *
     * @param  string  $name  Environment variable name.
     * @param  int  $default  Returned when the env var is missing or non-positive.
     * @return int
     */
    private static function resolveEnvInt(string $name, int $default): int
    {
        $v = getenv($name);

        return ($v !== false && is_numeric($v) && (int) $v > 0) ? (int) $v : $default;
    }

    /**
     * Extract a readable error message from a decoded API error response.
     *
     * @param  array<string, mixed>  $decoded  Decoded JSON error body.
     * @param  int  $httpStatus  HTTP status code from the response.
     * @return string
     */
    private function extractErrorMessage(array $decoded, int $httpStatus): string
    {
        if (isset($decoded['error']['message'])) {
            return (string) $decoded['error']['message'];
        }

        if (isset($decoded['error']) && is_string($decoded['error'])) {
            return $decoded['error'];
        }

        if (isset($decoded['error']['status'])) {
            return (string) ($decoded['error']['status'] ?? $httpStatus);
        }

        return "HTTP {$httpStatus}";
    }
}

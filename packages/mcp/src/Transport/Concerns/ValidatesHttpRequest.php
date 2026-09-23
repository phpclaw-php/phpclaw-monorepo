<?php

declare(strict_types=1);

namespace PhpClaw\Mcp\Transport\Concerns;

use PhpClaw\Mcp\Exceptions\McpException;
use PhpClaw\Mcp\McpSecurity;

/**
 * Shared single-request validation for the HTTP-family MCP transports.
 */
trait ValidatesHttpRequest
{
    private bool $open = true;

    private bool $requestRead = false;

    /**
     * Return true until the single HTTP request has been consumed.
     *
     * @return bool Whether another read() call may be issued.
     */
    public function isOpen(): bool
    {
        return $this->open && ! $this->requestRead;
    }

    /**
     * Maximum accepted request body size in bytes (1 MiB).
     *
     * @return int Byte ceiling applied to the request body.
     */
    private function maxBodyBytes(): int
    {
        return 1048576;
    }

    /**
     * Mark the single request as consumed, refusing a second read.
     *
     * @return void
     *
     * @throws McpException When read() is called more than once (-32700).
     */
    private function beginRequest(): void
    {
        if ($this->requestRead) {
            $this->open = false;
            throw new McpException('HTTP transport: request already consumed', McpException::PARSE_ERROR);
        }

        $this->requestRead = true;
    }

    /**
     * Reject non-loopback callers and cross-origin browser requests.
     *
     * @return void
     *
     * @throws McpException On a remote address or any Origin header (-32000).
     */
    private function assertLocalOrigin(): void
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($remoteAddr !== '127.0.0.1' && $remoteAddr !== '::1') {
            http_response_code(403);
            throw new McpException('Forbidden: only localhost connections accepted', McpException::TRANSPORT_ERROR);
        }

        if (($_SERVER['HTTP_ORIGIN'] ?? '') !== '') {
            http_response_code(403);
            throw new McpException('Forbidden: cross-origin browser requests are not allowed', McpException::TRANSPORT_ERROR);
        }
    }

    /**
     * Verify the bearer token and apply the per-token rate limit.
     *
     * @return void
     *
     * @throws McpException On a bad token or an exceeded rate limit (-32000).
     */
    private function assertAuthorised(): void
    {
        $provided = $this->extractBearerToken() ?? '';
        if (! McpSecurity::validateToken($provided, $this->bearerToken)) {
            http_response_code(401);
            throw new McpException('Unauthorized', McpException::TRANSPORT_ERROR);
        }

        if (! McpSecurity::rateLimit(hash('sha256', $this->bearerToken))) {
            http_response_code(429);
            throw new McpException('Too Many Requests', McpException::TRANSPORT_ERROR);
        }
    }

    /**
     * The current request method, defaulting to GET when the SAPI does not supply one.
     *
     * @return string Upper-case HTTP method name.
     */
    private function requestMethod(): string
    {
        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }

    /**
     * Reject any method other than POST.
     *
     * @param  string  $method  The request method to check.
     * @return void
     *
     * @throws McpException When the method is not POST (-32600).
     */
    private function assertPostMethod(string $method): void
    {
        if ($method !== 'POST') {
            http_response_code(405);
            throw new McpException('Method Not Allowed: expected POST', McpException::INVALID_REQUEST);
        }
    }

    /**
     * Read the request body, rejecting an empty or over-large payload.
     *
     * @return string Raw JSON-RPC request body.
     *
     * @throws McpException On an empty body (-32700) or one above the byte ceiling (-32600).
     */
    private function readBody(): string
    {
        $body = file_get_contents('php://input', false, null, 0, $this->maxBodyBytes() + 1);
        if ($body === false || $body === '') {
            http_response_code(400);
            throw new McpException('Empty request body', McpException::PARSE_ERROR);
        }

        if (strlen($body) > $this->maxBodyBytes()) {
            http_response_code(413);
            throw new McpException('Request body too large', McpException::INVALID_REQUEST);
        }

        return $body;
    }

    /**
     * Extract the bearer token from the Authorization header, or return null if absent.
     *
     * @return string|null The token string without the "Bearer " prefix, or null when absent.
     */
    private function extractBearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }

        return null;
    }
}

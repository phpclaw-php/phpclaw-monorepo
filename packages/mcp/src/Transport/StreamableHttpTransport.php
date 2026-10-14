<?php

declare(strict_types=1);

namespace PhpClaw\Mcp\Transport;

use PhpClaw\Mcp\Contracts\TransportInterface;
use PhpClaw\Mcp\Exceptions\McpException;
use PhpClaw\Mcp\McpRequest;
use PhpClaw\Mcp\McpResponse;
use PhpClaw\Mcp\McpSecurity;

/**
 * Streamable HTTP transport for MCP (replaces deprecated SSE).
 */
final class StreamableHttpTransport implements TransportInterface
{
    private const MAX_BODY_BYTES = 1048576;

    private const LOOPBACK_ADDRESSES = ['127.0.0.1', '::1'];

    private const BEARER_PREFIX = 'Bearer ';

    private const TOKEN_HASH_ALGO = 'sha256';

    private readonly string $bearerToken;

    private bool $open = true;

    private bool $requestRead = false;

    private ?string $sessionId = null;

    /**
     * Construct the streamable HTTP transport with a required expected bearer token.
     *
     * @param  string  $bearerToken  Expected bearer token; the transport refuses to start when empty.
     * @return void
     *
     * @throws McpException When the bearer token is empty (fail closed).
     */
    public function __construct(string $bearerToken)
    {
        $this->bearerToken = $bearerToken;

        if (trim($this->bearerToken) === '') {
            throw new McpException('MCP HTTP transport refused to start: PHPCLAW_MCP_TOKEN is not set.', McpException::TRANSPORT_ERROR);
        }
    }

    /**
     * Read and validate an HTTP POST (or DELETE) request from the current PHP request context.
     *
     * @return McpRequest The parsed request.
     *
     * @throws McpException On non-localhost origin or failed auth (-32000), wrong method (-32600), or empty/invalid body (-32700).
     */
    public function read(): McpRequest
    {
        $this->beginRequest();
        $this->assertLocalOrigin();
        $this->assertAuthorised();

        $method = $this->requestMethod();

        if ($method === 'DELETE') {
            $this->open = false;
            http_response_code(204);
            throw new McpException('Session terminated', McpException::PARSE_ERROR);
        }

        $this->assertPostMethod($method);

        return McpRequest::fromJson($this->readBody());
    }

    /**
     * Write the JSON-RPC response with session-id header.
     *
     * @param  McpResponse  $response  The response to write.
     * @return void
     */
    public function write(McpResponse $response): void
    {
        if ($this->sessionId === null) {
            $this->sessionId = bin2hex(random_bytes(16));
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Mcp-Session-Id: '.$this->sessionId);
        echo $response->toJson();
    }

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
        if (! in_array($_SERVER['REMOTE_ADDR'] ?? '', self::LOOPBACK_ADDRESSES, true)) {
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

        if (! McpSecurity::rateLimit(hash(self::TOKEN_HASH_ALGO, $this->bearerToken))) {
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
        $body = file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);
        if ($body === false || $body === '') {
            http_response_code(400);
            throw new McpException('Empty request body', McpException::PARSE_ERROR);
        }

        if (strlen($body) > self::MAX_BODY_BYTES) {
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
        if (! str_starts_with($header, self::BEARER_PREFIX)) {
            return null;
        }

        return substr($header, strlen(self::BEARER_PREFIX));
    }
}

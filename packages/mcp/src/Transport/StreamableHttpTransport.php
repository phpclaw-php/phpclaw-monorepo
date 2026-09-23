<?php

declare(strict_types=1);

namespace PhpClaw\Mcp\Transport;

use PhpClaw\Mcp\Contracts\TransportInterface;
use PhpClaw\Mcp\Exceptions\McpException;
use PhpClaw\Mcp\McpRequest;
use PhpClaw\Mcp\McpResponse;
use PhpClaw\Mcp\Transport\Concerns\ValidatesHttpRequest;

/**
 * Streamable HTTP transport for MCP (replaces deprecated SSE).
 */
final class StreamableHttpTransport implements TransportInterface
{
    use ValidatesHttpRequest;

    private ?string $sessionId = null;

    /**
     * Construct the streamable HTTP transport with a required expected bearer token.
     *
     * @param  string  $bearerToken  Expected bearer token; the transport refuses to start when empty.
     * @return void
     *
     * @throws McpException When the bearer token is empty (fail closed).
     */
    public function __construct(
        private readonly string $bearerToken,
    ) {
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
}

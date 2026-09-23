<?php

declare(strict_types=1);

namespace PhpClaw\Mcp\Exceptions;

use PhpClaw\Exceptions\PhpClawException;

/**
 * Thrown when a JSON-RPC 2.0 protocol error occurs.
 */
final class McpException extends PhpClawException
{
    public const PARSE_ERROR = -32700;

    public const INVALID_REQUEST = -32600;

    public const METHOD_NOT_FOUND = -32601;

    public const INTERNAL_ERROR = -32603;

    public const TRANSPORT_ERROR = -32000;

    /**
     * Construct a new MCP protocol exception.
     *
     * @param  string  $message  Human-readable error description.
     * @param  int  $rpcCode  JSON-RPC 2.0 error code (-32700, -32600, -32601, -32000, etc.).
     * @return void
     */
    public function __construct(
        string $message,
        public readonly int $rpcCode,
    ) {
        parent::__construct($message, $rpcCode);
    }
}

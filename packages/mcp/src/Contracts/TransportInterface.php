<?php

declare(strict_types=1);

namespace PhpClaw\Mcp\Contracts;

use PhpClaw\Mcp\Exceptions\McpException;
use PhpClaw\Mcp\McpRequest;
use PhpClaw\Mcp\McpResponse;

/**
 * Contract for MCP transport implementations.
 */
interface TransportInterface
{
    /**
     * Block until the next JSON-RPC message arrives and return it as an McpRequest.
     *
     * @return McpRequest The parsed request object.
     *
     * @throws McpException If the incoming data cannot be parsed (-32700 / -32600).
     */
    public function read(): McpRequest;

    /**
     * Write a response back to the client.
     *
     * @param  McpResponse  $response  The response to write to the transport channel.
     * @return void
     */
    public function write(McpResponse $response): void;

    /**
     * Return true while the transport channel is open and readable.
     *
     * @return bool Whether the transport is still readable.
     */
    public function isOpen(): bool;
}

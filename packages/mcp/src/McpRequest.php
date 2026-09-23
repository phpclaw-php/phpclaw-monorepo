<?php

declare(strict_types=1);

namespace PhpClaw\Mcp;

use PhpClaw\Mcp\Exceptions\McpException;

/**
 * Immutable JSON-RPC 2.0 request value object.
 */
final class McpRequest
{
    /**
     * Build an immutable JSON-RPC request value object.
     *
     * @param  string  $method  JSON-RPC method name.
     * @param  mixed  $id  Request id (string, int, or null for notifications).
     * @param  array<string, mixed>  $params  Decoded params object.
     * @param  bool  $isNotification  True when the original payload had no id field.
     * @return void
     */
    private function __construct(
        public readonly string $method,
        public readonly mixed $id,
        public readonly array $params,
        public readonly bool $isNotification,
    ) {}

    /**
     * Parse a raw JSON string into an McpRequest.
     *
     * @param  string  $json  Raw JSON-RPC payload (single message).
     * @return self The parsed request value object.
     *
     * @throws McpException On parse error (-32700) or invalid structure (-32600).
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new McpException('Parse error: invalid JSON', McpException::PARSE_ERROR);
        }

        return self::fromArray($data);
    }

    /**
     * Build from a pre-decoded array (useful in tests).
     *
     * @param  array<string, mixed>  $data  Decoded JSON-RPC message payload.
     * @return self The parsed request value object.
     *
     * @throws McpException If required fields are missing (-32600).
     */
    public static function fromArray(array $data): self
    {
        if (($data['jsonrpc'] ?? '') !== '2.0') {
            throw new McpException('Invalid Request: jsonrpc must be "2.0"', McpException::INVALID_REQUEST);
        }

        if (! isset($data['method']) || ! is_string($data['method']) || $data['method'] === '') {
            throw new McpException('Invalid Request: method is required', McpException::INVALID_REQUEST);
        }

        $id = $data['id'] ?? null;
        $isNotification = ! array_key_exists('id', $data);
        $params = is_array($data['params'] ?? null) ? $data['params'] : [];

        return new self(
            method: $data['method'],
            id: $id,
            params: $params,
            isNotification: $isNotification,
        );
    }

    /**
     * Get a named parameter, returning null if absent.
     *
     * @param  string  $key  Parameter name.
     * @return mixed The parameter value, or null when not present.
     */
    public function getParam(string $key): mixed
    {
        return $this->params[$key] ?? null;
    }
}

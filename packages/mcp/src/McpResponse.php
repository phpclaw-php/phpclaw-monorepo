<?php

declare(strict_types=1);

namespace PhpClaw\Mcp;

/**
 * Immutable JSON-RPC 2.0 response value object.
 */
final class McpResponse
{
    /**
     * Build an immutable JSON-RPC response value object.
     *
     * @param  mixed  $id  JSON-RPC request id (string, int, or null).
     * @param  mixed  $result  Result payload (null for error responses).
     * @param  array{code:int,message:string}|null  $error  Error envelope (null for success responses).
     * @return void
     */
    private function __construct(
        public readonly mixed $id,
        public readonly mixed $result,
        public readonly ?array $error,
    ) {}

    /**
     * Build a successful response.
     *
     * @param  mixed  $id  JSON-RPC request id (string, int, or null).
     * @param  mixed  $result  The result payload.
     * @return self
     */
    public static function success(mixed $id, mixed $result): self
    {
        return new self(id: $id, result: $result, error: null);
    }

    /**
     * Build an error response.
     *
     * @param  mixed  $id  JSON-RPC request id (null for transport-level errors).
     * @param  int  $code  JSON-RPC error code (e.g. -32600, -32601, -32603).
     * @param  string  $message  Human-readable error description.
     * @return self
     */
    public static function error(mixed $id, int $code, string $message): self
    {
        return new self(
            id: $id,
            result: null,
            error: ['code' => $code, 'message' => $message],
        );
    }

    /**
     * Return the JSON-RPC 2.0 array structure.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = ['jsonrpc' => '2.0', 'id' => $this->id];

        if ($this->error !== null) {
            $payload['error'] = $this->error;
        } else {
            $payload['result'] = $this->result;
        }

        return $payload;
    }

    /**
     * Serialize to a JSON string (single line, newline-terminated for stdio).
     *
     * @return string Newline-terminated JSON-RPC 2.0 message.
     *
     * @throws \JsonException When the payload cannot be encoded.
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }
}

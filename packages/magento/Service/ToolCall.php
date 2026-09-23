<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Service;

/**
 * Immutable value object for a single tool invocation captured during an agent turn.
 */
final class ToolCall
{
    /**
     * Bind the tool name, input and result captured for one invocation.
     *
     * @param  string  $toolName  Registered tool name; empty string when the hook payload had none.
     * @param  array<string, mixed>  $toolInput  Decoded tool input arguments.
     * @param  string  $toolResult  Tool execution result rendered as a string.
     * @return void
     */
    public function __construct(
        public readonly string $toolName,
        public readonly array $toolInput,
        public readonly string $toolResult,
    ) {}

    /**
     * Build a ToolCall from a ToolAfter lifecycle hook context array.
     *
     * @param  array<string, mixed>  $ctx  Hook context with optional tool_name, tool_input, tool_result keys.
     * @return self
     */
    public static function fromContext(array $ctx): self
    {
        return new self(
            (string) ($ctx['tool_name'] ?? ''),
            (array) ($ctx['tool_input'] ?? []),
            (string) ($ctx['tool_result'] ?? ''),
        );
    }

    /**
     * Whether this call carries a non-empty tool name.
     *
     * @return bool
     */
    public function isNamed(): bool
    {
        return $this->toolName !== '';
    }

    /**
     * Client/response shape returned in the chat JSON payload and SSE frames.
     *
     * @return array{tool_name: string, tool_input: array<string, mixed>, tool_result: string}
     */
    public function toArray(): array
    {
        return [
            'tool_name' => $this->toolName,
            'tool_input' => $this->toolInput,
            'tool_result' => $this->toolResult,
        ];
    }

    /**
     * Conversation-history shape spliced into the stored message list.
     *
     * @return array{role: string, content: string, tool_name: string, tool_input: array<string, mixed>}
     */
    public function toHistoryEntry(): array
    {
        return [
            'role' => 'tool',
            'content' => $this->toolResult,
            'tool_name' => $this->toolName,
            'tool_input' => $this->toolInput,
        ];
    }
}

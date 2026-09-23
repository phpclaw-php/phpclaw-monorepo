<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Service;

/**
 * Immutable value object for a single tool invocation captured during an agent turn.
 */
final class ToolCall
{
    /**
     * Construct a tool call record from its name, input, and result.
     *
     * @param  string  $toolName  Registered tool name.
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
     * Build a tool call record from a ToolAfter hook context array.
     *
     * @param  array<string, mixed>  $ctx  ToolAfter hook context.
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
     * Check whether this tool call has a non-empty tool name.
     *
     * @return bool
     */
    public function isNamed(): bool
    {
        return $this->toolName !== '';
    }

    /**
     * Serialise this tool call to its flat array representation.
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
     * Serialise this tool call as a conversation history entry.
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

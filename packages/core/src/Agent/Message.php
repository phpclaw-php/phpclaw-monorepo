<?php

declare(strict_types=1);

namespace PhpClaw\Agent;

/**
 * Immutable value object representing a single message in the conversation history.
 */
final class Message implements \Stringable
{
    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public const ROLE_TOOL = 'tool';

    public const ROLE_TOOL_BATCH = 'tool_batch';

    public const ROLE_SYSTEM = 'system';

    private const TO_STRING_TRUNCATE_AT = 200;

    /**
     * Construct a Message directly. Prefer the named factories (user(), assistant(), tool(), etc.) over this constructor.
     *
     * @param  string  $role  One of the ROLE_* constants.
     * @param  string  $content  Message text: empty string for tool_use / tool_batch.
     * @param  string|null  $toolName  Tool name (single tool_use / tool_result only).
     * @param  array<string, mixed>|null  $toolInput  Tool input (single tool_use / tool_result only).
     * @param  string|null  $toolUseId  Unique ID pairing tool_use ↔ tool_result.
     * @param  array<int, array<string, mixed>>|null  $batchCalls  tool_batch only, descriptors of every tool call in the batch.
     * @param  array<string, string>|null  $batchResults  tool_batch only: keyed by tool_use_id → result string.
     * @return void
     *
     * @throws \InvalidArgumentException When $role is not one of the ROLE_* constants.
     */
    public function __construct(
        public readonly string $role,
        public readonly string $content,
        public readonly ?string $toolName = null,
        public readonly ?array $toolInput = null,
        public readonly ?string $toolUseId = null,
        public readonly ?array $batchCalls = null,
        public readonly ?array $batchResults = null,
    ) {
        if (! in_array($role, [
            self::ROLE_USER,
            self::ROLE_ASSISTANT,
            self::ROLE_TOOL,
            self::ROLE_TOOL_BATCH,
            self::ROLE_SYSTEM,
        ], true)) {
            throw new \InvalidArgumentException(
                "Unknown message role '{$role}'. Expected one of: user, assistant, tool, tool_batch, system."
            );
        }
    }

    /**
     * Create a user message.
     *
     * @param  string  $content  Plain text user input.
     * @return self Message with role=user.
     */
    public static function user(string $content): self
    {
        return new self(role: self::ROLE_USER, content: $content);
    }

    /**
     * Create an assistant text response message.
     *
     * @param  string  $content  Plain text assistant reply.
     * @return self Message with role=assistant.
     */
    public static function assistant(string $content): self
    {
        return new self(role: self::ROLE_ASSISTANT, content: $content);
    }

    /**
     * Create a system message.
     *
     * @param  string  $content  System-prompt content.
     * @return self Message with role=system.
     */
    public static function system(string $content): self
    {
        return new self(role: self::ROLE_SYSTEM, content: $content);
    }

    /**
     * Create a tool-result message: shorthand for toolResult() without input.
     *
     * @param  string  $toolUseId  Unique ID matching the originating tool_use request.
     * @param  string  $toolName  Tool that produced this result.
     * @param  string  $result  Tool output text.
     * @return self Message with role=tool.
     */
    public static function tool(string $toolUseId, string $toolName, string $result): self
    {
        return self::toolResult($toolUseId, $toolName, $result);
    }

    /**
     * Create a tool result message (appended after tool execution).
     *
     * @param  string  $toolUseId  Unique ID matching the originating tool_use request.
     * @param  string  $toolName  Tool that produced this result.
     * @param  string  $result  Tool output text.
     * @param  array<string, mixed>  $toolInput  Input that was passed to the tool.
     * @return self Message with role=tool, carrying both input and result.
     */
    public static function toolResult(
        string $toolUseId,
        string $toolName,
        string $result,
        array $toolInput = [],
    ): self {
        return new self(
            role: self::ROLE_TOOL,
            content: $result,
            toolName: $toolName,
            toolInput: $toolInput,
            toolUseId: $toolUseId,
        );
    }

    /**
     * Create a placeholder message representing the assistant's tool_use request.
     *
     * @param  string  $toolUseId  Unique ID later paired with the matching tool result.
     * @param  string  $toolName  Tool the assistant wants to call.
     * @param  array<string, mixed>  $toolInput  Arguments for the tool call.
     * @return self Message with role=assistant carrying a tool_use payload.
     */
    public static function toolUse(
        string $toolUseId,
        string $toolName,
        array $toolInput,
    ): self {
        return new self(
            role: self::ROLE_ASSISTANT,
            content: '',
            toolName: $toolName,
            toolInput: $toolInput,
            toolUseId: $toolUseId,
        );
    }

    /**
     * Create a batch message carrying multiple tool calls and their results.
     *
     * @param  array<int, array<string, mixed>>  $calls  All tool call descriptors.
     * @param  array<string, string>  $results  Keyed by tool_use_id → result string.
     * @return self Message with role=tool_batch carrying both calls and results.
     */
    public static function toolBatch(array $calls, array $results): self
    {
        return new self(
            role: self::ROLE_TOOL_BATCH,
            content: '',
            batchCalls: $calls,
            batchResults: $results,
        );
    }

    /**
     * Reconstruct a Message from its serialized array form.
     *
     * @param  array<string, mixed>  $data  Required: 'role', 'content'. Optional: tool_name, tool_input, tool_use_id, batch_calls, batch_results.
     * @return self Reconstructed Message instance.
     *
     * @throws \InvalidArgumentException When 'role' is missing or invalid.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            role: (string) ($data['role'] ?? ''),
            content: (string) ($data['content'] ?? ''),
            toolName: isset($data['tool_name']) ? (string) $data['tool_name'] : null,
            toolInput: isset($data['tool_input']) ? (array) $data['tool_input'] : null,
            toolUseId: isset($data['tool_use_id']) ? (string) $data['tool_use_id'] : null,
            batchCalls: isset($data['batch_calls']) ? (array) $data['batch_calls'] : null,
            batchResults: isset($data['batch_results']) ? (array) $data['batch_results'] : null,
        );
    }

    /**
     * Serialize to a plain array: exact inverse of fromArray().
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'role' => $this->role,
            'content' => $this->content,
        ];

        if ($this->toolName !== null) {
            $data['tool_name'] = $this->toolName;
        }
        if ($this->toolInput !== null) {
            $data['tool_input'] = $this->toolInput;
        }
        if ($this->toolUseId !== null) {
            $data['tool_use_id'] = $this->toolUseId;
        }
        if ($this->batchCalls !== null) {
            $data['batch_calls'] = $this->batchCalls;
        }
        if ($this->batchResults !== null) {
            $data['batch_results'] = $this->batchResults;
        }

        return $data;
    }

    /**
     * Whether this message carries a batch of tool calls + results.
     *
     * @return bool True when role is ROLE_TOOL_BATCH.
     */
    public function isBatchToolUse(): bool
    {
        return $this->role === self::ROLE_TOOL_BATCH;
    }

    /**
     * Whether this message carries a tool_use request (not a text response).
     *
     * @return bool True when role is assistant and both toolName + toolInput are set.
     */
    public function isToolUse(): bool
    {
        return $this->toolName !== null && $this->toolInput !== null && $this->role === self::ROLE_ASSISTANT;
    }

    /**
     * Whether this message is a tool result.
     *
     * @return bool True when role is ROLE_TOOL.
     */
    public function isToolResult(): bool
    {
        return $this->role === self::ROLE_TOOL;
    }

    /**
     * Return a new Message with the given toolInput, preserving all other fields.
     *
     * @param  array<string, mixed>  $toolInput  Replacement tool input map.
     * @return self New Message instance with updated toolInput.
     */
    public function withToolInput(array $toolInput): self
    {
        return new self(
            role: $this->role,
            content: $this->content,
            toolName: $this->toolName,
            toolInput: $toolInput,
            toolUseId: $this->toolUseId,
            batchCalls: $this->batchCalls,
            batchResults: $this->batchResults,
        );
    }

    /**
     * Concise single-line representation for logs.
     *
     * @return string Single-line "[label] body" representation suitable for logs.
     */
    public function __toString(): string
    {
        $label = match (true) {
            $this->role === self::ROLE_TOOL && $this->toolName !== null => "tool:{$this->toolName}",
            $this->role === self::ROLE_ASSISTANT && $this->isToolUse() => "assistant:tool_use:{$this->toolName}",
            $this->role === self::ROLE_TOOL_BATCH => 'tool_batch',
            default => $this->role,
        };

        $body = $this->role === self::ROLE_TOOL_BATCH
            ? '('.count($this->batchCalls ?? []).' calls)'
            : $this->content;

        if (strlen($body) > self::TO_STRING_TRUNCATE_AT) {
            $body = substr($body, 0, self::TO_STRING_TRUNCATE_AT).'...';
        }

        $body = str_replace(["\r\n", "\r", "\n"], ' ', $body);

        return "[{$label}] {$body}";
    }
}

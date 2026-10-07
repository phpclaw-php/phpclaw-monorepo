<?php

declare(strict_types=1);

namespace PhpClaw\Agent;

/**
 * What a durable run was asked to do: its id, its message, and the run and conversation it belongs to.
 */
final class RunTask
{
    /**
     * Build a run task.
     *
     * @param  string  $runId  Run id, also the storage key.
     * @param  string  $message  Message the loop runs on (memory and skill context already added).
     * @param  string  $routingMessage  Original user message, used to route tools on resume.
     * @param  string|null  $parentRunId  Run that started this one, carried for graph and multi-agent work.
     * @param  string|null  $conversationId  Conversation the finished turn is appended to; null for a send() run.
     * @return void
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $message,
        public readonly string $routingMessage,
        public readonly ?string $parentRunId = null,
        public readonly ?string $conversationId = null,
    ) {}

    /**
     * Serialise to plain data.
     *
     * @return array{run_id: string, message: string, routing_message: string, parent_run_id: string|null, conversation_id: string|null}
     */
    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'message' => $this->message,
            'routing_message' => $this->routingMessage,
            'parent_run_id' => $this->parentRunId,
            'conversation_id' => $this->conversationId,
        ];
    }

    /**
     * Rebuild from toArray() output.
     *
     * @param  array<string, mixed>  $data  Serialised task.
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['run_id'] ?? ''),
            (string) ($data['message'] ?? ''),
            (string) ($data['routing_message'] ?? ''),
            isset($data['parent_run_id']) ? (string) $data['parent_run_id'] : null,
            isset($data['conversation_id']) ? (string) $data['conversation_id'] : null,
        );
    }
}

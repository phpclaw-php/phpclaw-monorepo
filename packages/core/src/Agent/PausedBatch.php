<?php

declare(strict_types=1);

namespace PhpClaw\Agent;

/**
 * The tool batch a durable run paused in, with the call waiting for a decision.
 */
final class PausedBatch
{
    public const APPROVED = 'approved';

    public const DENIED = 'denied';

    /**
     * Build a paused batch.
     *
     * @param  array<int, array<string, mixed>>  $calls  Every call of the batch, in order.
     * @param  array<string, string>  $results  Results of the calls that already ran, by tool_use id.
     * @param  int  $index  Position of the call waiting for a decision.
     * @param  \DateTimeImmutable  $requestedAt  When the run paused.
     * @param  string|null  $decision  APPROVED, DENIED, or null while undecided.
     * @return void
     */
    public function __construct(
        public readonly array $calls,
        public readonly array $results,
        public readonly int $index,
        public readonly \DateTimeImmutable $requestedAt,
        public readonly ?string $decision = null,
    ) {}

    /**
     * A batch paused now on the call at $index.
     *
     * @param  array<int, array<string, mixed>>  $calls  Every call of the batch, in order.
     * @param  array<string, string>  $results  Results of the calls that already ran, by tool_use id.
     * @param  int  $index  Position of the call waiting for a decision.
     * @return self
     */
    public static function now(array $calls, array $results, int $index): self
    {
        return new self($calls, $results, $index, new \DateTimeImmutable('@'.time()));
    }

    /**
     * Provider tool_use id of the call waiting for a decision.
     *
     * @return string
     */
    public function callId(): string
    {
        return (string) ($this->calls[$this->index]['tool_use_id'] ?? '');
    }

    /**
     * Tool the waiting call names.
     *
     * @return string
     */
    public function toolName(): string
    {
        return (string) ($this->calls[$this->index]['tool_name'] ?? '');
    }

    /**
     * Input the model supplied for the waiting call.
     *
     * @return array<string, mixed>
     */
    public function input(): array
    {
        return (array) ($this->calls[$this->index]['tool_input'] ?? []);
    }

    /**
     * Copy with a decision recorded.
     *
     * @param  string  $decision  APPROVED or DENIED.
     * @return self
     */
    public function withDecision(string $decision): self
    {
        return new self($this->calls, $this->results, $this->index, $this->requestedAt, $decision);
    }

    /**
     * Serialise to plain data.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'calls' => $this->calls,
            'results' => $this->results,
            'index' => $this->index,
            'requested_at' => $this->requestedAt->format(\DateTimeInterface::ATOM),
            'decision' => $this->decision,
        ];
    }

    /**
     * Rebuild from toArray() output.
     *
     * @param  array<string, mixed>  $data  Serialised batch.
     * @return self
     *
     * @throws \Exception When the stored date cannot be parsed.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            calls: (array) ($data['calls'] ?? []),
            results: (array) ($data['results'] ?? []),
            index: (int) ($data['index'] ?? 0),
            requestedAt: new \DateTimeImmutable((string) ($data['requested_at'] ?? 'now')),
            decision: isset($data['decision']) ? (string) $data['decision'] : null,
        );
    }
}

<?php

declare(strict_types=1);

namespace PhpClaw\Agent;

/**
 * Where a durable run's loop stands: the history, the steps and tokens spent, and what its tools have done.
 */
final class RunProgress
{
    /**
     * Build a progress record.
     *
     * @param  Message[]  $messages  History so far.
     * @param  int  $iteration  Iterations whose provider call completed.
     * @param  int  $tokensSpent  Input and output tokens spent, carried so a resume cannot reset the budget.
     * @param  bool  $isHallucinationRetry  Whether the one retry without tools is already spent.
     * @param  RunToolLog  $tools  What the run's tools have done.
     * @return void
     */
    public function __construct(
        public readonly array $messages,
        public readonly int $iteration = 0,
        public readonly int $tokensSpent = 0,
        public readonly bool $isHallucinationRetry = false,
        public readonly RunToolLog $tools = new RunToolLog,
    ) {}

    /**
     * Serialise to plain data.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'messages' => array_map(static fn (Message $message): array => $message->toArray(), $this->messages),
            'iteration' => $this->iteration,
            'tokens_spent' => $this->tokensSpent,
            'hallucination_retry' => $this->isHallucinationRetry,
            'tools' => $this->tools->toArray(),
        ];
    }

    /**
     * Rebuild from toArray() output.
     *
     * @param  array<string, mixed>  $data  Serialised progress.
     * @return self
     *
     * @throws \InvalidArgumentException When a stored message has no valid role.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            messages: array_map(static fn (mixed $message): Message => Message::fromArray((array) $message), array_values((array) ($data['messages'] ?? []))),
            iteration: (int) ($data['iteration'] ?? 0),
            tokensSpent: (int) ($data['tokens_spent'] ?? 0),
            isHallucinationRetry: (bool) ($data['hallucination_retry'] ?? false),
            tools: RunToolLog::fromArray((array) ($data['tools'] ?? [])),
        );
    }
}

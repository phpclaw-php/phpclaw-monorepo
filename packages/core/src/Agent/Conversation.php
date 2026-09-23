<?php

declare(strict_types=1);

namespace PhpClaw\Agent;

use PhpClaw\Exceptions\MemoryException;
use PhpClaw\Support\Ulid;

/**
 * Immutable value object representing a multi-turn conversation session.
 */
final class Conversation
{
    public const MEMORY_NAMESPACE = 'conversations';

    /**
     * Build a Conversation value object from its parts.
     *
     * @param  string  $id  Stable identifier (typically a ULID) used to key memory storage.
     * @param  Message[]  $history  Ordered list of messages composing the conversation.
     * @param  \DateTimeImmutable  $createdAt  Conversation start timestamp.
     * @param  array<string, mixed>  $metadata  Application-defined key-value pairs (never persisted by default).
     * @return void
     *
     * @throws \InvalidArgumentException When $history contains non-Message entries.
     */
    public function __construct(
        public readonly string $id,
        public readonly array $history,
        public readonly \DateTimeImmutable $createdAt,
        public readonly array $metadata = [],
    ) {
        foreach ($history as $i => $entry) {
            if (! $entry instanceof Message) {
                throw new \InvalidArgumentException(
                    "Conversation history[{$i}] must be a Message instance, got ".get_debug_type($entry).'.'
                );
            }
        }
    }

    /**
     * Start a brand-new conversation with a generated ULID and current timestamp.
     *
     * @param  array<string, mixed>  $metadata  Application-defined key-value pairs.
     * @return self Empty Conversation seeded with a fresh ULID and now() timestamp.
     */
    public static function start(array $metadata = []): self
    {
        return new self(
            id: Ulid::generate(),
            history: [],
            createdAt: new \DateTimeImmutable,
            metadata: $metadata,
        );
    }

    /**
     * Restore a conversation from its serialized array representation.
     *
     * @param  array<string, mixed>  $data  Serialized data from toArray().
     * @return self Reconstructed Conversation with hydrated history.
     *
     * @throws MemoryException When required keys (id, history, created_at) are missing.
     */
    public static function fromArray(array $data): self
    {
        if (! isset($data['id'], $data['history'], $data['created_at'])) {
            throw new MemoryException(
                'Conversation::fromArray() requires id, history, and created_at keys.'
            );
        }

        $history = array_map(
            static fn (array $msg) => Message::fromArray($msg),
            (array) $data['history'],
        );

        return new self(
            id: (string) $data['id'],
            history: $history,
            createdAt: new \DateTimeImmutable((string) $data['created_at']),
            metadata: (array) ($data['metadata'] ?? []),
        );
    }

    /**
     * Return a new Conversation with the given message appended to history.
     *
     * @param  Message  $message  Message to append; original Conversation is not modified.
     * @return self New Conversation instance with the appended history.
     */
    public function withMessage(Message $message): self
    {
        return new self(
            id: $this->id,
            history: [...$this->history, $message],
            createdAt: $this->createdAt,
            metadata: $this->metadata,
        );
    }

    /**
     * Return a new Conversation with additional metadata merged in.
     *
     * @param  array<string, mixed>  $metadata  Metadata array shallow-merged over existing metadata (existing keys win on collision).
     * @return self New Conversation instance with merged metadata.
     */
    public function withMetadata(array $metadata): self
    {
        return new self(
            id: $this->id,
            history: $this->history,
            createdAt: $this->createdAt,
            metadata: array_merge($this->metadata, $metadata),
        );
    }

    /**
     * Return the most recent message in the conversation, or null if empty.
     *
     * @return Message|null The last message, or null when history is empty.
     */
    public function lastMessage(): ?Message
    {
        return empty($this->history) ? null : $this->history[array_key_last($this->history)];
    }

    /**
     * Return all messages with the given role.
     *
     * @param  string  $role  Message role to filter by (e.g. 'user', 'assistant', 'system', 'tool').
     * @return Message[] Re-indexed list of matching messages in original order.
     */
    public function messagesByRole(string $role): array
    {
        return array_values(array_filter(
            $this->history,
            fn (Message $m) => $m->role === $role,
        ));
    }

    /**
     * Return the total number of messages in the conversation history.
     *
     * @return int Count of messages currently in history.
     */
    public function messageCount(): int
    {
        return count($this->history);
    }

    /**
     * Return true when the conversation has no messages.
     *
     * @return bool True when history is empty.
     */
    public function isEmpty(): bool
    {
        return empty($this->history);
    }

    /**
     * Serialize to a plain array suitable for JSON storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'created_at' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'metadata' => $this->metadata,
            'history' => array_map(
                static fn (Message $m): array => $m->toArray(),
                $this->history,
            ),
        ];
    }
}

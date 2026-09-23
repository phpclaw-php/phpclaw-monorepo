<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Testing;

use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PHPUnit\Framework\Assert;

/**
 * Test double for the agent engine: records every message sent and returns queued responses
 * without reaching a provider.
 */
final class ClawFake implements ClawInterface
{
    public const DEFAULT_TEXT = 'Fake response.';

    private const PROVIDER = 'fake';

    private const MODEL = 'fake-model';

    private array $sent = [];

    private array $queue;

    /**
     * Bind the queued response texts and default this fake returns in order.
     *
     * @param  array<int, string>  $responses  Texts returned in order; the default text is used once exhausted.
     * @param  ?MemoryInterface  $memory  Memory driver reported by memory().
     * @param  bool  $storeMessages  Value reported by storeMessages().
     * @return void
     */
    public function __construct(
        array $responses = [],
        private readonly ?MemoryInterface $memory = null,
        private readonly bool $storeMessages = true,
    ) {
        $this->queue = array_values($responses);
    }

    /**
     * Record the message and return the next queued response.
     *
     * @param  string  $message  The user message to send.
     * @return AgentResponse
     */
    public function send(string $message): AgentResponse
    {
        $this->sent[] = $message;

        return $this->response($this->nextText());
    }

    /**
     * Record the message, emit the whole response through $onToken, and return it.
     *
     * @param  string  $message  The user message to send.
     * @param  callable(string): void  $onToken  Called with the response text.
     * @return AgentResponse
     */
    public function stream(string $message, callable $onToken): AgentResponse
    {
        $this->sent[] = $message;
        $text = $this->nextText();

        $onToken($text);

        return $this->response($text);
    }

    /**
     * Return a new empty conversation, or one carrying the given id.
     *
     * @param  string  $id  ULID of an existing conversation, or empty to start new.
     * @param  array<string, mixed>  $metadata  Application metadata.
     * @return Conversation
     */
    public function conversation(string $id = '', array $metadata = []): Conversation
    {
        if ($id === '') {
            return Conversation::start($metadata);
        }

        return new Conversation(
            id: $id,
            history: [],
            createdAt: new \DateTimeImmutable,
            metadata: $metadata,
        );
    }

    /**
     * Record the message and return a turn wrapping the next queued response.
     *
     * @param  Conversation  $conversation  The conversation to continue.
     * @param  string  $message  The user message to send.
     * @return ConversationTurn
     */
    public function sendInConversation(Conversation $conversation, string $message): ConversationTurn
    {
        $this->sent[] = $message;

        return new ConversationTurn($this->response($this->nextText()), $conversation);
    }

    /**
     * Record the message, emit the response through $onToken, and return a turn.
     *
     * @param  Conversation  $conversation  The conversation to continue.
     * @param  string  $message  The user message to send.
     * @param  callable(string): void  $onToken  Called with the response text.
     * @param  ?callable  $beforePersist  Accepted for contract parity; never invoked.
     * @return ConversationTurn
     */
    public function streamInConversation(
        Conversation $conversation,
        string $message,
        callable $onToken,
        ?callable $beforePersist = null,
    ): ConversationTurn {
        $this->sent[] = $message;
        $text = $this->nextText();

        $onToken($text);

        return new ConversationTurn($this->response($text), $conversation);
    }

    /**
     * Return the memory driver supplied to the fake, if any.
     *
     * @return ?MemoryInterface
     */
    public function memory(): ?MemoryInterface
    {
        return $this->memory;
    }

    /**
     * Whether full message content is reported as persisted.
     *
     * @return bool
     */
    public function storeMessages(): bool
    {
        return $this->storeMessages;
    }

    /**
     * Every message passed to the fake, in order.
     *
     * @return array<int, string>
     */
    public function sentMessages(): array
    {
        return $this->sent;
    }

    /**
     * Assert a matching message was sent.
     *
     * @param  string|callable(string): bool  $message  Exact message, or a predicate.
     * @return void
     */
    public function assertSent(string|callable $message): void
    {
        Assert::assertTrue(
            $this->matches($message) > 0,
            'The expected message was not sent to phpClaw.',
        );
    }

    /**
     * Assert no matching message was sent.
     *
     * @param  string|callable(string): bool  $message  Exact message, or a predicate.
     * @return void
     */
    public function assertNotSent(string|callable $message): void
    {
        Assert::assertSame(
            0,
            $this->matches($message),
            'An unexpected message was sent to phpClaw.',
        );
    }

    /**
     * Assert nothing was sent at all.
     *
     * @return void
     */
    public function assertNothingSent(): void
    {
        Assert::assertSame(
            [],
            $this->sent,
            'Expected no messages sent to phpClaw, but some were.',
        );
    }

    /**
     * Assert exactly $count messages were sent.
     *
     * @param  int  $count  Expected number of messages.
     * @return void
     */
    public function assertSentCount(int $count): void
    {
        Assert::assertCount(
            $count,
            $this->sent,
            "Expected {$count} messages sent to phpClaw.",
        );
    }

    /**
     * Count recorded messages matching an exact string or a predicate.
     *
     * @param  string|callable(string): bool  $message  Exact message, or a predicate.
     * @return int
     */
    private function matches(string|callable $message): int
    {
        $predicate = is_string($message)
            ? static fn (string $sent): bool => $sent === $message
            : $message;

        return count(array_filter($this->sent, $predicate));
    }

    /**
     * Pop the next queued response text, falling back to the default.
     *
     * @return string
     */
    private function nextText(): string
    {
        return array_shift($this->queue) ?? self::DEFAULT_TEXT;
    }

    /**
     * Build a deterministic AgentResponse around the given text.
     *
     * @param  string  $text  Response text.
     * @return AgentResponse
     */
    private function response(string $text): AgentResponse
    {
        return new AgentResponse(
            text: $text,
            provider: self::PROVIDER,
            model: self::MODEL,
            iterations: 1,
        );
    }
}

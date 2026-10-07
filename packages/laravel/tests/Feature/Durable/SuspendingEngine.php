<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Feature\Durable;

use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Agent\RunStatus;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Exceptions\RunSuspendedException;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Support\Ulid;

final class SuspendingEngine implements ClawInterface
{
    public const RUN_ID = '01JFAKERUN000000000000000000';

    public function send(string $message): AgentResponse
    {
        throw new RunSuspendedException(self::RUN_ID, RunStatus::Suspended);
    }

    public function stream(string $message, callable $onToken): AgentResponse
    {
        return $this->send($message);
    }

    public function conversation(string $id = '', array $metadata = []): Conversation
    {
        return new Conversation(id: $id ?: Ulid::generate(), history: [], createdAt: new \DateTimeImmutable, metadata: $metadata);
    }

    public function sendInConversation(Conversation $conversation, string $message): ConversationTurn
    {
        $this->send($message);
    }

    public function streamInConversation(Conversation $conversation, string $message, callable $onToken, ?callable $beforePersist = null): ConversationTurn
    {
        $this->send($message);
    }

    public function memory(): ?MemoryInterface
    {
        return null;
    }

    public function storeMessages(): bool
    {
        return true;
    }
}

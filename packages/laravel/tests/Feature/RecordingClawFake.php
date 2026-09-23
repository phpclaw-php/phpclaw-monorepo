<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Feature;

use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Laravel\Testing\ClawFake;
use PhpClaw\Memory\Contracts\MemoryInterface;

final class RecordingClawFake implements ClawInterface
{
    private ClawFake $inner;

    private ?\Closure $onSend;

    public function __construct(callable $onSend)
    {
        $this->inner = new ClawFake(['ok']);
        $this->onSend = $onSend;
    }

    public function send(string $message): AgentResponse
    {
        ($this->onSend)();

        return $this->inner->send($message);
    }

    public function stream(string $message, callable $onToken): AgentResponse
    {
        return $this->inner->stream($message, $onToken);
    }

    public function conversation(string $id = '', array $metadata = []): Conversation
    {
        return $this->inner->conversation($id, $metadata);
    }

    public function sendInConversation(Conversation $conversation, string $message): ConversationTurn
    {
        return $this->inner->sendInConversation($conversation, $message);
    }

    public function streamInConversation(
        Conversation $conversation,
        string $message,
        callable $onToken,
        ?callable $beforePersist = null,
    ): ConversationTurn {
        return $this->inner->streamInConversation($conversation, $message, $onToken, $beforePersist);
    }

    public function memory(): ?MemoryInterface
    {
        return $this->inner->memory();
    }

    public function storeMessages(): bool
    {
        return $this->inner->storeMessages();
    }
}

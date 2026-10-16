<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Feature;

use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use PhpClaw\Memory\Contracts\MemoryInterface;

final class ToolCallingClawFake implements ClawInterface
{
    public ?array $persisted = null;

    public function __construct(private readonly string $conversationId = '01TOOLCALLINGCONVERSATIONA') {}

    public function send(string $message): AgentResponse
    {
        return $this->response();
    }

    public function stream(string $message, callable $onToken): AgentResponse
    {
        return $this->response();
    }

    public function conversation(string $id = '', array $metadata = []): Conversation
    {
        return new Conversation($this->conversationId, [], new \DateTimeImmutable, $metadata);
    }

    public function sendInConversation(Conversation $conversation, string $message): ConversationTurn
    {
        return new ConversationTurn($this->response(), $conversation);
    }

    public function streamInConversation(Conversation $conversation, string $message, callable $onToken, ?callable $beforePersist = null): ConversationTurn
    {
        HookRegistry::fire(LifecycleEvent::ToolAfter->value, ['tool_name' => 'db_query', 'tool_input' => ['query' => 'select 1'], 'tool_result' => '{"rows":[1]}']);
        $onToken('Done.');

        if ($beforePersist !== null) {
            $this->persisted = $beforePersist(['history' => [['role' => 'user', 'content' => $message], ['role' => 'assistant', 'content' => 'Done.']]]);
        }

        return new ConversationTurn($this->response(), $conversation);
    }

    public function memory(): ?MemoryInterface
    {
        return null;
    }

    public function storeMessages(): bool
    {
        return true;
    }

    public function storedRoles(): array
    {
        return array_column($this->persisted['history'] ?? [], 'role');
    }

    private function response(): AgentResponse
    {
        return new AgentResponse(text: 'Done.', provider: 'ollama', model: 'qwen2.5:7b', iterations: 2, toolsCalled: ['db_query']);
    }
}

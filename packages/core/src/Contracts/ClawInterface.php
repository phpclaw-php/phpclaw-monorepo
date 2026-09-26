<?php

declare(strict_types=1);

namespace PhpClaw\Contracts;

use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Memory\Contracts\MemoryInterface;

/**
 * Public API contract for the phpClaw agent engine, type-hint this, never the final Claw class.
 */
interface ClawInterface
{
    /**
     * Send a single message and return the agent response.
     *
     * @param  string  $message  The user message to send.
     * @return AgentResponse The terminal text response produced by the agent loop.
     *
     * @throws GuardException When a registered guard blocks the message.
     * @throws MaxIterationsException When the ReAct loop cap is hit.
     * @throws ProviderException When the LLM API call fails after all retries.
     * @throws ToolException When the model calls an unregistered tool after the no-tools retry.
     */
    public function send(string $message): AgentResponse;

    /**
     * Stream a single message, calling $onToken for each token received.
     *
     * @param  string  $message  The user message to send.
     * @param  callable(string): void  $onToken  Called with each text token as it arrives.
     * @return AgentResponse The terminal text response once streaming completes.
     *
     * @throws GuardException When a registered guard blocks the message.
     * @throws MaxIterationsException When the ReAct loop cap is hit.
     * @throws ProviderException When the LLM API call fails after all retries.
     * @throws ToolException When the model calls an unregistered tool after the no-tools retry.
     */
    public function stream(string $message, callable $onToken): AgentResponse;

    /**
     * Create or resume a named conversation.
     *
     * @param  string  $id  ULID of an existing conversation, or empty to start new.
     * @param  array<string, mixed>  $metadata  Application metadata attached to new conversations.
     * @return Conversation Loaded conversation when $id is found in memory, otherwise a new empty one.
     */
    public function conversation(string $id = '', array $metadata = []): Conversation;

    /**
     * Send a message within an existing conversation.
     *
     * @param  Conversation  $conversation  The conversation to continue.
     * @param  string  $message  The user message to send.
     * @return ConversationTurn The agent response paired with the updated conversation.
     *
     * @throws GuardException When a registered guard blocks the message.
     * @throws MaxIterationsException When the ReAct loop cap is hit.
     * @throws ProviderException When the LLM API call fails after all retries.
     * @throws ToolException When the model calls an unregistered tool after the no-tools retry.
     */
    public function sendInConversation(Conversation $conversation, string $message): ConversationTurn;

    /**
     * Stream a message within an existing conversation, runs the full ReAct tool loop, then streams the final assistant text through $onToken.
     *
     * @param  Conversation  $conversation  The conversation to continue.
     * @param  string  $message  The user message to send.
     * @param  callable(string): void  $onToken  Called with each text chunk as it arrives.
     * @param  (callable(array<string,mixed>): (array<string,mixed>|mixed))|null  $beforePersist  Optional. Mutate the conversation payload before it's saved + before conversation.end fires. Return a modified array to persist it instead; return non-array to keep the original.
     * @return ConversationTurn The agent response paired with the updated conversation.
     *
     * @throws GuardException When a registered guard blocks the message.
     * @throws MaxIterationsException When the ReAct loop cap is hit.
     * @throws ProviderException When the LLM API call fails after all retries.
     * @throws ToolException When the model calls an unregistered tool after the no-tools retry.
     */
    public function streamInConversation(
        Conversation $conversation,
        string $message,
        callable $onToken,
        ?callable $beforePersist = null,
    ): ConversationTurn;

    /**
     * Return the configured memory driver, or null if none is set.
     *
     * @return ?MemoryInterface The memory driver, or null when running stateless.
     */
    public function memory(): ?MemoryInterface;

    /**
     * Whether full message content is persisted (store_messages config).
     *
     * @return bool True when message content should be persisted to memory and cloud payloads.
     */
    public function storeMessages(): bool;
}

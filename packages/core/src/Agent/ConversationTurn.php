<?php

declare(strict_types=1);

namespace PhpClaw\Agent;

/**
 * Value object returned by PhpClaw::sendInConversation(): pairs the response with the updated Conversation.
 */
final class ConversationTurn
{
    /**
     * Bundle a single agent response with the updated conversation it produced.
     *
     * @param  AgentResponse  $response  The agent's response for this turn.
     * @param  Conversation  $conversation  The conversation with this turn's user + assistant messages appended (immutable).
     * @return void
     */
    public function __construct(
        public readonly AgentResponse $response,
        public readonly Conversation $conversation,
    ) {}
}

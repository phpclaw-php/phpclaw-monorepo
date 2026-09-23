<?php

declare(strict_types=1);

namespace PhpClaw\Hooks\Dispatchers;

use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\HookRunContext;
use PhpClaw\Hooks\LifecycleEvent;

/**
 * Typed dispatchers for conversation lifecycle events.
 *
 * @internal
 */
final class ConversationEventDispatcher
{
    /**
     * Fires when a brand-new conversation is created via PhpClaw::conversation().
     *
     * @param  string  $conversationId  Conversation identifier, if any.
     * @param  array<string, mixed>  $metadata  Metadata.
     * @return void
     */
    public static function start(string $conversationId, array $metadata = []): void
    {
        HookRegistry::fire(LifecycleEvent::ConversationStart->value, [
            'conversation_id' => $conversationId,
            'metadata' => $metadata,
            'run_id' => HookRunContext::currentRunId(),
        ]);
    }

    /**
     * Fires after each turn in PhpClaw::sendInConversation() completes successfully.
     *
     * @param  string  $conversationId  Conversation identifier, if any.
     * @param  int  $turnCount  Number of turns in the conversation.
     * @param  string  $message  User message.
     * @param  string  $response  Final response text.
     * @param  int  $durationMs  Duration in milliseconds.
     * @return void
     */
    public static function end(
        string $conversationId,
        int $turnCount,
        string $message,
        string $response,
        int $durationMs,
    ): void {
        HookRegistry::fire(LifecycleEvent::ConversationEnd->value, [
            'conversation_id' => $conversationId,
            'turn_count' => $turnCount,
            'message' => $message,
            'response' => $response,
            'duration_ms' => $durationMs,
            'run_id' => HookRunContext::currentRunId(),
        ]);
    }
}

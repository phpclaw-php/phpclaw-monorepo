<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\CLI\Concerns;

use PhpClaw\Agent\Conversation;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\WordPress\Exceptions\ConversationAccessDeniedException;

/**
 * Opens the conversation a WP-CLI command asked for (`--conv-id`, `--continue`, or a new one) and remembers the last id.
 */
trait TracksLastConversation
{
    /**
     * Open the named conversation, the last one, or a new one, and say so whenever the command cannot resume.
     *
     * @param  callable(string): void  $notice  Prints one notice line.
     * @param  PhpClawInterface  $engine  Agent that owns the conversations.
     * @param  string  $conversationId  Id given with `--conv-id`, or ''.
     * @param  bool  $continue  Whether `--continue` was given; `--conv-id` wins when both are set.
     * @return Conversation
     */
    private function openConversation(callable $notice, PhpClawInterface $engine, string $conversationId, bool $continue): Conversation
    {
        $wanted = $conversationId;

        if ($wanted === '' && $continue) {
            $wanted = $this->lastConversationId();

            if ($wanted === '') {
                $notice('No previous conversation to continue; starting a new one.');
            }
        }

        try {
            $conversation = $engine->conversation($wanted);
        } catch (ConversationAccessDeniedException) {
            $conversation = $engine->conversation();
            $notice("Conversation {$wanted} is not available to the console; started {$conversation->id}.");

            return $conversation;
        }

        if ($wanted !== '' && $conversation->id !== $wanted) {
            $notice("No stored conversation {$wanted}; started {$conversation->id}.");
        }

        return $conversation;
    }

    /**
     * Save the conversation id (never message content) in a site option so the next `--continue` picks it up.
     *
     * @param  string  $conversationId  Id of the conversation the run used.
     * @return void
     */
    private function rememberConversation(string $conversationId): void
    {
        update_option('phpclaw_cli_last_conversation', $conversationId, false);
    }

    /**
     * Read the last saved conversation id, or '' when there is none or the stored value is not an id.
     *
     * @return string
     */
    private function lastConversationId(): string
    {
        $saved = get_option('phpclaw_cli_last_conversation', '');

        return is_string($saved) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $saved) === 1 ? $saved : '';
    }
}

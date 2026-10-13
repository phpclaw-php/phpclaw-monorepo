<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\CLI\Concerns;

use PhpClaw\Agent\Conversation;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\PrestaShop\Exceptions\ConversationAccessDeniedException;

/**
 * Opens the conversation a CLI command asked for (`--conv-id`, `--continue`, or a new one) and remembers the last id.
 */
trait TracksLastConversation
{
    /**
     * Open the named conversation, the last one, or a new one, printing a notice whenever the command cannot resume.
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
     * Save the conversation id (never message content) in the PS configuration store so the next `--continue` picks it up.
     *
     * @param  string  $conversationId  Id of the conversation the run used.
     * @return void
     */
    private function rememberConversation(string $conversationId): void
    {
        if (! class_exists(\Configuration::class)) {
            return;
        }

        \Configuration::updateValue('PHPCLAW_CLI_LAST_CONV', $conversationId);
    }

    /**
     * Read the last saved conversation id from the PS configuration store, or '' when none exists or the value is invalid.
     *
     * @return string
     */
    private function lastConversationId(): string
    {
        if (! class_exists(\Configuration::class)) {
            return '';
        }

        $saved = \Configuration::get('PHPCLAW_CLI_LAST_CONV');

        return is_string($saved) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $saved) === 1 ? $saved : '';
    }
}

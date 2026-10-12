<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Commands\Concerns;

use PhpClaw\Agent\Conversation;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Drupal\DrupalConsole;
use PhpClaw\Drupal\Exceptions\ConversationAccessDeniedException;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Opens the conversation a Drush command asked for (`--conv-id`, `--continue`, or a new one) and remembers the last id.
 *
 * The using command holds `$context`, the agent context whose State API keeps the last id.
 */
trait TracksLastConversation
{
    /**
     * Open the named conversation, the last one, or a new one, and say so whenever the command cannot resume.
     *
     * @param  OutputInterface  $output  Console output for the notices.
     * @param  ClawInterface  $claw  Agent that owns the conversations.
     * @param  string  $conversationId  Id given with `--conv-id`, or ''.
     * @param  bool  $continue  Whether `--continue` was given; `--conv-id` wins when both are set.
     * @return Conversation
     */
    private function openConversation(OutputInterface $output, ClawInterface $claw, string $conversationId, bool $continue): Conversation
    {
        $wanted = $conversationId;

        if ($wanted === '' && $continue) {
            $wanted = $this->lastConversationId();

            if ($wanted === '') {
                $output->writeln('<comment>No previous conversation to continue; starting a new one.</comment>');
            }
        }

        try {
            $conversation = $claw->conversation($wanted);
        } catch (ConversationAccessDeniedException) {
            $conversation = $claw->conversation();
            $output->writeln("<comment>Conversation {$wanted} is not available to the console; started {$conversation->id}.</comment>");

            return $conversation;
        }

        if ($wanted !== '' && $conversation->id !== $wanted) {
            $output->writeln("<comment>No stored conversation {$wanted}; started {$conversation->id}.</comment>");
        }

        return $conversation;
    }

    /**
     * Save the conversation id (never message content) in the site's State API so the next `--continue` picks it up.
     *
     * @param  string  $conversationId  Id of the conversation the run used.
     * @return void
     */
    private function rememberConversation(string $conversationId): void
    {
        $this->context->state->set(DrupalConsole::LAST_CONVERSATION_STATE_KEY, $conversationId);
    }

    /**
     * Read the last saved conversation id, or '' when there is none or the stored value is not an id.
     *
     * @return string
     */
    private function lastConversationId(): string
    {
        $saved = $this->context->state->get(DrupalConsole::LAST_CONVERSATION_STATE_KEY, '');

        return is_string($saved) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $saved) === 1 ? $saved : '';
    }
}

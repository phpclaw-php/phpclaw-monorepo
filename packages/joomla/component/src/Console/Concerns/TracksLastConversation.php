<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Component\Administrator\Console\Concerns;

use PhpClaw\Agent\Conversation;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Joomla\Component\Administrator\Exceptions\ConversationAccessDeniedException;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Opens the conversation a console command asked for and keeps the last one for --continue.
 */
trait TracksLastConversation
{
    /**
     * Open the conversation given by --conv-id or --continue, or a new one, and say why when the id was not used.
     *
     * @param  OutputInterface  $output  Console output for the notice.
     * @param  ClawInterface  $claw  Engine that loads the conversation.
     * @param  string  $conversationId  Value of --conv-id, '' when not given.
     * @param  bool  $continue  Whether --continue was passed.
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
     * Save the conversation id for the next --continue; a folder that cannot be written is skipped.
     *
     * @param  string  $conversationId  Conversation the run used.
     * @return void
     */
    private function rememberConversation(string $conversationId): void
    {
        $directory = $this->lastConversationDirectory();

        if ($directory === '' || (! is_dir($directory) && ! @mkdir($directory, 0755, recursive: true) && ! is_dir($directory))) {
            return;
        }

        @file_put_contents($directory.'/.last-conversation', $conversationId, LOCK_EX);
    }

    /**
     * The saved conversation id, or '' when none is saved or the saved value is not a conversation id.
     *
     * @return string
     */
    private function lastConversationId(): string
    {
        $file = $this->lastConversationDirectory().'/.last-conversation';
        $saved = is_file($file) ? trim((string) @file_get_contents($file)) : '';

        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $saved) === 1 ? $saved : '';
    }

    /**
     * Folder that holds the last conversation id: the given one, or the component's storage folder.
     *
     * @return string
     */
    private function lastConversationDirectory(): string
    {
        if ($this->storageDirectory !== '') {
            return rtrim($this->storageDirectory, '/');
        }

        return defined('JPATH_ADMINISTRATOR') ? JPATH_ADMINISTRATOR.'/components/com_phpclaw/storage' : '';
    }
}

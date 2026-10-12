<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Console\Command\Concerns;

use PhpClaw\Agent\Conversation;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Opens the conversation a console command asked for (`--conv-id`, `--continue`, or a new one) and remembers the last id.
 *
 * The using command holds `$varDirectory`, the Magento var folder; '' means `BP/var`.
 */
trait TracksLastConversation
{
    /**
     * Open the named conversation, the last one, or a new one, and say so whenever the command cannot resume.
     *
     * @param  OutputInterface  $output  Console output for the warnings.
     * @param  PhpClawInterface  $claw  Agent that owns the conversations.
     * @param  string  $conversationId  Id given with `--conv-id`, or ''.
     * @param  bool  $continue  Whether `--continue` was given; `--conv-id` wins when both are set.
     * @return Conversation
     */
    private function openConversation(OutputInterface $output, PhpClawInterface $claw, string $conversationId, bool $continue): Conversation
    {
        $wanted = $conversationId;

        if ($wanted === '' && $continue) {
            $wanted = $this->lastConversationId();

            if ($wanted === '') {
                $output->writeln('<comment>No previous conversation to continue; starting a new one.</comment>');
            }
        }

        $conversation = $claw->conversation($wanted);

        if ($wanted !== '' && $conversation->id !== $wanted) {
            $output->writeln("<comment>No stored conversation {$wanted}; started {$conversation->id}.</comment>");
        }

        return $conversation;
    }

    /**
     * Save the conversation id so the next `--continue` picks it up; a folder or file that cannot be written is skipped.
     *
     * @param  string  $conversationId  Id of the conversation the run used.
     * @return void
     */
    private function rememberConversation(string $conversationId): void
    {
        $file = $this->lastConversationFile();

        if ($file === '' || (! is_dir(dirname($file)) && ! @mkdir(dirname($file), 0755, true) && ! is_dir(dirname($file)))) {
            return;
        }

        @file_put_contents($file, $conversationId, LOCK_EX);
    }

    /**
     * Read the last saved conversation id, or '' when there is none or the file holds anything but an id.
     *
     * @return string
     */
    private function lastConversationId(): string
    {
        $file = $this->lastConversationFile();
        $saved = $file !== '' && is_file($file) ? trim((string) @file_get_contents($file)) : '';

        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $saved) === 1 ? $saved : '';
    }

    /**
     * Path of the file that holds the last conversation id (never message content), or '' when no var folder is known.
     *
     * @return string
     */
    private function lastConversationFile(): string
    {
        $varDirectory = $this->varDirectory !== '' ? $this->varDirectory : (defined('BP') ? BP.'/var' : '');

        return $varDirectory === '' ? '' : rtrim($varDirectory, '/').'/phpclaw/.last-conversation';
    }
}

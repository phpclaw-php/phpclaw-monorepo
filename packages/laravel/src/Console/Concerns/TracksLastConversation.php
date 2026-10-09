<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Console\Concerns;

use Illuminate\Console\Command;
use PhpClaw\Agent\Conversation;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;

/**
 * Opens the conversation a console command asked for (`--conv-id`, `--continue`, or a new one) and remembers the last id.
 *
 * @mixin Command
 */
trait TracksLastConversation
{
    /**
     * Open the named conversation, the last one, or a new one, and say so whenever the command cannot resume.
     *
     * @param  PhpClawInterface  $claw  Agent that owns the conversations.
     * @param  string  $conversationId  Id given with `--conv-id`, or ''.
     * @param  bool  $continue  Whether `--continue` was given; `--conv-id` wins when both are set.
     * @return Conversation
     */
    private function openConversation(PhpClawInterface $claw, string $conversationId, bool $continue): Conversation
    {
        $wanted = $conversationId;

        if ($wanted === '' && $continue) {
            $wanted = $this->lastConversationId();

            if ($wanted === '') {
                $this->warn('No previous conversation to continue; starting a new one.');
            }
        }

        if ($wanted !== '' && (string) config('phpclaw.memory_driver', 'database') === 'array') {
            $this->warn('Resuming needs a stored memory driver (memory_driver is array); starting a new conversation.');
            $wanted = '';
        }

        $conversation = $claw->conversation($wanted);

        if ($wanted !== '' && $conversation->id !== $wanted) {
            $this->warn("No stored conversation {$wanted}; started {$conversation->id}.");
        }

        return $conversation;
    }

    /**
     * Save the conversation id so the next `--continue` picks it up; a file that cannot be written is skipped.
     *
     * @param  string  $conversationId  Id of the conversation the run used.
     * @return void
     */
    private function rememberConversation(string $conversationId): void
    {
        $file = $this->lastConversationFile();

        if (! is_dir(dirname($file)) && ! @mkdir(dirname($file), 0755, true) && ! is_dir(dirname($file))) {
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
        $saved = is_file($file) ? trim((string) @file_get_contents($file)) : '';

        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $saved) === 1 ? $saved : '';
    }

    /**
     * Path of the file that holds the last conversation id, never any message content.
     *
     * @return string
     */
    private function lastConversationFile(): string
    {
        return storage_path('phpclaw/.last-conversation');
    }
}

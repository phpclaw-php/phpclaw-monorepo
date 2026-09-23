<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Memory;

/**
 * Extracts a conversation title from the first user message in the history.
 */
trait ConversationTitleTrait
{
    /**
     * Return the first non-empty user message (up to 80 chars) as a title.
     *
     * @param  array<int, array<string, mixed>>  $history  Conversation history to search.
     * @return ?string
     */
    private static function extractTitle(array $history): ?string
    {
        foreach ($history as $message) {
            if (($message['role'] ?? '') === 'user' && is_string($message['content'] ?? null)) {
                $text = trim((string) $message['content']);
                if ($text !== '') {
                    return mb_substr($text, 0, 80);
                }
            }
        }

        return null;
    }
}

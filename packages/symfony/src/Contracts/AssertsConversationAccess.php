<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Contracts;

use PhpClaw\Symfony\Exceptions\ConversationAccessDeniedException;

/**
 * Answers whether the acting user may reach a conversation, before any response is committed.
 */
interface AssertsConversationAccess
{
    /**
     * Throw when the conversation exists and belongs to a different user.
     *
     * @param  string  $key  Conversation ULID.
     * @param  string  $namespace  Scoping namespace.
     * @return void
     *
     * @throws ConversationAccessDeniedException
     */
    public function assertAccess(string $key, string $namespace = 'conversations'): void;
}

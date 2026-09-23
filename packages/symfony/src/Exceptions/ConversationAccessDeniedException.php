<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Exceptions;

use PhpClaw\Exceptions\PhpClawException;

/**
 * Thrown when a user reaches for a conversation that belongs to another user.
 */
final class ConversationAccessDeniedException extends PhpClawException
{
    /**
     * Create the exception with a fixed message that never reveals whether the id exists.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct('This conversation is unavailable.');
    }
}

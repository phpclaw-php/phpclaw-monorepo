<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Exceptions;

use PhpClaw\Exceptions\PhpClawException;

/**
 * Thrown when a controller surfaces a conversation the acting employee cannot reach.
 */
final class ConversationAccessDeniedException extends PhpClawException
{
    /**
     * Create the exception with a generic message that never reveals whether the id exists.
     */
    public function __construct()
    {
        parent::__construct('This conversation is unavailable.');
    }
}

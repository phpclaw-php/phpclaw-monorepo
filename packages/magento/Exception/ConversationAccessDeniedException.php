<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Exception;

use PhpClaw\Exceptions\PhpClawException;

/**
 * Thrown when the acting admin asks for a conversation owned by someone else.
 */
// non-final: Magento interceptor required
class ConversationAccessDeniedException extends PhpClawException
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

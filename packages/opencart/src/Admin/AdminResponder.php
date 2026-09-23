<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Admin;

use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\OpenCart\Exceptions\ConversationAccessDeniedException;

/**
 * Maps throwables to user-facing error message strings for the OpenCart admin.
 */
final class AdminResponder
{
    /**
     * Return the user-facing error message for a throwable.
     *
     * @param  \Throwable  $e
     * @return string
     */
    public static function messageFor(\Throwable $e): string
    {
        $cause = $e->getPrevious() ?? $e;

        if ($cause instanceof ConversationAccessDeniedException) {
            return 'You do not have permission to access this conversation.';
        }

        if ($cause instanceof GuardException) {
            return 'Request blocked by security guard.';
        }

        if ($cause instanceof ProviderException) {
            return 'AI provider error. Check your API key and provider settings.';
        }

        if ($cause instanceof MaxIterationsException) {
            return 'Agent reached max iterations. Try a simpler or more specific request.';
        }

        return 'An internal error occurred. Please try again.';
    }
}

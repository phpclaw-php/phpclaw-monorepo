<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Admin;

use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Exceptions\TokenBudgetExceededException;
use PhpClaw\OpenCart\Exceptions\ConversationAccessDeniedException;

/**
 * Maps throwables to user-facing error message strings for the OpenCart admin.
 */
final class AdminResponder
{
    private const HTTP_OK = 200;

    private const HTTP_UNPROCESSABLE = 422;

    private const HTTP_TOO_MANY_REQUESTS = 429;

    private const BUDGET_MESSAGE = 'Token budget reached for this run.';

    private const RATE_LIMIT_MESSAGE = 'Rate limit reached, try again shortly.';

    /**
     * Return the user-facing error message for a throwable.
     *
     * @param  \Throwable  $e
     * @return string
     */
    public static function messageFor(\Throwable $e): string
    {
        $limit = self::limitMessage($e);

        if ($limit !== null) {
            return $limit;
        }

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

    /**
     * HTTP status for a failed send: 422 for a spent token budget, 429 for a provider rate limit, 200 otherwise.
     *
     * @param  \Throwable  $e  The failure, possibly wrapping the original exception.
     * @return int
     */
    public static function statusFor(\Throwable $e): int
    {
        return match (self::limitMessage($e)) {
            self::BUDGET_MESSAGE => self::HTTP_UNPROCESSABLE,
            self::RATE_LIMIT_MESSAGE => self::HTTP_TOO_MANY_REQUESTS,
            default => self::HTTP_OK,
        };
    }

    /**
     * The user-facing message when a failure, or any exception it wraps, is a spent token budget or a provider 429.
     *
     * @param  \Throwable  $e  The failure, possibly wrapping the original exception.
     * @return string|null The limit message, or null when the failure is not a limit.
     */
    public static function limitMessage(\Throwable $e): ?string
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof TokenBudgetExceededException) {
                return self::BUDGET_MESSAGE;
            }

            if ($cause instanceof ProviderException && $cause->statusCode === self::HTTP_TOO_MANY_REQUESTS) {
                return self::RATE_LIMIT_MESSAGE;
            }
        }

        return null;
    }
}

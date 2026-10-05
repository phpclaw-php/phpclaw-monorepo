<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Support;

use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Exceptions\TokenBudgetExceededException;

/**
 * User-facing message and HTTP status for the two limit failures: a spent token budget and a provider rate limit.
 */
final class LimitResponse
{
    private const HTTP_UNPROCESSABLE = 422;

    private const HTTP_TOO_MANY_REQUESTS = 429;

    private const BUDGET_MESSAGE = 'Token budget reached for this run.';

    private const RATE_LIMIT_MESSAGE = 'Rate limit reached, try again shortly.';

    /**
     * The message when a failure, or any exception it wraps, is a spent token budget or a provider 429.
     *
     * @param  \Throwable  $e  The failure, possibly wrapping the original exception.
     * @return string|null The limit message, or null when the failure is not a limit.
     */
    public static function message(\Throwable $e): ?string
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

    /**
     * HTTP status for a limit failure: 422 for a spent token budget, 429 for a provider rate limit, null otherwise.
     *
     * @param  \Throwable  $e  The failure, possibly wrapping the original exception.
     * @return int|null
     */
    public static function status(\Throwable $e): ?int
    {
        return match (self::message($e)) {
            self::BUDGET_MESSAGE => self::HTTP_UNPROCESSABLE,
            self::RATE_LIMIT_MESSAGE => self::HTTP_TOO_MANY_REQUESTS,
            default => null,
        };
    }
}

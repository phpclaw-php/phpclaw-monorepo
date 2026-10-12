<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\CLI\Concerns;

use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Exceptions\TokenBudgetExceededException;

/**
 * The line a WP-CLI command prints when a turn fails.
 */
trait ReportsAgentTurns
{
    /**
     * Log the failure to the PHP error log and return the one line shown to the user, which never carries its message.
     *
     * @param  \Throwable  $e  The failure.
     * @param  string  $surface  Where it happened, for the log line: "send", "stream" or "chat".
     * @return string
     */
    private function agentErrorMessage(\Throwable $e, string $surface): string
    {
        error_log("phpClaw: CLI {$surface} ".$e::class.': '.$e->getMessage());

        return match (true) {
            $e instanceof GuardException => 'Your message was blocked by a security guard. Rephrase your prompt and try again.',
            $e instanceof TokenBudgetExceededException => 'Token budget reached for this run.',
            $e instanceof ProviderException && $e->statusCode === 429 => 'Rate limit reached, try again shortly.',
            default => 'An error occurred. Check your API key and provider settings.',
        };
    }
}

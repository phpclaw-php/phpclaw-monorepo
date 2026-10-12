<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Commands\Concerns;

use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Drupal\Support\LimitResponse;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Exceptions\TokenBudgetExceededException;

/**
 * The lines a Drush command prints after a turn: the summary, or the failure.
 */
trait ReportsAgentTurns
{
    /**
     * Provider, model, tokens, the tools the turn used (when any) and the conversation id.
     *
     * @param  ConversationTurn  $turn  The finished turn.
     * @return string
     */
    private function turnSummary(ConversationTurn $turn): string
    {
        $response = $turn->response;
        $summary = sprintf(
            'Provider: %s | Model: %s | Tokens: %s→%s',
            $response->provider,
            $response->model,
            $response->inputTokens ?? '?',
            $response->outputTokens ?? '?',
        );

        if ($response->usedTools()) {
            $summary .= ' | Tools: '.implode(', ', $response->uniqueToolsCalled());
        }

        return $summary.' | Conversation: '.$turn->conversation->id;
    }

    /**
     * One line naming what went wrong: a guard's own reason, otherwise the limit or the exception class, never its message.
     *
     * @param  \Throwable  $e  The failure.
     * @return string
     */
    private function agentErrorMessage(\Throwable $e): string
    {
        return match (true) {
            $e instanceof GuardException => 'Your message was blocked by a security guard: '.$e->getMessage(),
            $e instanceof ProviderException, $e instanceof TokenBudgetExceededException => LimitResponse::message($e) ?? 'AI provider error: '.$e::class,
            $e instanceof MaxIterationsException => 'Agent loop exceeded max iterations: '.$e::class,
            default => 'The agent run failed: '.$e::class,
        };
    }
}

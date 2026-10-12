<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Console\Command\Concerns;

use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Exceptions\TokenBudgetExceededException;
use PhpClaw\Magento\Service\LimitResponse;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The lines a console command prints after a turn: the summary, or the failure.
 *
 * The using command holds `$logger`, a PSR-3 logger.
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
     * Print one line naming what went wrong and log it, except a limit or the iteration cap, which need no log.
     *
     * @param  OutputInterface  $output  Console output.
     * @param  \Throwable  $e  The failure.
     * @return void
     */
    private function reportAgentError(OutputInterface $output, \Throwable $e): void
    {
        $limitMessage = $e instanceof GuardException ? null : LimitResponse::message($e);

        $output->writeln('<error>'.match (true) {
            $e instanceof GuardException => 'Prompt injection detected.',
            $limitMessage !== null => $limitMessage,
            $e instanceof ProviderException, $e instanceof TokenBudgetExceededException => 'AI provider error. Check configuration and try again.',
            $e instanceof MaxIterationsException => 'Could not complete the request. Try a simpler question.',
            default => 'An internal error occurred. Check the Magento log.',
        }.'</error>');

        if ($limitMessage !== null || $e instanceof MaxIterationsException) {
            return;
        }

        $this->logger->error(match (true) {
            $e instanceof GuardException => 'phpClaw guard blocked',
            $e instanceof ProviderException, $e instanceof TokenBudgetExceededException => 'phpClaw provider error',
            default => 'phpClaw agent error',
        }, ['exception' => $e]);
    }
}

<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Command\Concerns;

use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Agent\RunStatus;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Exceptions\RunSuspendedException;
use PhpClaw\Exceptions\TokenBudgetExceededException;
use PhpClaw\Exceptions\ToolException;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The lines a console command prints after a turn: the summary, a stopped run, or a failure.
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
     * Name the run that stopped and the command that finishes it.
     *
     * @param  SymfonyStyle  $io  Console style.
     * @param  RunSuspendedException  $e  The pause.
     * @return void
     */
    private function reportStoppedRun(SymfonyStyle $io, RunSuspendedException $e): void
    {
        $io->warning(sprintf('Run %s stopped: %s. Next: %s', $e->runId, $e->status->value, match ($e->status) {
            RunStatus::Suspended => "bin/console phpclaw:runs resume {$e->runId}",
            default => 'bin/console phpclaw:runs list, then approve or deny the paused call',
        }));
    }

    /**
     * Print one line naming what went wrong; any other error points at the application log.
     *
     * @param  SymfonyStyle  $io  Console style.
     * @param  \Throwable  $e  The failure.
     * @return void
     */
    private function reportAgentError(SymfonyStyle $io, \Throwable $e): void
    {
        $io->error(match (true) {
            $e instanceof GuardException => 'Blocked: '.$e->getMessage(),
            $e instanceof ToolException => 'Tool error: a tool call failed during the agent run.',
            $e instanceof ProviderException => $e->statusCode === 429
                ? 'Rate limit reached, try again shortly.'
                : 'Provider error: the LLM provider returned an error.',
            $e instanceof TokenBudgetExceededException => 'Token budget reached for this run.',
            $e instanceof MaxIterationsException => 'Agent hit iteration limit.',
            default => 'An internal error occurred. Check the application log.',
        });
    }
}

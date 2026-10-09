<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Console\Concerns;

use Illuminate\Console\Command;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Agent\RunStatus;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\PhpClawException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Exceptions\RunSuspendedException;
use PhpClaw\Exceptions\TokenBudgetExceededException;
use PhpClaw\Exceptions\ToolException;

/**
 * The lines a console command prints after a turn: the summary, a stopped run, or a failure.
 *
 * @mixin Command
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
        $summary = "Provider: {$response->provider} | Model: {$response->model} | Tokens: {$response->inputTokens}→{$response->outputTokens}";

        if ($response->usedTools()) {
            $summary .= ' | Tools: '.implode(', ', $response->uniqueToolsCalled());
        }

        return $summary.' | Conversation: '.$turn->conversation->id;
    }

    /**
     * Name the run that stopped and the command that finishes it.
     *
     * @param  RunSuspendedException  $e  The pause.
     * @return void
     */
    private function reportStoppedRun(RunSuspendedException $e): void
    {
        $this->warn(sprintf('Run %s stopped: %s. Next: %s', $e->runId, $e->status->value, match ($e->status) {
            RunStatus::Suspended => "php artisan phpclaw:runs resume {$e->runId}",
            default => 'php artisan phpclaw:runs list, then approve or deny the paused call',
        }));
    }

    /**
     * Report a failed turn to the application log and print one line naming what went wrong.
     *
     * @param  PhpClawException  $e  The failure.
     * @return void
     */
    private function reportAgentError(PhpClawException $e): void
    {
        report($e);

        $this->error(match (true) {
            $e instanceof GuardException => "Blocked: {$e->getMessage()}",
            $e instanceof ToolException => 'Tool error: a tool call failed during the agent run.',
            $e instanceof ProviderException => $e->statusCode === 429
                ? 'Rate limit reached, try again shortly.'
                : 'Provider error: the LLM provider returned an error.',
            $e instanceof TokenBudgetExceededException => 'Token budget reached for this run.',
            $e instanceof MaxIterationsException => 'Agent hit iteration limit.',
            default => 'Agent error: the run failed.',
        });
    }
}

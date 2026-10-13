<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Component\Administrator\Console\Concerns;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Exceptions\TokenBudgetExceededException;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The lines a console command prints after a turn, the summary or the failure, and the language they use.
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
     * Log the failure to the PHP error log and print one translated line, which never carries its message.
     *
     * @param  OutputInterface  $output  Console output.
     * @param  \Throwable  $e  The failure.
     * @return void
     */
    private function reportAgentError(OutputInterface $output, \Throwable $e): void
    {
        error_log('phpClaw CLI: '.$e::class.': '.$e->getMessage());

        $output->writeln('<error>'.Text::_(match (true) {
            $e instanceof GuardException => 'COM_PHPCLAW_CLI_ERROR_GUARD',
            $e instanceof TokenBudgetExceededException => 'COM_PHPCLAW_ERROR_BUDGET_EXCEEDED',
            $e instanceof ProviderException && $e->statusCode === 429 => 'COM_PHPCLAW_ERROR_RATE_LIMITED',
            $e instanceof ProviderException => 'COM_PHPCLAW_CLI_ERROR_PROVIDER',
            $e instanceof MaxIterationsException => 'COM_PHPCLAW_CLI_ERROR_MAX_ITER',
            default => 'COM_PHPCLAW_ERROR_INTERNAL',
        }).'</error>');
    }

    /**
     * Load the component's language strings into the console application, which boots without
     * a component context and would otherwise echo raw keys instead of translated messages.
     *
     * @return void
     */
    private function loadComponentLanguage(): void
    {
        $app = Factory::$application;

        if ($app === null) {
            return;
        }

        $app->getLanguage()->load('com_phpclaw', JPATH_ADMINISTRATOR);
    }
}

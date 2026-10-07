<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Console;

use Illuminate\Console\Command;
use PhpClaw\Agent\RunStatus;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Exceptions\RunSuspendedException;
use PhpClaw\Exceptions\TokenBudgetExceededException;
use PhpClaw\Exceptions\ToolException;

/**
 * Artisan command `php artisan phpclaw "<message>"`, sends a message to the agent and outputs the response (`--stream` for live tokens).
 */
final class PhpClawCommand extends Command
{
    protected $signature = 'phpclaw
        {message : The message to send to the AI agent}
        {--stream : Stream tokens live to output}
        {--provider= : Override the LLM provider}
        {--model= : Override the model}';

    protected $description = 'Send a message to the phpClaw AI agent';

    /**
     * Send the message argument to the agent and print the response.
     *
     * @return int Artisan exit code.
     */
    public function handle(): int
    {
        $message = (string) $this->argument('message');

        $provider = (string) $this->option('provider');
        $model = (string) $this->option('model');
        if ($provider !== '') {
            config(['phpclaw.provider' => $provider]);
        }
        if ($model !== '') {
            config(['phpclaw.model' => $model]);
        }

        $phpclaw = $this->laravel->make(PhpClawInterface::class);

        try {
            $conversation = $phpclaw->conversation();

            if ($this->option('stream')) {
                $turn = $phpclaw->streamInConversation(
                    $conversation,
                    $message,
                    function (string $token): void {
                        $this->output->write($token);
                    },
                );
                $this->line('');
            } else {
                $turn = $phpclaw->sendInConversation($conversation, $message);
                $this->line($turn->response->text);
            }

            $response = $turn->response;

            $this->line('');
            $this->comment(
                "Provider: {$response->provider} | Model: {$response->model} | Tokens: {$response->inputTokens}→{$response->outputTokens}"
            );

            return self::SUCCESS;
        } catch (RunSuspendedException $e) {
            return $this->reportStoppedRun($e);
        } catch (GuardException $e) {
            report($e);
            $this->error("Blocked: {$e->getMessage()}");

            return self::FAILURE;
        } catch (ToolException $e) {
            report($e);
            $this->error('Tool error: a tool call failed during the agent run.');

            return self::FAILURE;
        } catch (ProviderException $e) {
            report($e);
            $this->error($e->statusCode === 429
                ? 'Rate limit reached, try again shortly.'
                : 'Provider error: the LLM provider returned an error.');

            return self::FAILURE;
        } catch (TokenBudgetExceededException $e) {
            report($e);
            $this->error('Token budget reached for this run.');

            return self::FAILURE;
        } catch (MaxIterationsException $e) {
            report($e);
            $this->error('Agent hit iteration limit.');

            return self::FAILURE;
        }
    }

    /**
     * Name the run that stopped and the command that finishes it.
     *
     * @param  RunSuspendedException  $e  The pause.
     * @return int Artisan exit code.
     */
    private function reportStoppedRun(RunSuspendedException $e): int
    {
        $this->warn(sprintf('Run %s stopped: %s. Next: %s', $e->runId, $e->status->value, match ($e->status) {
            RunStatus::Suspended => "php artisan phpclaw:runs resume {$e->runId}",
            default => 'php artisan phpclaw:runs list, then approve or deny the paused call',
        }));

        return self::SUCCESS;
    }
}

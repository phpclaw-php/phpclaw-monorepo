<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Console;

use Illuminate\Console\Command;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Exceptions\RunSuspendedException;
use PhpClaw\Exceptions\TokenBudgetExceededException;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Laravel\Console\Concerns\RendersToolTrace;
use PhpClaw\Laravel\Console\Concerns\ReportsAgentTurns;
use PhpClaw\Laravel\Console\Concerns\TracksLastConversation;

/**
 * Artisan command `php artisan phpclaw "<message>"`, sends one message to the agent and prints the response and a summary.
 */
final class PhpClawCommand extends Command
{
    use RendersToolTrace;
    use ReportsAgentTurns;
    use TracksLastConversation;

    protected $signature = 'phpclaw
        {message : The message to send to the AI agent}
        {--stream : Stream tokens live to output}
        {--provider= : Override the LLM provider}
        {--model= : Override the model}
        {--conv-id= : Continue the stored conversation with this id}
        {--continue : Continue the last conversation this command used}
        {--trace : Print each tool call as it runs}';

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

        if ($this->option('trace') && ! $this->startToolTrace()) {
            return self::FAILURE;
        }

        try {
            $conversation = $this->openConversation($phpclaw, (string) $this->option('conv-id'), (bool) $this->option('continue'));

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

            $this->rememberConversation($turn->conversation->id);

            $this->line('');
            $this->comment($this->turnSummary($turn));

            return self::SUCCESS;
        } catch (RunSuspendedException $e) {
            $this->reportStoppedRun($e);

            return self::SUCCESS;
        } catch (GuardException|ToolException|ProviderException|TokenBudgetExceededException|MaxIterationsException $e) {
            $this->reportAgentError($e);

            return self::FAILURE;
        } finally {
            $this->stopToolTrace();
        }
    }
}

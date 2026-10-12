<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Commands;

use Drush\Attributes as CLI;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Drupal\Commands\Base\PhpClawDrushCommands;
use PhpClaw\Drupal\Commands\Concerns\RendersToolTrace;
use PhpClaw\Drupal\Commands\Concerns\ReportsAgentTurns;
use PhpClaw\Drupal\Commands\Concerns\TracksLastConversation;
use PhpClaw\Drupal\PhpClawServiceFactory;
use PhpClaw\Drupal\Service\DrupalAgentContext;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Exceptions\TokenBudgetExceededException;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Drush commands for the PhpClaw AI agent.
 */
final class PhpClawCommands extends PhpClawDrushCommands
{
    use RendersToolTrace;
    use ReportsAgentTurns;
    use TracksLastConversation;

    /**
     * Construct the Drush commands with the context the agent is built from.
     *
     * @param  DrupalAgentContext  $context  All Drupal dependencies bundled as a context object.
     * @return void
     */
    public function __construct(
        private readonly DrupalAgentContext $context,
    ) {
        parent::__construct();
    }

    /**
     * Send a message to the PhpClaw AI agent, print the response and a summary line.
     *
     * @param  string  $message  The natural-language message to send to the agent.
     * @param  array<string, mixed>  $options  Drush options: 'stream', 'conv-id', 'continue' and 'trace'.
     * @return int Exit code: 0 on success, 1 on failure.
     */
    #[CLI\Command(name: 'phpclaw:run', aliases: ['pc'])]
    #[CLI\Argument(name: 'message', description: 'The message to send to the AI agent.')]
    #[CLI\Option(name: 'stream', description: 'Stream the response token by token.')]
    #[CLI\Option(name: 'conv-id', description: 'Continue the stored conversation with this id.')]
    #[CLI\Option(name: 'continue', description: 'Continue the last conversation.')]
    #[CLI\Option(name: 'trace', description: 'Print one line per tool call.')]
    #[CLI\Usage(name: 'drush phpclaw:run "list active modules"', description: 'Ask the AI agent to list active Drupal modules.')]
    #[CLI\Usage(name: 'drush phpclaw:run "show cache bin sizes" --stream', description: 'Ask the AI agent with streaming output.')]
    #[CLI\Usage(name: 'drush phpclaw:run "and the disabled ones?" --continue --trace', description: 'Continue the last conversation and print each tool call.')]
    #[CLI\Usage(name: 'drush pc "check database connections"', description: 'Use the pc alias for brevity.')]
    public function run(string $message, array $options = ['stream' => false, 'conv-id' => self::REQ, 'continue' => false, 'trace' => false]): int
    {
        $agent = PhpClawServiceFactory::create($this->context);

        if ($agent === null) {
            $this->logger()?->error('Agent unavailable. Check the provider and API key in Settings.');

            return 1;
        }

        $output = $this->output();

        if ($options['trace']) {
            $this->startToolTrace($output);
        }

        try {
            $conversation = $this->openConversation($output, $agent, (string) ($options['conv-id'] ?? ''), continue: (bool) $options['continue']);
            $turn = $this->sendTurn($output, $agent, $conversation, $message, stream: (bool) $options['stream']);
        } catch (GuardException|ProviderException|TokenBudgetExceededException|MaxIterationsException $e) {
            $this->logger()?->error($this->agentErrorMessage($e));

            return 1;
        } finally {
            $this->stopToolTrace();
        }

        $this->rememberConversation($turn->conversation->id);
        $output->writeln('<comment>'.$this->turnSummary($turn).'</comment>');

        return 0;
    }

    /**
     * Send one message and print the reply, token by token when streaming.
     *
     * @param  OutputInterface  $output  Console output for the reply.
     * @param  ClawInterface  $agent  Agent that runs the turn.
     * @param  Conversation  $conversation  Conversation the turn belongs to.
     * @param  string  $message  The message to send.
     * @param  bool  $stream  Whether to print the reply as it arrives.
     * @return ConversationTurn
     */
    private function sendTurn(OutputInterface $output, ClawInterface $agent, Conversation $conversation, string $message, bool $stream): ConversationTurn
    {
        if (! $stream) {
            $turn = $agent->sendInConversation($conversation, $message);
            $output->writeln($turn->response->text);

            return $turn;
        }

        $turn = $agent->streamInConversation(
            $conversation,
            $message,
            static function (string $token) use ($output): void {
                $output->write($token);
            },
        );
        $output->writeln('');

        return $turn;
    }
}

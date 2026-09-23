<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Commands;

use Drush\Attributes as CLI;
use PhpClaw\Drupal\Commands\Base\PhpClawDrushCommands;
use PhpClaw\Drupal\PhpClawServiceFactory;
use PhpClaw\Drupal\Service\DrupalAgentContext;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\ProviderException;

/**
 * Drush commands for the PhpClaw AI agent.
 */
final class PhpClawCommands extends PhpClawDrushCommands
{
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
     * Send a message to the PhpClaw AI agent and print the response.
     *
     * @param  string  $message  The natural-language message to send to the agent.
     * @param  array<string, mixed>  $options  Drush options; supports 'stream' (bool).
     * @return int Exit code: 0 on success, 1 on failure.
     */
    #[CLI\Command(name: 'phpclaw:run', aliases: ['pc'])]
    #[CLI\Argument(name: 'message', description: 'The message to send to the AI agent.')]
    #[CLI\Option(name: 'stream', description: 'Stream the response token by token.')]
    #[CLI\Usage(name: 'drush phpclaw:run "list active modules"', description: 'Ask the AI agent to list active Drupal modules.')]
    #[CLI\Usage(name: 'drush phpclaw:run "show cache bin sizes" --stream', description: 'Ask the AI agent with streaming output.')]
    #[CLI\Usage(name: 'drush pc "check database connections"', description: 'Use the pc alias for brevity.')]
    public function run(string $message, array $options = ['stream' => false]): int
    {
        $agent = PhpClawServiceFactory::create($this->context);

        if ($agent === null) {
            $this->logger()?->error('Agent unavailable. Check the provider and API key in Settings.');

            return 1;
        }

        try {
            $conversation = $agent->conversation();

            if ($options['stream']) {
                $agent->streamInConversation(
                    $conversation,
                    $message,
                    function (string $token): void {
                        $this->output()->write($token);
                    },
                );
                $this->output()->writeln('');
            } else {
                $turn = $agent->sendInConversation($conversation, $message);
                $this->output()->writeln($turn->response->text);
            }
        } catch (GuardException $e) {
            $this->logger()?->error('Your message was blocked by a security guard: '.$e->getMessage());

            return 1;
        } catch (ProviderException $e) {
            $this->logger()?->error('AI provider error: '.$e::class);

            return 1;
        } catch (MaxIterationsException $e) {
            $this->logger()?->error('Agent loop exceeded max iterations: '.$e::class);

            return 1;
        }

        return 0;
    }
}

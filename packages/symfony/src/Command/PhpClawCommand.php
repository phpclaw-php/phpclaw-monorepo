<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Command;

use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Exceptions\ToolException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Console command that sends a message to the phpClaw AI agent and prints the response.
 */
#[AsCommand(
    name: 'phpclaw',
    description: 'Send a message to the phpClaw AI agent.',
)]
final class PhpClawCommand extends Command
{
    /**
     * Constructs the command with the resolved phpClaw engine.
     *
     * @param  PhpClawInterface  $phpClaw  The resolved phpClaw engine instance.
     */
    public function __construct(
        private readonly PhpClawInterface $phpClaw,
    ) {
        parent::__construct();
    }

    /**
     * Declares the message argument and the stream option.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->addArgument(
                name: 'message',
                mode: InputArgument::REQUIRED,
                description: 'The message or task to send to the AI agent.',
            )
            ->addOption(
                name: 'stream',
                shortcut: null,
                mode: InputOption::VALUE_NONE,
                description: 'Stream the response token by token.',
            );
    }

    /**
     * Runs the agent with the supplied message and prints the result.
     *
     * @param  InputInterface  $input
     * @param  OutputInterface  $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $message = (string) $input->getArgument('message');

        try {
            $conversation = $this->phpClaw->conversation();

            if ($input->getOption('stream')) {
                $turn = $this->phpClaw->streamInConversation(
                    $conversation,
                    $message,
                    static function (string $token) use ($output): void {
                        $output->write($token);
                    },
                );
                $output->writeln('');
            } else {
                $turn = $this->phpClaw->sendInConversation($conversation, $message);
                $io->writeln($turn->response->text);
            }

            $response = $turn->response;

            $io->comment(sprintf(
                'Provider: %s | Model: %s | Tokens: %s→%s',
                $response->provider,
                $response->model,
                $response->inputTokens ?? '?',
                $response->outputTokens ?? '?',
            ));

            return Command::SUCCESS;
        } catch (GuardException $e) {
            $io->error('Blocked: '.$e->getMessage());

            return Command::FAILURE;
        } catch (ToolException) {
            $io->error('Tool error: a tool call failed during the agent run.');

            return Command::FAILURE;
        } catch (ProviderException) {
            $io->error('Provider error: the LLM provider returned an error.');

            return Command::FAILURE;
        } catch (MaxIterationsException) {
            $io->error('Agent hit iteration limit.');

            return Command::FAILURE;
        } catch (\Throwable) {
            $io->error('An internal error occurred. Check the application log.');

            return Command::FAILURE;
        }
    }
}

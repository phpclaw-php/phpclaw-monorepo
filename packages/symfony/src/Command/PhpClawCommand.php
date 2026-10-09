<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Command;

use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Exceptions\RunSuspendedException;
use PhpClaw\Exceptions\TokenBudgetExceededException;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Symfony\Command\Concerns\RendersToolTrace;
use PhpClaw\Symfony\Command\Concerns\ReportsAgentTurns;
use PhpClaw\Symfony\Command\Concerns\TracksLastConversation;
use PhpClaw\Symfony\PhpClawFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Console command that sends one message to the phpClaw AI agent and prints the response and a summary.
 */
#[AsCommand(
    name: 'phpclaw',
    description: 'Send a message to the phpClaw AI agent.',
)]
final class PhpClawCommand extends Command
{
    use RendersToolTrace;
    use ReportsAgentTurns;
    use TracksLastConversation;

    /**
     * Constructs the command with the terminal engine and the settings resume and the trace need.
     *
     * @param  PhpClawInterface  $phpClaw  The terminal engine, which keeps the Y/n prompt.
     * @param  string  $memoryDriver  The `phpclaw.memory_driver` setting; `array` cannot resume.
     * @param  string  $stateDir  Kernel cache folder for the last conversation id; '' saves nothing.
     * @param  string  $environment  Kernel environment; prod refuses a traced run on a project workspace.
     * @param  string  $workspaceRoot  The `phpclaw.workspace_root` setting.
     * @param  string  $projectDir  Kernel project folder; its var/phpclaw is the default workspace.
     */
    public function __construct(
        #[Autowire(service: PhpClawFactory::TERMINAL_ENGINE)]
        private readonly PhpClawInterface $phpClaw,
        #[Autowire('%phpclaw.memory_driver%')]
        private readonly string $memoryDriver = 'doctrine',
        #[Autowire('%kernel.cache_dir%')]
        private readonly string $stateDir = '',
        #[Autowire('%kernel.environment%')]
        private readonly string $environment = 'dev',
        #[Autowire('%phpclaw.workspace_root%')]
        private readonly string $workspaceRoot = '',
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir = '',
    ) {
        parent::__construct();
    }

    /**
     * Declares the message argument and the stream, resume and trace options.
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
            )
            ->addOption(
                name: 'conv-id',
                shortcut: null,
                mode: InputOption::VALUE_REQUIRED,
                description: 'Continue the stored conversation with this id.',
            )
            ->addOption(
                name: 'continue',
                shortcut: null,
                mode: InputOption::VALUE_NONE,
                description: 'Continue the last conversation this command used.',
            )
            ->addOption(
                name: 'trace',
                shortcut: null,
                mode: InputOption::VALUE_NONE,
                description: 'Print each tool call as it runs.',
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

        if ($input->getOption('trace') && ! $this->startToolTrace($io)) {
            return Command::FAILURE;
        }

        try {
            $conversation = $this->openConversation($io, $this->phpClaw, (string) $input->getOption('conv-id'), (bool) $input->getOption('continue'));

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

            $this->rememberConversation($turn->conversation->id);
            $io->comment($this->turnSummary($turn));

            return Command::SUCCESS;
        } catch (RunSuspendedException $e) {
            $this->reportStoppedRun($io, $e);

            return Command::SUCCESS;
        } catch (GuardException|ToolException|ProviderException|TokenBudgetExceededException|MaxIterationsException $e) {
            $this->reportAgentError($io, $e);

            return Command::FAILURE;
        } catch (\Throwable $e) {
            $this->reportAgentError($io, $e);

            return Command::FAILURE;
        } finally {
            $this->stopToolTrace();
        }
    }
}

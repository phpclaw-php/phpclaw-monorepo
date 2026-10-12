<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Console\Command;

use Magento\Framework\Console\Cli;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Exceptions\TokenBudgetExceededException;
use PhpClaw\Magento\Console\Command\Concerns\RendersToolTrace;
use PhpClaw\Magento\Console\Command\Concerns\ReportsAgentTurns;
use PhpClaw\Magento\Console\Command\Concerns\TracksLastConversation;
use PhpClaw\Magento\Factory\PhpClawFactoryInterface;
use PhpClaw\Magento\Service\ToolCallCollectorFactory;
use PhpClaw\Magento\Service\ToolHistorySplicer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Magento CLI command for the phpClaw AI agent.
 */
// non-final: Magento interceptor required
class PhpClawRunCommand extends Command
{
    use RendersToolTrace;
    use ReportsAgentTurns;
    use TracksLastConversation;

    protected static $defaultName = 'phpclaw:run';

    protected static $defaultDescription = 'Send a message to the PhpClaw AI agent.';

    /**
     * Bind the agent factory and logger this command runs prompts through.
     *
     * @param  PhpClawFactoryInterface  $phpClawFactory  Factory that builds the configured agent.
     * @param  LoggerInterface  $logger  PSR-3 logger for recording guard and provider errors.
     * @param  ToolHistorySplicer  $splicer  Splices tool-call entries into conversation history.
     * @param  ToolCallCollectorFactory  $toolCallCollectorFactory  Builds a fresh tool-call collector per turn.
     * @param  string|null  $name  Optional command name override (Magento interceptor use).
     * @param  string  $varDirectory  Folder for the last conversation id; '' uses the Magento var folder.
     * @return void
     */
    public function __construct(
        private readonly PhpClawFactoryInterface $phpClawFactory,
        private readonly LoggerInterface $logger,
        private readonly ToolHistorySplicer $splicer,
        private readonly ToolCallCollectorFactory $toolCallCollectorFactory,
        ?string $name = null,
        private readonly string $varDirectory = '',
    ) {
        parent::__construct($name ?? static::$defaultName);
    }

    /**
     * Define the command arguments and options.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->addArgument('message', InputArgument::REQUIRED, 'The message to send to the AI agent.');
        $this->addOption('stream', null, InputOption::VALUE_NONE, 'Stream the response token by token.');
        $this->addOption('conv-id', null, InputOption::VALUE_REQUIRED, 'Continue an existing conversation by ID (omit to start a new one).');
        $this->addOption('continue', null, InputOption::VALUE_NONE, 'Continue the last conversation.');
        $this->addOption('trace', null, InputOption::VALUE_NONE, 'Print one line per tool call.');
    }

    /**
     * Run the agent with the provided message argument and write the reply to output.
     *
     * @param  InputInterface  $input  Console input (the message argument, --stream, --conv-id, --continue and --trace).
     * @param  OutputInterface  $output  Console output to write the agent reply to.
     * @return int Cli::RETURN_SUCCESS on success, Cli::RETURN_FAILURE on error.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $message = (string) $input->getArgument('message');
        $stream = (bool) $input->getOption('stream');
        $agent = $this->phpClawFactory->create();

        if ($input->getOption('trace')) {
            $this->startToolTrace($output);
        }

        try {
            $conversation = $this->openConversation($output, $agent, (string) ($input->getOption('conv-id') ?? ''), (bool) $input->getOption('continue'));

            if ($stream) {
                $collector = $this->toolCallCollectorFactory->create();
                $collector->listen();
                $turn = $agent->streamInConversation(
                    $conversation,
                    $message,
                    function (string $token) use ($output): void {
                        $output->write($token);
                    },
                    function (array $payload) use ($collector): array {
                        if (! $collector->isEmpty()) {
                            $history = (array) ($payload['history'] ?? $payload['messages'] ?? []);
                            $insertAt = $this->splicer->findLastAssistantIndex($history);
                            $payload['history'] = $this->splicer->splice($history, $collector->toArrayList(), $insertAt);
                        }

                        return $payload;
                    },
                );
                $output->writeln('');
            } else {
                $turn = $agent->sendInConversation($conversation, $message);
                $text = $turn->response->text;
                $text = preg_replace('#\s*<tool_call>.*?</tool_call>\s*#s', '', $text) ?? $text;
                $text = preg_replace('#\s*</tool_call>\s*#', '', $text) ?? $text;
                $text = trim($text);
                $output->writeln($text);
            }
        } catch (GuardException|ProviderException|TokenBudgetExceededException|MaxIterationsException $e) {
            $this->reportAgentError($output, $e);

            return Cli::RETURN_FAILURE;
        } finally {
            $this->stopToolTrace();
        }

        $this->rememberConversation($turn->conversation->id);
        $output->writeln('<comment>'.$this->turnSummary($turn).'</comment>');
        $output->writeln(sprintf('<comment>conv-id=%s</comment>', $turn->conversation->id));

        return Cli::RETURN_SUCCESS;
    }
}

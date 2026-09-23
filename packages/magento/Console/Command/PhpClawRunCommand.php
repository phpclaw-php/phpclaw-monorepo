<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Console\Command;

use Magento\Framework\Console\Cli;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\ProviderException;
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
     * @return void
     */
    public function __construct(
        private readonly PhpClawFactoryInterface $phpClawFactory,
        private readonly LoggerInterface $logger,
        private readonly ToolHistorySplicer $splicer,
        private readonly ToolCallCollectorFactory $toolCallCollectorFactory,
        ?string $name = null,
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
    }

    /**
     * Run the agent with the provided message argument and write the reply to output.
     *
     * @param  InputInterface  $input  Console input (provides the message argument, --stream, and --conv-id).
     * @param  OutputInterface  $output  Console output to write the agent reply to.
     * @return int Cli::RETURN_SUCCESS on success, Cli::RETURN_FAILURE on error.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $message = (string) $input->getArgument('message');
        $stream = (bool) $input->getOption('stream');
        $convId = (string) ($input->getOption('conv-id') ?? '');
        $agent = $this->phpClawFactory->create();

        try {
            $conversation = $convId !== ''
                ? $agent->conversation($convId)
                : $agent->conversation();

            if ($stream) {
                $collector = $this->toolCallCollectorFactory->create();
                $collector->listen();
                $agent->streamInConversation(
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
                $output->writeln(sprintf('<comment>conv-id=%s</comment>', $conversation->id));
            } else {
                $turn = $agent->sendInConversation($conversation, $message);
                $text = $turn->response->text;
                $text = preg_replace('#\s*<tool_call>.*?</tool_call>\s*#s', '', $text) ?? $text;
                $text = preg_replace('#\s*</tool_call>\s*#', '', $text) ?? $text;
                $text = trim($text);
                $output->writeln($text);
                $output->writeln(sprintf('<comment>conv-id=%s</comment>', $turn->conversation->id));
            }
        } catch (GuardException $e) {
            $this->logger->error('phpClaw guard blocked', ['exception' => $e]);
            $output->writeln('<error>Prompt injection detected.</error>');

            return Cli::RETURN_FAILURE;
        } catch (ProviderException $e) {
            $this->logger->error('phpClaw provider error', ['exception' => $e]);
            $output->writeln('<error>AI provider error. Check configuration and try again.</error>');

            return Cli::RETURN_FAILURE;
        } catch (MaxIterationsException) {
            $output->writeln('<error>Could not complete the request. Try a simpler question.</error>');

            return Cli::RETURN_FAILURE;
        }

        return Cli::RETURN_SUCCESS;
    }
}

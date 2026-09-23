<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Component\Administrator\Console;

use Closure;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\Console\Command\AbstractCommand;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\ProviderException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Joomla CLI command for the phpClaw AI agent.
 */
final class PhpClawCommand extends AbstractCommand
{
    private const EXIT_SUCCESS = 0;

    private const EXIT_ERROR = 1;

    protected static $defaultName = 'phpclaw';

    protected static $defaultDescription = 'Send a message to the PhpClaw AI agent.';

    private ?ClawInterface $agent = null;

    /**
     * Create a new PhpClawCommand instance.
     *
     * @param  Closure(): ClawInterface  $agentFactory  Builds the engine on first use.
     */
    public function __construct(
        private readonly Closure $agentFactory,
    ) {
        parent::__construct();
    }

    /**
     * Define the `message` argument plus `--stream` and `--conv-id` options.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->addArgument('message', InputArgument::REQUIRED, 'The message to send to the AI agent.');
        $this->addOption('stream', null, InputOption::VALUE_NONE, 'Stream response chunks to output.');
        $this->addOption('conv-id', null, InputOption::VALUE_OPTIONAL, 'Conversation ID for multi-turn chat.');
    }

    /**
     * Execute the CLI command.
     *
     * @param  InputInterface  $input
     * @param  OutputInterface  $output
     * @return int Exit code.
     */
    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $this->loadComponentLanguage();

        $message = (string) $input->getArgument('message');
        $stream = (bool) $input->getOption('stream');
        $convId = (string) ($input->getOption('conv-id') ?? '');

        if ($stream) {
            return $this->runWithErrorHandling($output, fn (): bool => $this->runStreaming($message, $output));
        }

        if ($convId !== '') {
            return $this->runWithErrorHandling($output, fn (): bool => $this->runConversationTurn($convId, $message, $output));
        }

        return $this->runWithErrorHandling($output, fn (): bool => $this->runSingleShot($message, $output));
    }

    /**
     * Build the engine on first use, then reuse it for the rest of the run.
     *
     * @return ClawInterface
     */
    private function agent(): ClawInterface
    {
        return $this->agent ??= ($this->agentFactory)();
    }

    /**
     * Stream the response token-by-token to STDOUT.
     *
     * @param  string  $message
     * @param  OutputInterface  $output
     * @return bool
     */
    private function runStreaming(string $message, OutputInterface $output): bool
    {
        $conversation = $this->agent()->conversation();
        $this->agent()->streamInConversation(
            $conversation,
            $message,
            static function (string $chunk) use ($output): void {
                $output->write($chunk);
            },
        );
        $output->writeln('');

        return true;
    }

    /**
     * Continue an existing conversation by id, emit just the assistant text.
     *
     * @param  string  $convId
     * @param  string  $message
     * @param  OutputInterface  $output
     * @return bool
     */
    private function runConversationTurn(string $convId, string $message, OutputInterface $output): bool
    {
        $conversation = $this->agent()->conversation($convId);
        $turn = $this->agent()->sendInConversation($conversation, $message);
        $output->writeln($turn->response->text);

        return true;
    }

    /**
     * Send a one-shot prompt in a fresh conversation and emit the assistant text.
     *
     * @param  string  $message
     * @param  OutputInterface  $output
     * @return bool
     */
    private function runSingleShot(string $message, OutputInterface $output): bool
    {
        $conversation = $this->agent()->conversation();
        $turn = $this->agent()->sendInConversation($conversation, $message);
        $output->writeln($turn->response->text);

        return true;
    }

    /**
     * Run $work, mapping the expected exception types to localised CLI errors.
     *
     * @param  OutputInterface  $output
     * @param  callable(): bool  $work
     * @return int Exit code.
     */
    private function runWithErrorHandling(OutputInterface $output, callable $work): int
    {
        try {
            $work();

            return self::EXIT_SUCCESS;
        } catch (GuardException $e) {
            return $this->reportError($output, $e, 'COM_PHPCLAW_CLI_ERROR_GUARD');
        } catch (ProviderException $e) {
            return $this->reportError($output, $e, 'COM_PHPCLAW_CLI_ERROR_PROVIDER');
        } catch (MaxIterationsException $e) {
            return $this->reportError($output, $e, 'COM_PHPCLAW_CLI_ERROR_MAX_ITER');
        }
    }

    /**
     * Log the exception and print the localised error message.
     *
     * @param  OutputInterface  $output
     * @param  \Throwable  $e
     * @param  string  $languageKey
     * @return int Error exit code.
     */
    private function reportError(OutputInterface $output, \Throwable $e, string $languageKey): int
    {
        error_log('phpClaw CLI: '.$e->getMessage());
        $output->writeln('<error>'.Text::_($languageKey).'</error>');

        return self::EXIT_ERROR;
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

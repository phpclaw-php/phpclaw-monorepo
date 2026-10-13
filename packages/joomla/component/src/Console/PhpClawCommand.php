<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Component\Administrator\Console;

use Closure;
use Joomla\Console\Command\AbstractCommand;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Exceptions\PhpClawException;
use PhpClaw\Joomla\Component\Administrator\Console\Concerns\RendersToolTrace;
use PhpClaw\Joomla\Component\Administrator\Console\Concerns\ReportsAgentTurns;
use PhpClaw\Joomla\Component\Administrator\Console\Concerns\TracksLastConversation;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Joomla CLI command for the phpClaw AI agent: one message, sent or streamed, then a summary line.
 */
final class PhpClawCommand extends AbstractCommand
{
    use RendersToolTrace;
    use ReportsAgentTurns;
    use TracksLastConversation;

    private const EXIT_SUCCESS = 0;

    private const EXIT_ERROR = 1;

    protected static $defaultName = 'phpclaw';

    private ?ClawInterface $agent = null;

    /**
     * Create a new PhpClawCommand instance.
     *
     * @param  Closure(): ClawInterface  $agentFactory  Builds the engine on first use.
     * @param  string  $storageDirectory  Folder for the last conversation id; '' uses the component's storage folder.
     */
    public function __construct(
        private readonly Closure $agentFactory,
        private readonly string $storageDirectory = '',
    ) {
        parent::__construct();
    }

    /**
     * Define the `message` argument plus the `--stream`, `--conv-id`, `--continue` and `--trace` options.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setDescription('Send a message to the PhpClaw AI agent.');
        $this->addArgument('message', InputArgument::REQUIRED, 'The message to send to the AI agent.');
        $this->addOption('stream', null, InputOption::VALUE_NONE, 'Stream response chunks to output.');
        $this->addOption('conv-id', null, InputOption::VALUE_OPTIONAL, 'Conversation ID for multi-turn chat.');
        $this->addOption('continue', null, InputOption::VALUE_NONE, 'Continue the last conversation.');
        $this->addOption('trace', null, InputOption::VALUE_NONE, 'Print one line per tool call.');
    }

    /**
     * Execute the CLI command.
     *
     * @param  InputInterface  $input  The message argument and the options.
     * @param  OutputInterface  $output  Console output for the reply and the summary line.
     * @return int Exit code.
     */
    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $this->loadComponentLanguage();

        if ($input->getOption('trace')) {
            $this->startToolTrace($output);
        }

        try {
            $conversation = $this->openConversation($output, $this->agent(), (string) ($input->getOption('conv-id') ?? ''), (bool) $input->getOption('continue'));
            $turn = $this->sendTurn($conversation, (string) $input->getArgument('message'), $output, stream: (bool) $input->getOption('stream'));
        } catch (PhpClawException $e) {
            $this->reportAgentError($output, $e);

            return self::EXIT_ERROR;
        } finally {
            $this->stopToolTrace();
        }

        $this->rememberConversation($turn->conversation->id);
        $output->writeln('<comment>'.$this->turnSummary($turn).'</comment>');

        return self::EXIT_SUCCESS;
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
     * Send one message and print the reply: all at once, or chunk by chunk when streaming.
     *
     * @param  Conversation  $conversation  Conversation the turn belongs to.
     * @param  string  $message  The prompt to send.
     * @param  OutputInterface  $output  Console output for the reply.
     * @param  bool  $stream  Whether to write each chunk as it arrives.
     * @return ConversationTurn
     */
    private function sendTurn(Conversation $conversation, string $message, OutputInterface $output, bool $stream): ConversationTurn
    {
        if (! $stream) {
            $turn = $this->agent()->sendInConversation($conversation, $message);
            $output->writeln($turn->response->text);

            return $turn;
        }

        $turn = $this->agent()->streamInConversation(
            $conversation,
            $message,
            static function (string $chunk) use ($output): void {
                $output->write($chunk);
            },
        );
        $output->writeln('');

        return $turn;
    }
}

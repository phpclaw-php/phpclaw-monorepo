<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Component\Administrator\Console;

use Closure;
use Joomla\Console\Command\AbstractCommand;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Claw;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Exceptions\AdapterException;
use PhpClaw\Exceptions\PhpClawException;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use PhpClaw\Joomla\Component\Administrator\Console\Concerns\RendersToolTrace;
use PhpClaw\Joomla\Component\Administrator\Console\Concerns\ReportsAgentTurns;
use PhpClaw\Joomla\Component\Administrator\Console\Concerns\TracksLastConversation;
use Symfony\Component\Console\Exception\MissingInputException;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Joomla CLI command `php cli/joomla.php phpclaw:chat`, an interactive agent session: one conversation, streamed
 * turns, a live tool trace and slash commands.
 */
final class ChatCommand extends AbstractCommand
{
    use RendersToolTrace;
    use ReportsAgentTurns;
    use TracksLastConversation;

    private const EXIT_SUCCESS = 0;

    private const EXIT_ERROR = 1;

    private const PROMPT = 'you';

    private const SLASH_COMMANDS = [
        '/help' => 'list these commands',
        '/tools' => 'list the active tools',
        '/model <name>' => 'switch the model for this session only',
        '/provider <slug>' => 'switch the provider for this session only',
        '/new' => 'start a new conversation',
        '/conv' => 'print the conversation id',
        '/quiet' => 'turn the tool trace off or on',
        '/clear' => 'clear the screen',
        '/exit' => 'leave (Ctrl+D also leaves)',
    ];

    private const LOGO = <<<'ART'
██████╗  ██╗  ██╗  ██████╗   ██████╗ ██╗       █████╗  ██╗    ██╗
██╔══██╗ ██║  ██║  ██╔══██╗ ██╔════╝ ██║      ██╔══██╗ ██║ █╗ ██║
██████╔╝ ███████║  ██████╔╝ ██║      ██║      ███████║ ██║███╗██║
██╔═══╝  ██╔══██║  ██╔═══╝  ██║      ██║      ██╔══██║ ╚███╔███╔╝
██║      ██║  ██║  ██║      ╚██████╗ ███████╗ ██║  ██║  ╚██╔██╔╝
╚═╝      ╚═╝  ╚═╝  ╚═╝       ╚═════╝ ╚══════╝ ╚═╝  ╚═╝   ╚═╝╚═╝
ART;

    private const LOGO_GRADIENT = [201, 129, 27, 51, 46, 226];

    private const TAGLINE = 'AI agents for Joomla';

    private const SPINNER_FRAMES = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

    private const SPINNER_LABEL = 'Thinking...';

    private const SPINNER_FRAME_MICROSECONDS = 100000;

    private const CLEAR_LINE = "\r\e[2K";

    protected static $defaultName = 'phpclaw:chat';

    private ClawInterface $claw;

    private Conversation $conversation;

    private string $sessionProvider = '';

    private string $sessionModel = '';

    private bool $turnRunning = false;

    private bool $spinnerShown = false;

    private ?int $spinnerPid = null;

    private ?Closure $pauseSpinner = null;

    private ?Closure $resumeSpinner = null;

    /**
     * Bind the factory that builds the session engine and the folder for the last conversation id.
     *
     * @param  Closure(string, string): ClawInterface  $agentFactory  Builds the engine from a provider and a model; '' keeps the saved value.
     * @param  string  $storageDirectory  Folder for the last conversation id; '' uses the component's storage folder.
     */
    public function __construct(
        private readonly Closure $agentFactory,
        private readonly string $storageDirectory = '',
    ) {
        parent::__construct();
    }

    /**
     * Declare the resume options.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setDescription('Open an interactive phpClaw agent session.');
        $this->addOption('conv-id', null, InputOption::VALUE_REQUIRED, 'Continue the stored conversation with this id.');
        $this->addOption('continue', null, InputOption::VALUE_NONE, 'Continue the last conversation.');
    }

    /**
     * Run the session until /exit or end of input.
     *
     * @param  InputInterface  $input  Console input with the resume options.
     * @param  OutputInterface  $output  Console output.
     * @return int 0 when the session ends, 1 without a terminal or when the engine cannot be built.
     */
    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $this->loadComponentLanguage();
        $io = new SymfonyStyle($input, $output);

        if (! $this->hasTerminal($input)) {
            $io->error('phpclaw:chat needs an interactive terminal. For one message use: php cli/joomla.php phpclaw "<message>"');

            return self::EXIT_ERROR;
        }

        try {
            $this->claw = ($this->agentFactory)('', '');
        } catch (PhpClawException $e) {
            $this->reportAgentError($io, $e);

            return self::EXIT_ERROR;
        }

        $this->startToolTrace($io);
        $this->watchToolsForSpinner($io);

        try {
            $this->conversation = $this->openConversation($io, $this->claw, (string) $input->getOption('conv-id'), (bool) $input->getOption('continue'));
            $this->banner($io);
            $this->printHeader($io);
            $this->loop($io);
        } finally {
            $this->stopSpinner($io);
            $this->unwatchToolsForSpinner();
            $this->stopToolTrace();
        }

        $io->writeln("Resume with: php cli/joomla.php phpclaw:chat --conv-id={$this->conversation->id}");

        return self::EXIT_SUCCESS;
    }

    /**
     * Whether a person can type into this session: interactive input and, for a command line (`ArgvInput`), a terminal on STDIN.
     *
     * @param  InputInterface  $input  Console input.
     * @return bool
     */
    private function hasTerminal(InputInterface $input): bool
    {
        if (! $input->isInteractive()) {
            return false;
        }

        return $input::class !== ArgvInput::class || (defined('STDIN') && @stream_isatty(STDIN));
    }

    /**
     * Print the phpClaw wordmark and tagline, coloured on a decorated terminal and plain otherwise; no version.
     *
     * @param  SymfonyStyle  $io  Console style.
     * @return void
     */
    private function banner(SymfonyStyle $io): void
    {
        $decorated = $io->isDecorated();

        foreach (explode("\n", self::LOGO) as $i => $line) {
            $color = self::LOGO_GRADIENT[$i] ?? 226;
            $io->writeln($decorated ? "\e[38;5;{$color}m{$line}\e[0m" : $line);
        }

        $io->writeln($decorated ? "\e[2m".self::TAGLINE."\e[0m" : self::TAGLINE);
        $io->newLine();
    }

    /**
     * Read lines until /exit or end of input; a line starting with / is a command, an empty line does nothing.
     *
     * @param  SymfonyStyle  $io  Console style.
     * @return void
     */
    private function loop(SymfonyStyle $io): void
    {
        while (($line = $this->readLine($io)) !== null) {
            if ($line === '') {
                continue;
            }

            if (! str_starts_with($line, '/')) {
                $this->runTurn($io, $line);

                continue;
            }

            if (! $this->runSlashCommand($io, $line)) {
                return;
            }
        }
    }

    /**
     * Read one trimmed line, or null at end of input (Ctrl+D).
     *
     * @param  SymfonyStyle  $io  Console style.
     * @return string|null
     */
    private function readLine(SymfonyStyle $io): ?string
    {
        try {
            return trim((string) $io->ask(self::PROMPT));
        } catch (MissingInputException) {
            return null;
        }
    }

    /**
     * Stream one turn in the session's conversation; a failure is printed and the session goes on.
     *
     * @param  SymfonyStyle  $io  Console style.
     * @param  string  $message  What the user typed.
     * @return void
     */
    private function runTurn(SymfonyStyle $io, string $message): void
    {
        try {
            $turn = $this->streamTurn($io, $message);
        } catch (PhpClawException $e) {
            $io->writeln('');
            $this->reportAgentError($io, $e);

            return;
        }

        $io->writeln('');
        $this->conversation = $turn->conversation;
        $this->rememberConversation($turn->conversation->id);
        $io->comment($this->turnSummary($turn));
    }

    /**
     * Send one message with the spinner shown until the first word of the reply.
     *
     * @param  SymfonyStyle  $io  Console style.
     * @param  string  $message  What the user typed.
     * @return ConversationTurn
     */
    private function streamTurn(SymfonyStyle $io, string $message): ConversationTurn
    {
        $this->turnRunning = true;
        $this->startSpinner($io);

        try {
            return $this->claw->streamInConversation(
                $this->conversation,
                $message,
                function (string $token) use ($io): void {
                    $this->stopSpinner($io);
                    $io->write($token);
                },
            );
        } finally {
            $this->turnRunning = false;
            $this->stopSpinner($io);
        }
    }

    /**
     * Show the spinner on a terminal: animated by a child process, or a still line when the process cannot fork.
     *
     * @param  SymfonyStyle  $io  Console style.
     * @return void
     */
    private function startSpinner(SymfonyStyle $io): void
    {
        if ($this->spinnerShown || ! $io->isDecorated()) {
            return;
        }

        $this->spinnerShown = true;
        $parent = getmypid();
        $pid = function_exists('pcntl_fork') && function_exists('posix_kill') ? pcntl_fork() : -1;

        if ($pid === 0) {
            $this->animateSpinner($io, (int) $parent);
        }

        if ($pid === -1) {
            $io->write("\e[2m".self::SPINNER_LABEL."\e[0m");

            return;
        }

        $this->spinnerPid = $pid;
    }

    /**
     * Child process loop: redraw the spinner until killed, or kill itself when the session process is gone.
     *
     * @param  SymfonyStyle  $io  Console style.
     * @param  int  $parent  Process id of the session.
     * @return never
     */
    private function animateSpinner(SymfonyStyle $io, int $parent): never
    {
        for ($frame = 0; ; $frame++) {
            if (posix_getppid() !== $parent) {
                posix_kill((int) getmypid(), SIGKILL);
            }

            $glyph = self::SPINNER_FRAMES[$frame % count(self::SPINNER_FRAMES)];
            $io->write(self::CLEAR_LINE."\e[38;5;245m{$glyph} ".self::SPINNER_LABEL."\e[0m");
            usleep(self::SPINNER_FRAME_MICROSECONDS);
        }
    }

    /**
     * Remove the spinner: kill and reap its child process, then clear the line.
     *
     * @param  SymfonyStyle  $io  Console style.
     * @return void
     */
    private function stopSpinner(SymfonyStyle $io): void
    {
        if (! $this->spinnerShown) {
            return;
        }

        if ($this->spinnerPid !== null) {
            posix_kill($this->spinnerPid, SIGKILL);
            pcntl_waitpid($this->spinnerPid, $status);
            $this->spinnerPid = null;
        }

        $io->write(self::CLEAR_LINE);
        $this->spinnerShown = false;
    }

    /**
     * Hide the spinner before each tool call is traced or approved, and show it again while the model works after it.
     *
     * @param  SymfonyStyle  $io  Console style.
     * @return void
     */
    private function watchToolsForSpinner(SymfonyStyle $io): void
    {
        $this->pauseSpinner = function () use ($io): void {
            $this->stopSpinner($io);
        };
        $this->resumeSpinner = function () use ($io): void {
            if ($this->turnRunning) {
                $this->startSpinner($io);
            }
        };

        HookRegistry::on(LifecycleEvent::ToolBefore->value, $this->pauseSpinner, HookRegistry::DEFAULT_PRIORITY - 1);
        HookRegistry::on(LifecycleEvent::ToolAfter->value, $this->resumeSpinner);
        HookRegistry::on(LifecycleEvent::ToolError->value, $this->resumeSpinner);
    }

    /**
     * Remove this command's spinner listeners, never one another module added.
     *
     * @return void
     */
    private function unwatchToolsForSpinner(): void
    {
        if ($this->pauseSpinner === null || $this->resumeSpinner === null) {
            return;
        }

        HookRegistry::off(LifecycleEvent::ToolBefore->value, $this->pauseSpinner);
        HookRegistry::off(LifecycleEvent::ToolAfter->value, $this->resumeSpinner);
        HookRegistry::off(LifecycleEvent::ToolError->value, $this->resumeSpinner);
        $this->pauseSpinner = null;
        $this->resumeSpinner = null;
    }

    /**
     * Run a slash command; it is never sent to the agent.
     *
     * @param  SymfonyStyle  $io  Console style.
     * @param  string  $line  The line, starting with "/".
     * @return bool False when the session should end.
     */
    private function runSlashCommand(SymfonyStyle $io, string $line): bool
    {
        [$command, $argument] = array_pad(preg_split('/\s+/', $line, 2) ?: [], 2, '');

        match ($command) {
            '/exit' => null,
            '/help' => $this->printHelp($io),
            '/tools' => $this->printTools($io),
            '/model' => $this->switchEngine($io, model: $argument),
            '/provider' => $this->switchEngine($io, provider: $argument),
            '/new' => $this->startNewConversation($io),
            '/conv' => $io->writeln("Conversation: {$this->conversation->id}"),
            '/quiet' => $this->toggleTrace($io),
            '/clear' => $io->write("\033[2J\033[H"),
            default => $io->warning("Unknown command {$command}. Type /help for the list."),
        };

        return $command !== '/exit';
    }

    /**
     * Print the engine, the tool count and the conversation id.
     *
     * @param  SymfonyStyle  $io  Console style.
     * @return void
     */
    private function printHeader(SymfonyStyle $io): void
    {
        $io->writeln(sprintf('%s | Tools: %d', $this->engineLine(), count($this->toolNames())));
        $io->writeln("Conversation: {$this->conversation->id}");
        $io->writeln('Type /help for commands, /exit to leave.');
    }

    /**
     * Provider and model of the engine in use, or "?" when the engine is not a phpClaw engine.
     *
     * @return string
     */
    private function engineLine(): string
    {
        return $this->claw instanceof Claw
            ? sprintf('Provider: %s | Model: %s', $this->claw->config()->providerName, $this->claw->config()->model)
            : 'Provider: ? | Model: ?';
    }

    /**
     * Print every slash command with what it does.
     *
     * @param  SymfonyStyle  $io  Console style.
     * @return void
     */
    private function printHelp(SymfonyStyle $io): void
    {
        foreach (self::SLASH_COMMANDS as $command => $effect) {
            $io->writeln(sprintf('  %-18s %s', $command, $effect));
        }
    }

    /**
     * Print the active tool names and their count.
     *
     * @param  SymfonyStyle  $io  Console style.
     * @return void
     */
    private function printTools(SymfonyStyle $io): void
    {
        $names = $this->toolNames();
        $io->writeln(sprintf('%d tools: %s', count($names), $names === [] ? 'none' : implode(', ', $names)));
    }

    /**
     * Names of the tools the engine was built with; none when the engine is not a phpClaw engine.
     *
     * @return list<string>
     */
    private function toolNames(): array
    {
        if (! $this->claw instanceof Claw) {
            return [];
        }

        return array_values(array_map(static fn ($tool): string => $tool->name(), $this->claw->config()->tools));
    }

    /**
     * Switch the model or provider for this session only; settings are never written and a failed switch keeps the engine.
     *
     * @param  SymfonyStyle  $io  Console style.
     * @param  string|null  $provider  New provider, or null when only the model changes.
     * @param  string|null  $model  New model, or null when only the provider changes.
     * @return void
     */
    private function switchEngine(SymfonyStyle $io, ?string $provider = null, ?string $model = null): void
    {
        if (($provider ?? $model) === '') {
            $io->warning($model === null ? 'Usage: /provider <slug>' : 'Usage: /model <name>');

            return;
        }

        try {
            $claw = ($this->agentFactory)($provider ?? $this->sessionProvider, $model ?? $this->sessionModel);
        } catch (AdapterException) {
            $io->warning(sprintf(
                'Cannot switch to %s: the provider name is unknown or its API key is not saved in phpClaw settings.',
                $provider === null ? "model {$model}" : "provider {$provider}",
            ));

            return;
        } catch (PhpClawException $e) {
            $this->reportAgentError($io, $e);

            return;
        }

        $this->claw = $claw;
        $this->sessionProvider = $provider ?? $this->sessionProvider;
        $this->sessionModel = $model ?? $this->sessionModel;

        $io->writeln($model === null ? "Provider for this session: {$provider}" : "Model for this session: {$model}");
        $io->writeln($this->engineLine());
    }

    /**
     * Open a new conversation for the following turns and print its id.
     *
     * @param  SymfonyStyle  $io  Console style.
     * @return void
     */
    private function startNewConversation(SymfonyStyle $io): void
    {
        $this->conversation = $this->claw->conversation();
        $this->rememberConversation($this->conversation->id);
        $io->writeln("New conversation: {$this->conversation->id}");
    }

    /**
     * Turn the tool trace off when it is on, and back on when it is off.
     *
     * @param  SymfonyStyle  $io  Console style.
     * @return void
     */
    private function toggleTrace(SymfonyStyle $io): void
    {
        if ($this->toolTrace !== null) {
            $this->stopToolTrace();
            $io->writeln('Tool trace off.');

            return;
        }

        $this->startToolTrace($io);
        $io->writeln('Tool trace on.');
    }
}

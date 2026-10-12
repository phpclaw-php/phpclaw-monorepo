<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Commands;

use Closure;
use Drush\Attributes as CLI;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Claw;
use PhpClaw\Drupal\Commands\Base\PhpClawDrushCommands;
use PhpClaw\Drupal\Commands\Concerns\RendersToolTrace;
use PhpClaw\Drupal\Commands\Concerns\ReportsAgentTurns;
use PhpClaw\Drupal\Commands\Concerns\TracksLastConversation;
use PhpClaw\Drupal\PhpClawServiceFactory;
use PhpClaw\Drupal\Service\DrupalAgentContext;
use PhpClaw\Exceptions\PhpClawException;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use Symfony\Component\Console\Exception\MissingInputException;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Drush command `drush phpclaw:chat`: an interactive agent session with streamed turns, a tool trace and slash commands.
 */
final class PhpClawChatCommands extends PhpClawDrushCommands
{
    use RendersToolTrace;
    use ReportsAgentTurns;
    use TracksLastConversation;

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

    private const TAGLINE = 'AI agents for Drupal';

    private const SPINNER_FRAMES = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

    private const SPINNER_LABEL = 'Thinking...';

    private const SPINNER_FRAME_MICROSECONDS = 100000;

    private const CLEAR_LINE = "\r\e[2K";

    private Claw $claw;

    private Conversation $conversation;

    private string $sessionProvider = '';

    private string $sessionModel = '';

    private bool $turnRunning = false;

    private bool $spinnerShown = false;

    private ?int $spinnerPid = null;

    private ?Closure $pauseSpinner = null;

    private ?Closure $resumeSpinner = null;

    /**
     * Construct the Drush command with the context the agent is built from.
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
     * Open an interactive phpClaw agent session and run it until /exit or end of input.
     *
     * @param  array<string, mixed>  $options  Drush options: 'conv-id' and 'continue'.
     * @return int Exit code: 0 when the session ends, 1 without a terminal or an agent.
     */
    #[CLI\Command(name: 'phpclaw:chat')]
    #[CLI\Option(name: 'conv-id', description: 'Continue the stored conversation with this id.')]
    #[CLI\Option(name: 'continue', description: 'Continue the last conversation.')]
    #[CLI\Usage(name: 'drush phpclaw:chat', description: 'Open a session in a new conversation.')]
    #[CLI\Usage(name: 'drush phpclaw:chat --continue', description: 'Open a session in the last conversation.')]
    public function chat(array $options = ['conv-id' => self::REQ, 'continue' => false]): int
    {
        $io = new SymfonyStyle($this->input(), $this->output());

        if (! $this->hasTerminal($this->input())) {
            $io->error('phpclaw:chat needs an interactive terminal. For one message use: drush phpclaw:run "<message>"');

            return 1;
        }

        $claw = PhpClawServiceFactory::createForSession($this->context);

        if ($claw === null) {
            $io->error('Agent unavailable. Check the provider and API key in Settings.');

            return 1;
        }

        $this->claw = $claw;
        $this->runSession($io, (string) ($options['conv-id'] ?? ''), continue: (bool) $options['continue']);
        $io->writeln("Resume with: drush phpclaw:chat --conv-id={$this->conversation->id}");

        return 0;
    }

    /**
     * Open the conversation and read turns until /exit or end of input, with the trace and spinner listeners removed after.
     *
     * @param  SymfonyStyle  $io  Console style.
     * @param  string  $conversationId  Id given with `--conv-id`, or ''.
     * @param  bool  $continue  Whether `--continue` was given.
     * @return void
     */
    private function runSession(SymfonyStyle $io, string $conversationId, bool $continue): void
    {
        $this->startToolTrace($io);
        $this->watchToolsForSpinner($io);

        try {
            $this->conversation = $this->openConversation($io, $this->claw, $conversationId, continue: $continue);
            $this->banner($io);
            $this->printHeader($io);
            $this->loop($io);
        } finally {
            $this->stopSpinner($io);
            $this->unwatchToolsForSpinner();
            $this->stopToolTrace();
        }
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
            $io->error($this->agentErrorMessage($e));

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
     * Provider and model of the engine in use.
     *
     * @return string
     */
    private function engineLine(): string
    {
        return sprintf('Provider: %s | Model: %s', $this->claw->config()->providerName, $this->claw->config()->model);
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
     * Names of the tools the engine was built with.
     *
     * @return list<string>
     */
    private function toolNames(): array
    {
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
            $io->warning($model !== null ? 'Usage: /model <name>' : 'Usage: /provider <slug>');

            return;
        }

        $claw = PhpClawServiceFactory::createForSession($this->context, provider: $provider ?? $this->sessionProvider, model: $model ?? $this->sessionModel);

        if ($claw === null) {
            $io->warning(sprintf(
                'Cannot switch to %s: the provider name is unknown or its API key is not saved in phpClaw settings.',
                $provider !== null ? "provider {$provider}" : "model {$model}",
            ));

            return;
        }

        $this->claw = $claw;
        $this->sessionProvider = $provider ?? $this->sessionProvider;
        $this->sessionModel = $model ?? $this->sessionModel;

        $io->writeln($model !== null ? "Model for this session: {$model}" : "Provider for this session: {$provider}");
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

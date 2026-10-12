<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\CLI;

use Closure;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Claw;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Exceptions\PhpClawException;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use PhpClaw\WordPress\CLI\Concerns\RendersToolTrace;
use PhpClaw\WordPress\CLI\Concerns\ReportsAgentTurns;
use PhpClaw\WordPress\CLI\Concerns\ResolvesEngine;
use PhpClaw\WordPress\CLI\Concerns\TracksLastConversation;

/**
 * WP-CLI command `wp phpclaw chat`: an interactive agent session with streamed turns, a tool trace and slash commands.
 */
final class ChatCommand
{
    use RendersToolTrace;
    use ReportsAgentTurns;
    use ResolvesEngine;
    use TracksLastConversation;

    private const PROMPT = 'you > ';

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

    private const TAGLINE = 'AI agents for WordPress';

    private const SPINNER_FRAMES = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

    private const SPINNER_LABEL = 'Thinking...';

    private const SPINNER_FRAME_MICROSECONDS = 100000;

    private const CLEAR_LINE = "\r\e[2K";

    private mixed $input;

    private mixed $output;

    private bool $isTerminal;

    private bool $decorated;

    private PhpClawInterface $engine;

    private Conversation $conversation;

    private string $sessionProvider = '';

    private string $sessionModel = '';

    private bool $turnRunning = false;

    private bool $spinnerShown = false;

    private ?int $spinnerPid = null;

    private ?Closure $pauseSpinner = null;

    private ?Closure $resumeSpinner = null;

    /**
     * Bind the streams the session reads and writes; by default the process's STDIN and STDOUT.
     *
     * @param  mixed  $input  Stream the typed lines are read from; null uses STDIN.
     * @param  mixed  $output  Stream the session writes to; null uses STDOUT.
     * @param  bool|null  $isTerminal  Whether a person types into the input; null asks the input stream.
     * @param  bool|null  $decorated  Whether the output takes colours and the spinner; null asks the output stream.
     * @return void
     */
    public function __construct(mixed $input = null, mixed $output = null, ?bool $isTerminal = null, ?bool $decorated = null)
    {
        $this->input = $input ?? STDIN;
        $this->output = $output ?? STDOUT;
        $this->isTerminal = $isTerminal ?? @stream_isatty($this->input);
        $this->decorated = $decorated ?? @stream_isatty($this->output);
    }

    /**
     * Open an interactive phpClaw agent session.
     *
     * ## OPTIONS
     *
     * [--conv-id=<id>]
     * : Continue the stored conversation with this id.
     *
     * [--continue]
     * : Continue the last conversation.
     *
     * ## EXAMPLES
     *
     *     wp phpclaw chat
     *     wp phpclaw chat --continue
     *
     * @when after_wp_load
     *
     * @param  string[]  $args  Positional arguments (none).
     * @param  string[]  $assocArgs  Named arguments.
     * @return void
     */
    public function __invoke(array $args, array $assocArgs): void
    {
        if (! $this->isTerminal) {
            \WP_CLI::error('wp phpclaw chat needs an interactive terminal. For one message use: wp phpclaw send "<message>"');

            return;
        }

        try {
            $this->engine = $this->resolveEngine();
        } catch (\Throwable $e) {
            \WP_CLI::error($this->agentErrorMessage($e, 'chat'));

            return;
        }

        $this->runSession((string) ($assocArgs['conv-id'] ?? ''), continue: isset($assocArgs['continue']));
        $this->writeLine("Resume with: wp phpclaw chat --conv-id={$this->conversation->id}");
    }

    /**
     * Open the conversation and read turns until /exit or end of input, with the trace and spinner listeners removed after.
     *
     * @param  string  $conversationId  Id given with `--conv-id`, or ''.
     * @param  bool  $continue  Whether `--continue` was given.
     * @return void
     */
    private function runSession(string $conversationId, bool $continue): void
    {
        $this->startToolTrace(fn (string $line) => $this->writeLine($this->dim($line)));
        $this->watchToolsForSpinner();

        try {
            $this->conversation = $this->openConversation(fn (string $line) => $this->writeLine($line), $this->engine, $conversationId, continue: $continue);
            $this->banner();
            $this->printHeader();
            $this->loop();
        } finally {
            $this->stopSpinner();
            $this->unwatchToolsForSpinner();
            $this->stopToolTrace();
        }
    }

    /**
     * Read lines until /exit or end of input; a line starting with / is a command, an empty line does nothing.
     *
     * @return void
     */
    private function loop(): void
    {
        while (($line = $this->readLine()) !== null) {
            if ($line === '') {
                continue;
            }

            if (! str_starts_with($line, '/')) {
                $this->runTurn($line);

                continue;
            }

            if (! $this->runSlashCommand($line)) {
                return;
            }
        }
    }

    /**
     * Show the prompt and read one trimmed line, or null at end of input (Ctrl+D).
     *
     * @return string|null
     */
    private function readLine(): ?string
    {
        $this->write(self::PROMPT);
        $line = fgets($this->input);

        if ($line === false) {
            $this->writeLine('');

            return null;
        }

        return trim($line);
    }

    /**
     * Stream one turn in the session's conversation; a failure is printed and the session goes on.
     *
     * @param  string  $message  What the user typed.
     * @return void
     */
    private function runTurn(string $message): void
    {
        try {
            $turn = $this->streamTurn($message);
        } catch (PhpClawException $e) {
            $this->writeLine('');
            $this->writeLine($this->agentErrorMessage($e, 'chat'));

            return;
        }

        $this->writeLine('');
        $this->conversation = $turn->conversation;
        $this->rememberConversation($turn->conversation->id);
        $this->writeLine($this->dim($this->turnSummary($turn)));
    }

    /**
     * Send one message with the spinner shown until the first word of the reply.
     *
     * @param  string  $message  What the user typed.
     * @return ConversationTurn
     */
    private function streamTurn(string $message): ConversationTurn
    {
        $this->turnRunning = true;
        $this->startSpinner();

        try {
            return $this->engine->streamInConversation(
                $this->conversation,
                $message,
                function (string $token): void {
                    $this->stopSpinner();
                    $this->write($token);
                },
            );
        } finally {
            $this->turnRunning = false;
            $this->stopSpinner();
        }
    }

    /**
     * Show the spinner on a terminal: animated by a child process, or a still line when the process cannot fork.
     *
     * @return void
     */
    private function startSpinner(): void
    {
        if ($this->spinnerShown || ! $this->decorated) {
            return;
        }

        $this->spinnerShown = true;
        $parent = getmypid();
        $pid = function_exists('pcntl_fork') && function_exists('posix_kill') ? pcntl_fork() : -1;

        if ($pid === 0) {
            $this->animateSpinner((int) $parent);
        }

        if ($pid === -1) {
            $this->write("\e[2m".self::SPINNER_LABEL."\e[0m");

            return;
        }

        $this->spinnerPid = $pid;
    }

    /**
     * Child process loop: redraw the spinner until killed, or kill itself when the session process is gone.
     *
     * @param  int  $parent  Process id of the session.
     * @return never
     */
    private function animateSpinner(int $parent): never
    {
        for ($frame = 0; ; $frame++) {
            if (posix_getppid() !== $parent) {
                posix_kill((int) getmypid(), SIGKILL);
            }

            $glyph = self::SPINNER_FRAMES[$frame % count(self::SPINNER_FRAMES)];
            $this->write(self::CLEAR_LINE."\e[38;5;245m{$glyph} ".self::SPINNER_LABEL."\e[0m");
            usleep(self::SPINNER_FRAME_MICROSECONDS);
        }
    }

    /**
     * Remove the spinner: kill and reap its child process, then clear the line.
     *
     * @return void
     */
    private function stopSpinner(): void
    {
        if (! $this->spinnerShown) {
            return;
        }

        if ($this->spinnerPid !== null) {
            posix_kill($this->spinnerPid, SIGKILL);
            pcntl_waitpid($this->spinnerPid, $status);
            $this->spinnerPid = null;
        }

        $this->write(self::CLEAR_LINE);
        $this->spinnerShown = false;
    }

    /**
     * Hide the spinner before each tool call is traced or approved, and show it again while the model works after it.
     *
     * @return void
     */
    private function watchToolsForSpinner(): void
    {
        $this->pauseSpinner = function (): void {
            $this->stopSpinner();
        };
        $this->resumeSpinner = function (): void {
            if ($this->turnRunning) {
                $this->startSpinner();
            }
        };

        HookRegistry::on(LifecycleEvent::ToolBefore->value, $this->pauseSpinner, HookRegistry::DEFAULT_PRIORITY - 1);
        HookRegistry::on(LifecycleEvent::ToolAfter->value, $this->resumeSpinner);
        HookRegistry::on(LifecycleEvent::ToolError->value, $this->resumeSpinner);
    }

    /**
     * Remove this command's spinner listeners, never one another plugin added.
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
     * @param  string  $line  The line, starting with "/".
     * @return bool False when the session should end.
     */
    private function runSlashCommand(string $line): bool
    {
        [$command, $argument] = array_pad(preg_split('/\s+/', $line, 2) ?: [], 2, '');

        match ($command) {
            '/exit' => null,
            '/help' => $this->printHelp(),
            '/tools' => $this->printTools(),
            '/model' => $this->switchEngine(model: $argument),
            '/provider' => $this->switchEngine(provider: $argument),
            '/new' => $this->startNewConversation(),
            '/conv' => $this->writeLine("Conversation: {$this->conversation->id}"),
            '/quiet' => $this->toggleTrace(),
            '/clear' => $this->write("\033[2J\033[H"),
            default => $this->writeLine("Unknown command {$command}. Type /help for the list."),
        };

        return $command !== '/exit';
    }

    /**
     * Print the phpClaw wordmark and tagline, coloured on a decorated terminal and plain otherwise; no version.
     *
     * @return void
     */
    private function banner(): void
    {
        foreach (explode("\n", self::LOGO) as $i => $line) {
            $color = self::LOGO_GRADIENT[$i] ?? 226;
            $this->writeLine($this->decorated ? "\e[38;5;{$color}m{$line}\e[0m" : $line);
        }

        $this->writeLine($this->dim(self::TAGLINE));
        $this->writeLine('');
    }

    /**
     * Print the engine, the tool count and the conversation id.
     *
     * @return void
     */
    private function printHeader(): void
    {
        $this->writeLine(sprintf('%s | Tools: %d', $this->engineLine(), count($this->toolNames())));
        $this->writeLine("Conversation: {$this->conversation->id}");
        $this->writeLine('Type /help for commands, /exit to leave.');
    }

    /**
     * Provider and model of the engine in use, or "?" when the engine is not a phpClaw engine.
     *
     * @return string
     */
    private function engineLine(): string
    {
        return $this->engine instanceof Claw
            ? sprintf('Provider: %s | Model: %s', $this->engine->config()->providerName, $this->engine->config()->model)
            : 'Provider: ? | Model: ?';
    }

    /**
     * Print every slash command with what it does.
     *
     * @return void
     */
    private function printHelp(): void
    {
        foreach (self::SLASH_COMMANDS as $command => $effect) {
            $this->writeLine(sprintf('  %-18s %s', $command, $effect));
        }
    }

    /**
     * Print the active tool names and their count.
     *
     * @return void
     */
    private function printTools(): void
    {
        $names = $this->toolNames();
        $this->writeLine(sprintf('%d tools: %s', count($names), $names === [] ? 'none' : implode(', ', $names)));
    }

    /**
     * Names of the tools the engine was built with; none when the engine is not a phpClaw engine.
     *
     * @return list<string>
     */
    private function toolNames(): array
    {
        if (! $this->engine instanceof Claw) {
            return [];
        }

        return array_values(array_map(static fn ($tool): string => $tool->name(), $this->engine->config()->tools));
    }

    /**
     * Switch the model or provider for this session only; settings are never written and a failed switch keeps the engine.
     *
     * @param  string|null  $provider  New provider, or null when only the model changes.
     * @param  string|null  $model  New model, or null when only the provider changes.
     * @return void
     */
    private function switchEngine(?string $provider = null, ?string $model = null): void
    {
        if (($provider ?? $model) === '') {
            $this->writeLine($model !== null ? 'Usage: /model <name>' : 'Usage: /provider <slug>');

            return;
        }

        try {
            $engine = $this->resolveEngine($provider ?? $this->sessionProvider, $model ?? $this->sessionModel);
        } catch (\Throwable) {
            $this->writeLine(sprintf(
                'Cannot switch to %s: the provider name is unknown or its API key is not saved in phpClaw settings.',
                $provider !== null ? "provider {$provider}" : "model {$model}",
            ));

            return;
        }

        $this->engine = $engine;
        $this->sessionProvider = $provider ?? $this->sessionProvider;
        $this->sessionModel = $model ?? $this->sessionModel;

        $this->writeLine($model !== null ? "Model for this session: {$model}" : "Provider for this session: {$provider}");
        $this->writeLine($this->engineLine());
    }

    /**
     * Open a new conversation for the following turns and print its id.
     *
     * @return void
     */
    private function startNewConversation(): void
    {
        $this->conversation = $this->engine->conversation();
        $this->rememberConversation($this->conversation->id);
        $this->writeLine("New conversation: {$this->conversation->id}");
    }

    /**
     * Turn the tool trace off when it is on, and back on when it is off.
     *
     * @return void
     */
    private function toggleTrace(): void
    {
        if ($this->toolTrace !== null) {
            $this->stopToolTrace();
            $this->writeLine('Tool trace off.');

            return;
        }

        $this->startToolTrace(fn (string $line) => $this->writeLine($this->dim($line)));
        $this->writeLine('Tool trace on.');
    }

    /**
     * Grey text on a decorated terminal, plain text otherwise.
     *
     * @param  string  $text  Text to dim.
     * @return string
     */
    private function dim(string $text): string
    {
        return $this->decorated ? "\e[2m{$text}\e[0m" : $text;
    }

    /**
     * Write one line to the session output.
     *
     * @param  string  $text  Line without its newline.
     * @return void
     */
    private function writeLine(string $text): void
    {
        $this->write($text.PHP_EOL);
    }

    /**
     * Write text to the session output as it is.
     *
     * @param  string  $text  Text to write.
     * @return void
     */
    private function write(string $text): void
    {
        fwrite($this->output, $text);
    }

    /**
     * Provider, model, tokens, the tools the turn used (when any) and the conversation id.
     *
     * @param  ConversationTurn  $turn  The finished turn.
     * @return string
     */
    private function turnSummary(ConversationTurn $turn): string
    {
        $response = $turn->response;
        $summary = sprintf(
            'Provider: %s | Model: %s | Tokens: %s→%s',
            $response->provider,
            $response->model,
            $response->inputTokens ?? '?',
            $response->outputTokens ?? '?',
        );

        if ($response->usedTools()) {
            $summary .= ' | Tools: '.implode(', ', $response->uniqueToolsCalled());
        }

        return $summary.' | Conversation: '.$turn->conversation->id;
    }
}

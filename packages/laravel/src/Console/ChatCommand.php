<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Console;

use Closure;
use Illuminate\Console\Command;
use Laravel\Prompts\SuggestPrompt;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Exceptions\PhpClawException;
use PhpClaw\Exceptions\RunSuspendedException;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use PhpClaw\Laravel\Console\Concerns\RendersBanner;
use PhpClaw\Laravel\Console\Concerns\RendersToolTrace;
use PhpClaw\Laravel\Console\Concerns\ReportsAgentTurns;
use PhpClaw\Laravel\Console\Concerns\TracksLastConversation;
use PhpClaw\Laravel\Engine\EngineFactory;
use Symfony\Component\Console\Exception\MissingInputException;
use Symfony\Component\Console\Input\ArgvInput;

/**
 * Artisan command `php artisan phpclaw:chat`, an interactive agent session: one conversation, streamed turns,
 * a live tool trace and slash commands.
 */
final class ChatCommand extends Command
{
    use RendersBanner;
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

    private const SPINNER_FRAMES = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

    private const SPINNER_LABEL = 'Thinking...';

    private const SPINNER_FRAME_MICROSECONDS = 100000;

    private const CLEAR_LINE = "\r\e[2K";

    private const END_OF_INPUT_KEY = "\x04";

    private const EXIT_WORDS = ['exit', 'quit'];

    protected $signature = 'phpclaw:chat
        {--conv-id= : Continue the stored conversation with this id}
        {--continue : Continue the last conversation}
        {--provider= : Use this LLM provider for the session}
        {--model= : Use this model for the session}';

    protected $description = 'Open an interactive phpClaw agent session';

    private PhpClawInterface $claw;

    private Conversation $conversation;

    private bool $turnRunning = false;

    private bool $spinnerShown = false;

    private ?int $spinnerPid = null;

    private ?Closure $pauseSpinner = null;

    private ?Closure $resumeSpinner = null;

    /**
     * Run the session until /exit or end of input.
     *
     * @return int Artisan exit code.
     */
    public function handle(): int
    {
        if (! $this->hasTerminal()) {
            $this->error('phpclaw:chat needs an interactive terminal. For one message use: php artisan phpclaw "<message>"');

            return self::FAILURE;
        }

        if ((string) $this->option('provider') !== '') {
            config(['phpclaw.provider' => (string) $this->option('provider')]);
        }

        if ((string) $this->option('model') !== '') {
            config(['phpclaw.model' => (string) $this->option('model')]);
        }

        if (! $this->startToolTrace()) {
            return self::FAILURE;
        }

        $this->watchToolsForSpinner();

        try {
            $this->claw = $this->laravel->make(PhpClawInterface::class);
            $this->conversation = $this->openConversation($this->claw, (string) $this->option('conv-id'), (bool) $this->option('continue'));
            $this->banner();
            $this->printHeader();
            $this->loop();
        } finally {
            $this->stopSpinner();
            $this->unwatchToolsForSpinner();
            $this->stopToolTrace();
        }

        $this->line("Resume with: php artisan phpclaw:chat --conv-id={$this->conversation->id}");

        return self::SUCCESS;
    }

    /**
     * Whether a person can type into this session: interactive input and, for a command line (`ArgvInput`), a terminal on STDIN.
     *
     * @return bool
     */
    private function hasTerminal(): bool
    {
        if (! $this->input->isInteractive()) {
            return false;
        }

        return $this->input::class !== ArgvInput::class || (defined('STDIN') && @stream_isatty(STDIN));
    }

    /**
     * Read lines until /exit, exit, quit or end of input; a line starting with / is a command, an empty line does nothing.
     *
     * @return void
     */
    private function loop(): void
    {
        while (($line = $this->readLine()) !== null) {
            if ($line === '') {
                continue;
            }

            if (in_array(strtolower($line), self::EXIT_WORDS, true)) {
                return;
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
     * Read one trimmed line, or null at end of input (Ctrl+D).
     *
     * @return string|null
     */
    private function readLine(): ?string
    {
        if ($this->hasSlashMenu()) {
            return $this->askWithSlashMenu();
        }

        try {
            return trim((string) $this->ask(self::PROMPT));
        } catch (MissingInputException) {
            return null;
        }
    }

    /**
     * Whether the prompt can show the slash command menu: a real command line, not Windows.
     *
     * @return bool
     */
    private function hasSlashMenu(): bool
    {
        return $this->input::class === ArgvInput::class && PHP_OS_FAMILY !== 'Windows';
    }

    /**
     * Read one trimmed line with a menu of slash commands under the cursor, or null for Ctrl+D on an empty line.
     *
     * @return string|null
     */
    private function askWithSlashMenu(): ?string
    {
        $prompt = new SuggestPrompt(
            label: self::PROMPT,
            options: fn (string $typed): array => $this->slashSuggestions($typed),
            scroll: count(self::SLASH_COMMANDS),
        );
        $endOfInput = false;

        $prompt->on('key', static function (string $key) use ($prompt, &$endOfInput): void {
            if ($key === self::END_OF_INPUT_KEY && $prompt->value() === '') {
                $endOfInput = true;
                $prompt->state = 'submit';
            }
        });

        $line = trim((string) $prompt->prompt());

        return $endOfInput ? null : $line;
    }

    /**
     * Slash command names that start with what was typed; none for a message or once an argument is typed.
     *
     * @param  string  $typed  The text typed so far.
     * @return list<string>
     */
    private function slashSuggestions(string $typed): array
    {
        if (! str_starts_with($typed, '/') || str_contains($typed, ' ')) {
            return [];
        }

        $names = array_map(static fn (string $usage): string => explode(' ', $usage)[0], array_keys(self::SLASH_COMMANDS));

        return array_values(array_filter($names, static fn (string $name): bool => str_starts_with($name, $typed)));
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
            $this->line('');
            $e instanceof RunSuspendedException ? $this->reportStoppedRun($e) : $this->reportAgentError($e);

            return;
        }

        $this->line('');
        $this->conversation = $turn->conversation;
        $this->rememberConversation($turn->conversation->id);
        $this->comment($this->turnSummary($turn));
    }

    /**
     * Send one message with the spinner shown until the first word of the reply.
     *
     * @param  string  $message  What the user typed.
     * @return ConversationTurn
     *
     * @throws PhpClawException When the run fails or stops.
     */
    private function streamTurn(string $message): ConversationTurn
    {
        $this->turnRunning = true;
        $this->startSpinner();

        try {
            return $this->claw->streamInConversation(
                $this->conversation,
                $message,
                function (string $token): void {
                    $this->stopSpinner();
                    $this->output->write($token);
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
        if ($this->spinnerShown || ! $this->output->isDecorated()) {
            return;
        }

        $this->spinnerShown = true;
        $parent = getmypid();
        $pid = function_exists('pcntl_fork') && function_exists('posix_kill') ? pcntl_fork() : -1;

        if ($pid === 0) {
            $this->animateSpinner((int) $parent);
        }

        if ($pid === -1) {
            $this->output->write("\e[2m".self::SPINNER_LABEL."\e[0m");

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
            $this->output->write(self::CLEAR_LINE."\e[38;5;245m{$glyph} ".self::SPINNER_LABEL."\e[0m");
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

        $this->output->write(self::CLEAR_LINE);
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
     * Remove this command's spinner listeners, never the host application's.
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
            '/model' => $this->switchSetting('model', 'Model', $argument),
            '/provider' => $this->switchSetting('provider', 'Provider', $argument),
            '/new' => $this->startNewConversation(),
            '/conv' => $this->line("Conversation: {$this->conversation->id}"),
            '/quiet' => $this->toggleTrace(),
            '/clear' => $this->output->write("\033[2J\033[H"),
            default => $this->warn("Unknown command {$command}. Type /help for the list."),
        };

        return $command !== '/exit';
    }

    /**
     * Print the provider, model, tool count, memory driver, workspace and conversation id.
     *
     * @return void
     */
    private function printHeader(): void
    {
        $this->line(sprintf(
            'Provider: %s | Model: %s | Tools: %d | Memory: %s | Workspace: %s',
            (string) config('phpclaw.provider', ''),
            (string) config('phpclaw.model', ''),
            count($this->toolNames()),
            (string) config('phpclaw.memory_driver', 'database'),
            (string) config('phpclaw.workspace_root', storage_path('phpclaw')),
        ));
        $this->line("Conversation: {$this->conversation->id}");
        $this->line('Type /help for commands, /exit to leave.');
    }

    /**
     * Print every slash command with what it does.
     *
     * @return void
     */
    private function printHelp(): void
    {
        foreach (self::SLASH_COMMANDS as $command => $effect) {
            $this->line(sprintf('  %-18s %s', $command, $effect));
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
        $this->line(sprintf('%d tools: %s', count($names), $names === [] ? 'none' : implode(', ', $names)));
    }

    /**
     * Names of the tools the engine is built with.
     *
     * @return list<string>
     */
    private function toolNames(): array
    {
        return array_values(array_map(static fn ($tool): string => $tool->name(), EngineFactory::resolveTools($this->laravel)));
    }

    /**
     * Change the model or provider for this process only and rebuild the agent; config files and .env are never written.
     *
     * @param  string  $key  Config key under phpclaw: "model" or "provider".
     * @param  string  $label  Word shown to the user.
     * @param  string  $value  New value; empty prints the usage line.
     * @return void
     */
    private function switchSetting(string $key, string $label, string $value): void
    {
        if ($value === '') {
            $this->warn($key === 'model' ? 'Usage: /model <name>' : 'Usage: /provider <slug>');

            return;
        }

        config(["phpclaw.{$key}" => $value]);
        $this->laravel->forgetInstance(PhpClawInterface::class);
        $this->claw = $this->laravel->make(PhpClawInterface::class);
        $this->info("{$label} for this session: {$value}");
    }

    /**
     * Open a new conversation for the following turns and print its id.
     *
     * @return void
     */
    private function startNewConversation(): void
    {
        $this->conversation = $this->claw->conversation();
        $this->rememberConversation($this->conversation->id);
        $this->line("New conversation: {$this->conversation->id}");
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
            $this->line('Tool trace off.');

            return;
        }

        $this->startToolTrace();
        $this->line('Tool trace on.');
    }
}

<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\CLI;

use Closure;
use PhpClaw\Agent\Conversation;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Exceptions\TokenBudgetExceededException;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use PhpClaw\OpenCart\Admin\AdminResponder;
use PhpClaw\OpenCart\CLI\Concerns\RendersToolTrace;
use PhpClaw\OpenCart\CLI\Concerns\TracksLastConversation;
use PhpClaw\OpenCart\Plugin;
use PhpClaw\OpenCart\Support\ToolHistorySplicer;

/**
 * CLI "send" command for phpClaw in OpenCart 3/4.
 */
final class PhpClawCommand
{
    use RendersToolTrace;
    use TracksLastConversation;

    /**
     * Bind the plugin singleton, the exit handler and the storage directory for the last conversation id.
     *
     * @param  Plugin  $plugin  Bootstrapped plugin singleton.
     * @param  callable|null  $exitHandler  Overridable exit function, defaults to exit().
     * @param  string  $storageDirectory  Folder for the last conversation id; '' uses DIR_STORAGE when defined.
     * @return void
     */
    public function __construct(
        private readonly Plugin $plugin,
        private readonly mixed $exitHandler = null,
        private readonly string $storageDirectory = '',
    ) {}

    /**
     * Execute the send command with optional conversation resume and tool trace flags.
     *
     * @param  string[]  $args  Positional args from CLI (message at index 0).
     * @param  array<string, string>  $flags  Parsed --key=value flags.
     * @return void
     */
    public function run(array $args, array $flags): void
    {
        $message = trim($args[0] ?? '');

        if ($message === '') {
            fwrite(STDERR, "phpClaw: message is required.\n");
            fwrite(STDERR, "Usage: php phpclaw.php send \"your prompt here\"\n");
            $this->halt(1);

            return;
        }

        $stream = isset($flags['stream']);

        try {
            $engine = $this->resolveEngine($flags);
        } catch (\Throwable $e) {
            fwrite(STDERR, "phpClaw: failed to build engine. {$e->getMessage()}\n");
            $this->halt(1);

            return;
        }

        $convId = $flags['conv-id'] ?? '';
        $continue = isset($flags['continue']);
        $trace = isset($flags['trace']);

        $conversation = $this->openConversation(
            static function (string $msg): void {
                fwrite(STDERR, "phpClaw: {$msg}\n");
            },
            $engine,
            $convId,
            continue: $continue,
        );

        if ($stream) {
            $this->runStream($engine, $message, $conversation, $trace);
        } else {
            $this->runSync($engine, $message, $conversation, $trace);
        }
    }

    /**
     * Send a prompt synchronously and print the assistant reply to STDOUT.
     *
     * @param  ClawInterface  $engine  Built engine instance.
     * @param  string  $message  Prompt text supplied by the caller.
     * @param  Conversation|null  $conversation  Conversation to continue; null creates a new one.
     * @param  bool  $trace  When true, one line per tool call is printed to STDERR.
     * @return void
     */
    private function runSync(ClawInterface $engine, string $message, ?Conversation $conversation = null, bool $trace = false): void
    {
        $this->startStderrTrace($trace);
        $collectedToolCalls = [];
        $toolAfterListener = $this->collectToolCalls($collectedToolCalls);

        try {
            $turn = $engine->streamInConversation(
                $conversation ?? $engine->conversation(),
                $message,
                static function (string $token): void {},
                static function (array $payload) use (&$collectedToolCalls): array {
                    return ToolHistorySplicer::splice($payload, $collectedToolCalls);
                },
            );
            $response = $turn->response;

            echo $response->text."\n";
            fwrite(STDERR, sprintf(
                "\n[provider:%s model:%s tokens:%d iterations:%d conversation:%s]\n",
                $response->provider,
                $response->model,
                ($response->inputTokens ?? 0) + ($response->outputTokens ?? 0),
                $response->iterations,
                $turn->conversation->id,
            ));
            $this->rememberConversation($turn->conversation->id);
        } catch (GuardException|ProviderException|TokenBudgetExceededException|MaxIterationsException $e) {
            $this->reportFailure($e, '');
        } finally {
            $this->stopToolTrace();
            HookRegistry::off(LifecycleEvent::ToolAfter->value, $toolAfterListener);
        }
    }

    /**
     * Stream a prompt and write each token to STDOUT as it arrives.
     *
     * @param  ClawInterface  $engine  Built engine instance.
     * @param  string  $message  Prompt text supplied by the caller.
     * @param  Conversation|null  $conversation  Conversation to continue; null creates a new one.
     * @param  bool  $trace  When true, one line per tool call is printed to STDERR.
     * @return void
     */
    private function runStream(ClawInterface $engine, string $message, ?Conversation $conversation = null, bool $trace = false): void
    {
        $this->startStderrTrace($trace);
        $collectedToolCalls = [];
        $toolAfterListener = $this->collectToolCalls($collectedToolCalls);

        try {
            $turn = $engine->streamInConversation(
                $conversation ?? $engine->conversation(),
                $message,
                $this->writeToken(...),
                static function (array $payload) use (&$collectedToolCalls): array {
                    return ToolHistorySplicer::splice($payload, $collectedToolCalls);
                },
            );

            echo "\n";
            $this->rememberConversation($turn->conversation->id);
        } catch (GuardException|ProviderException|TokenBudgetExceededException|MaxIterationsException $e) {
            $this->reportFailure($e, "\n");
        } finally {
            $this->stopToolTrace();
            HookRegistry::off(LifecycleEvent::ToolAfter->value, $toolAfterListener);
        }
    }

    /**
     * Echo one streamed token and flush it to the terminal straight away.
     *
     * @param  string  $token  Token from the provider stream.
     * @return void
     */
    private function writeToken(string $token): void
    {
        echo $token;

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }

    /**
     * Print one line per tool call on STDERR when the caller asked for the trace.
     *
     * @param  bool  $trace  Whether `--trace` was given.
     * @return void
     */
    private function startStderrTrace(bool $trace): void
    {
        if (! $trace) {
            return;
        }

        $this->startToolTrace(static function (string $line): void {
            fwrite(STDERR, $line."\n");
        });
    }

    /**
     * Register a ToolAfter listener that appends each named tool call to $collected, and return it for removal.
     *
     * @param  list<array{tool_name: string, tool_input: array<mixed>, tool_result: string}>  $collected  Calls collected for the history splice.
     * @return Closure
     */
    private function collectToolCalls(array &$collected): Closure
    {
        $listener = static function (array $ctx) use (&$collected): void {
            $toolName = (string) ($ctx['tool_name'] ?? '');

            if ($toolName === '') {
                return;
            }

            $collected[] = [
                'tool_name' => $toolName,
                'tool_input' => (array) ($ctx['tool_input'] ?? []),
                'tool_result' => (string) ($ctx['tool_result'] ?? ''),
            ];
        };
        HookRegistry::on(LifecycleEvent::ToolAfter->value, $listener);

        return $listener;
    }

    /**
     * Print the failure line the send command has always printed for this exception type, then halt with exit 1.
     *
     * @param  GuardException|ProviderException|TokenBudgetExceededException|MaxIterationsException  $e  The failure.
     * @param  string  $lead  Text written before the line: "\n" after streamed tokens, '' otherwise.
     * @return void
     */
    private function reportFailure(GuardException|ProviderException|TokenBudgetExceededException|MaxIterationsException $e, string $lead): void
    {
        fwrite(STDERR, $lead.'phpClaw: '.match (true) {
            $e instanceof GuardException => "prompt blocked. {$e->getMessage()}",
            $e instanceof MaxIterationsException => "max iterations reached. {$e->getMessage()}",
            default => AdminResponder::limitMessage($e) ?? "provider error. {$e->getMessage()}",
        }."\n");
        $this->halt(1);
    }

    /**
     * Resolve the engine with owner_id = 0 unconditionally (CLI has no authenticated user).
     *
     * @param  array<string, string>  $flags
     * @return ClawInterface
     */
    private function resolveEngine(array $flags): ClawInterface
    {
        if (! isset($flags['provider']) && ! isset($flags['model'])) {
            return $this->plugin->buildScopedEngine(0, false, callerMayUseModule: true, mayQueryRaw: true);
        }

        return $this->plugin->buildScopedEngineWithOverrides(0, false, callerMayUseModule: true, mayQueryRaw: true, overrides: [
            'provider' => $flags['provider'] ?? '',
            'model' => $flags['model'] ?? '',
        ]);
    }

    /**
     * Terminate the process via the injected exit handler, or the built-in exit().
     *
     * @param  int  $code  Exit status code.
     * @return void
     */
    private function halt(int $code): void
    {
        if ($this->exitHandler !== null) {
            ($this->exitHandler)($code);

            return;
        }
        exit($code);
    }
}

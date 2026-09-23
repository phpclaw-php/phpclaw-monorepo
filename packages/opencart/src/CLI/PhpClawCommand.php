<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\CLI;

use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use PhpClaw\OpenCart\Plugin;
use PhpClaw\OpenCart\Support\ToolHistorySplicer;

/**
 * CLI "send" command for phpClaw in OpenCart 3/4.
 */
final class PhpClawCommand
{
    /**
     * Bind the plugin singleton and the exit handler this command terminates through.
     *
     * @param  Plugin  $plugin  Bootstrapped plugin singleton.
     * @param  callable|null  $exitHandler  Overridable exit function, defaults to exit().
     *                                      Inject a no-op in tests to prevent process termination.
     */
    public function __construct(
        private readonly Plugin $plugin,
        private readonly mixed $exitHandler = null,
    ) {}

    /**
     * Execute the send command.
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

        if ($stream) {
            $this->runStream($engine, $message);
        } else {
            $this->runSync($engine, $message);
        }
    }

    /**
     * Send a prompt synchronously and print the assistant reply to STDOUT, via
     * streamInConversation with a discard handler to gain the beforePersist hook.
     *
     * @param  ClawInterface  $engine  Built engine instance.
     * @param  string  $message  Prompt text supplied by the caller.
     * @return void
     */
    private function runSync(ClawInterface $engine, string $message): void
    {
        $collectedToolCalls = [];
        HookRegistry::on(LifecycleEvent::ToolAfter->value, static function (array $ctx) use (&$collectedToolCalls): void {
            $entry = [
                'tool_name' => (string) ($ctx['tool_name'] ?? ''),
                'tool_input' => (array) ($ctx['tool_input'] ?? []),
                'tool_result' => (string) ($ctx['tool_result'] ?? ''),
            ];
            if ($entry['tool_name'] !== '') {
                $collectedToolCalls[] = $entry;
            }
        });

        try {
            $conversation = $engine->conversation();
            $turn = $engine->streamInConversation(
                $conversation,
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
        } catch (GuardException $e) {
            fwrite(STDERR, "phpClaw: prompt blocked. {$e->getMessage()}\n");
            $this->halt(1);
        } catch (ProviderException $e) {
            fwrite(STDERR, "phpClaw: provider error. {$e->getMessage()}\n");
            $this->halt(1);
        } catch (MaxIterationsException $e) {
            fwrite(STDERR, "phpClaw: max iterations reached. {$e->getMessage()}\n");
            $this->halt(1);
        }
    }

    /**
     * Stream a prompt and write each token to STDOUT as it arrives.
     *
     * @param  ClawInterface  $engine  Built engine instance.
     * @param  string  $message  Prompt text supplied by the caller.
     * @return void
     */
    private function runStream(ClawInterface $engine, string $message): void
    {
        $collectedToolCalls = [];
        HookRegistry::on(LifecycleEvent::ToolAfter->value, static function (array $ctx) use (&$collectedToolCalls): void {
            $entry = [
                'tool_name' => (string) ($ctx['tool_name'] ?? ''),
                'tool_input' => (array) ($ctx['tool_input'] ?? []),
                'tool_result' => (string) ($ctx['tool_result'] ?? ''),
            ];
            if ($entry['tool_name'] !== '') {
                $collectedToolCalls[] = $entry;
            }
        });

        try {
            $conversation = $engine->conversation();
            $engine->streamInConversation(
                $conversation,
                $message,
                function (string $token): void {
                    echo $token;
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                },
                static function (array $payload) use (&$collectedToolCalls): array {
                    return ToolHistorySplicer::splice($payload, $collectedToolCalls);
                },
            );

            echo "\n";
        } catch (GuardException $e) {
            fwrite(STDERR, "\nphpClaw: prompt blocked. {$e->getMessage()}\n");
            $this->halt(1);
        } catch (ProviderException $e) {
            fwrite(STDERR, "\nphpClaw: provider error. {$e->getMessage()}\n");
            $this->halt(1);
        } catch (MaxIterationsException $e) {
            fwrite(STDERR, "\nphpClaw: max iterations reached. {$e->getMessage()}\n");
            $this->halt(1);
        }
    }

    /**
     * Resolve the engine with owner_id = 0 unconditionally (CLI has no authenticated user).
     *
     * Provider/model flags are applied in-memory via buildScopedEngineWithOverrides.
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

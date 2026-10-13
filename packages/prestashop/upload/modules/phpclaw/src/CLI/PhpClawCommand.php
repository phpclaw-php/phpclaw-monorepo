<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\CLI;

use PhpClaw\Agent\Conversation;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Exceptions\TokenBudgetExceededException;
use PhpClaw\PrestaShop\CLI\Concerns\RendersToolTrace;
use PhpClaw\PrestaShop\CLI\Concerns\TracksLastConversation;
use PhpClaw\PrestaShop\Plugin;
use PhpClaw\PrestaShop\Rest\ApiHandler;

/**
 * CLI `send` command handler for phpClaw in PrestaShop.
 */
final class PhpClawCommand
{
    use RendersToolTrace;
    use TracksLastConversation;

    private mixed $exitFn;

    /**
     * Create a new PhpClawCommand instance.
     *
     * @param  Plugin  $plugin  Module plugin that holds the engine.
     * @param  callable|null  $exitFn  Overridable exit handler; defaults to the built-in exit().
     * @return void
     */
    public function __construct(
        private readonly Plugin $plugin,
        ?callable $exitFn = null,
    ) {
        $this->exitFn = $exitFn ?? static function (int $code): never {
            exit($code);
        };
    }

    /**
     * Execute the send command: resolve the engine and conversation, then print the reply.
     *
     * @param  string[]  $args  Positional args (message at index 0).
     * @param  array<string, string>  $flags  Parsed --key=value flags.
     * @return void
     */
    public function run(array $args, array $flags): void
    {
        $message = trim($args[0] ?? '');

        if ($message === '') {
            fwrite(STDERR, "phpClaw: message is required.\n");
            fwrite(STDERR, "Usage: php modules/phpclaw/cli/phpclaw.php send \"your prompt here\"\n");
            ($this->exitFn)(1);

            return;
        }

        $stream = isset($flags['stream']);

        if (isset($flags['trace'])) {
            $this->startToolTrace(static function (string $line): void {
                fwrite(STDERR, $line."\n");
            });
        }

        try {
            $this->sendWithEngine($flags, $message, stream: $stream);
        } finally {
            $this->stopToolTrace();
        }
    }

    /**
     * Resolve the engine and the conversation, then send the message synchronously or streamed.
     *
     * @param  array<string, string>  $flags  Parsed flags.
     * @param  string  $message  The prompt.
     * @param  bool  $stream  Whether to stream the reply.
     * @return void
     */
    private function sendWithEngine(array $flags, string $message, bool $stream): void
    {
        try {
            $engine = $this->resolveEngine($flags);
        } catch (\Throwable $e) {
            fwrite(STDERR, "phpClaw: failed to build engine: {$e->getMessage()}\n");
            ($this->exitFn)(1);

            return;
        }

        $conversation = $this->openConversation(
            static function (string $line): void {
                fwrite(STDERR, "phpClaw: {$line}\n");
            },
            $engine,
            (string) ($flags['conv-id'] ?? ''),
            continue: isset($flags['continue']),
        );

        if ($stream) {
            $this->runStream($engine, $conversation, $message);

            return;
        }

        $this->runSync($engine, $conversation, $message);
    }

    /**
     * Send the message synchronously and print the full response to stdout.
     *
     * @param  ClawInterface  $engine  The resolved phpClaw engine.
     * @param  Conversation  $conversation  The conversation to continue.
     * @param  string  $message  The prompt.
     * @return void
     */
    private function runSync(ClawInterface $engine, Conversation $conversation, string $message): void
    {
        try {
            $result = (new ApiHandler($engine))->handle($message, $conversation->id);

            echo $result['text']."\n";
            fwrite(STDERR, sprintf(
                "\n[provider:%s model:%s tokens:%d iterations:%d conversation:%s]\n",
                $result['provider'],
                $result['model'],
                $result['tokens'],
                $result['iterations'],
                $result['conversation_id'],
            ));
            $this->rememberConversation($result['conversation_id']);
        } catch (GuardException $e) {
            fwrite(STDERR, "phpClaw: prompt blocked: {$e->getMessage()}\n");
            ($this->exitFn)(1);
        } catch (ProviderException|TokenBudgetExceededException $e) {
            fwrite(STDERR, 'phpClaw: '.(ApiHandler::limitMessage($e) ?? "provider error: {$e->getMessage()}")."\n");
            ($this->exitFn)(1);
        } catch (MaxIterationsException $e) {
            fwrite(STDERR, "phpClaw: max iterations reached: {$e->getMessage()}\n");
            ($this->exitFn)(1);
        }
    }

    /**
     * Send the message and print streamed tokens to stdout as they arrive, then a summary line to stderr.
     *
     * @param  ClawInterface  $engine  The resolved phpClaw engine.
     * @param  Conversation  $conversation  The conversation to continue.
     * @param  string  $message  The prompt.
     * @return void
     */
    private function runStream(ClawInterface $engine, Conversation $conversation, string $message): void
    {
        try {
            $turn = $engine->streamInConversation(
                $conversation,
                $message,
                function (string $token): void {
                    echo $token;
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                },
            );

            echo "\n";
            fwrite(STDERR, sprintf("\n[conversation:%s]\n", $turn->conversation->id));
            $this->rememberConversation($turn->conversation->id);
        } catch (GuardException $e) {
            fwrite(STDERR, "\nphpClaw: prompt blocked: {$e->getMessage()}\n");
            ($this->exitFn)(1);
        } catch (ProviderException|TokenBudgetExceededException $e) {
            fwrite(STDERR, "\nphpClaw: ".(ApiHandler::limitMessage($e) ?? "provider error: {$e->getMessage()}")."\n");
            ($this->exitFn)(1);
        } catch (MaxIterationsException $e) {
            fwrite(STDERR, "\nphpClaw: max iterations reached: {$e->getMessage()}\n");
            ($this->exitFn)(1);
        }
    }

    /**
     * Resolve the engine, rebuilding it only when a provider or model flag overrides the saved settings.
     *
     * @param  array<string, string>  $flags  Parsed flags.
     * @return ClawInterface
     */
    private function resolveEngine(array $flags): ClawInterface
    {
        if (! isset($flags['provider']) && ! isset($flags['model'])) {
            return $this->plugin->engine();
        }

        $overrides = [];

        if (isset($flags['provider'])) {
            $overrides['provider'] = $flags['provider'];
        }

        if (isset($flags['model'])) {
            $overrides['model'] = $flags['model'];
        }

        return $this->plugin->buildEngineWithOverrides($overrides);
    }
}

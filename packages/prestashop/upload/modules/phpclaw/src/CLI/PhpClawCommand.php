<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\CLI;

use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\PrestaShop\Plugin;
use PhpClaw\PrestaShop\Rest\ApiHandler;

/**
 * CLI "send" command handler for phpClaw in PrestaShop.
 */
final class PhpClawCommand
{
    private $exitFn;

    /**
     * Create a new PhpClawCommand instance.
     *
     * @param  Plugin  $plugin
     * @param  callable|null  $exitFn  Overridable exit handler; defaults to the built-in exit().
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
     * Execute the chat command: send the message to the engine and print the reply.
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

        try {
            $engine = $this->resolveEngine($flags);
        } catch (\Throwable $e) {
            fwrite(STDERR, "phpClaw: failed to build engine: {$e->getMessage()}\n");
            ($this->exitFn)(1);

            return;
        }

        if ($stream) {
            $this->runStream($engine, $message);
        } else {
            $this->runSync($engine, $message);
        }
    }

    /**
     * Send the message and print the full response to stdout.
     *
     * @param  ClawInterface  $engine
     * @param  string  $message
     * @return void
     */
    private function runSync(ClawInterface $engine, string $message): void
    {
        try {
            $result = (new ApiHandler($engine))->handle($message);

            echo $result['text']."\n";
            fwrite(STDERR, sprintf(
                "\n[provider:%s model:%s tokens:%d iterations:%d conversation:%s]\n",
                $result['provider'],
                $result['model'],
                $result['tokens'],
                $result['iterations'],
                $result['conversation_id'],
            ));
        } catch (GuardException $e) {
            fwrite(STDERR, "phpClaw: prompt blocked: {$e->getMessage()}\n");
            ($this->exitFn)(1);
        } catch (ProviderException $e) {
            fwrite(STDERR, "phpClaw: provider error: {$e->getMessage()}\n");
            ($this->exitFn)(1);
        } catch (MaxIterationsException $e) {
            fwrite(STDERR, "phpClaw: max iterations reached: {$e->getMessage()}\n");
            ($this->exitFn)(1);
        }
    }

    /**
     * Send the message and print streamed tokens to stdout as they arrive.
     *
     * @param  ClawInterface  $engine
     * @param  string  $message
     * @return void
     */
    private function runStream(ClawInterface $engine, string $message): void
    {
        try {
            $conversation = $engine->conversation();
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
        } catch (GuardException $e) {
            fwrite(STDERR, "\nphpClaw: prompt blocked: {$e->getMessage()}\n");
            ($this->exitFn)(1);
        } catch (ProviderException $e) {
            fwrite(STDERR, "\nphpClaw: provider error: {$e->getMessage()}\n");
            ($this->exitFn)(1);
        } catch (MaxIterationsException $e) {
            fwrite(STDERR, "\nphpClaw: max iterations reached: {$e->getMessage()}\n");
            ($this->exitFn)(1);
        }
    }

    /**
     * Resolve the engine, rebuilding it only when a provider or model flag overrides the saved settings.
     *
     * @param  array<string, string>  $flags
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

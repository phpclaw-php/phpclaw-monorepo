<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Controller;

use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\ProviderException;

/**
 * CLI controller for the phpClaw AI agent in OpenCart 3/4.
 */
final class PhpClawController
{
    /**
     * Bind the Claw engine this controller runs prompts against.
     *
     * @param  ClawInterface  $agent  Configured AI agent engine.
     */
    public function __construct(
        private readonly ClawInterface $agent,
    ) {}

    /**
     * Run the agent with the given message and echo the response.
     *
     * @param  string  $message  The user prompt to send to the agent.
     * @return int Exit code, 0 on success and 1 on failure.
     */
    public function run(string $message): int
    {
        if ($message === '') {
            fwrite(STDERR, 'Usage: php phpclaw.php send "your prompt"'.PHP_EOL);

            return 1;
        }

        try {
            $response = $this->agent->send($message);
            echo $response->text.PHP_EOL;

            return 0;
        } catch (GuardException $e) {
            fwrite(STDERR, 'Prompt injection detected: '.$e->getMessage().PHP_EOL);

            return 1;
        } catch (ProviderException $e) {
            fwrite(STDERR, 'AI provider error: '.$e->getMessage().PHP_EOL);

            return 1;
        } catch (MaxIterationsException $e) {
            fwrite(STDERR, 'Agent loop exceeded max iterations: '.$e->getMessage().PHP_EOL);

            return 1;
        }
    }
}

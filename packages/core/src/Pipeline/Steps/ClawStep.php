<?php

declare(strict_types=1);

namespace PhpClaw\Pipeline\Steps;

use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\PipelineException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Pipeline\Contracts\PipelineStepInterface;
use Stringable;

/**
 * Pipeline step that sends its text input to an agent and returns the reply text.
 */
final class ClawStep implements PipelineStepInterface
{
    /**
     * Hold the agent that answers each input.
     *
     * @param  ClawInterface  $claw  Agent whose send() runs the full guard, tool and hook flow.
     */
    public function __construct(
        private readonly ClawInterface $claw,
    ) {}

    /**
     * Send the input as one message and return the agent's reply text.
     *
     * @param  mixed  $input  The message, as a string or Stringable.
     * @return string
     *
     * @throws PipelineException When the input is not a string or Stringable.
     * @throws GuardException When a registered guard blocks the message.
     * @throws MaxIterationsException When the ReAct loop cap is hit.
     * @throws ProviderException When the LLM API call fails after all retries.
     * @throws ToolException When the model calls an unregistered tool after the no-tools retry.
     */
    public function run(mixed $input): string
    {
        return (string) $this->claw->send($this->message($input));
    }

    /**
     * Send the input as one message, passing each reply token to the callback, and return the full reply text.
     *
     * @param  mixed  $input  The message, as a string or Stringable.
     * @param  callable(string): void  $onToken  Called with each text token as it arrives.
     * @return string
     *
     * @throws PipelineException When the input is not a string or Stringable.
     * @throws GuardException When a registered guard blocks the message.
     * @throws MaxIterationsException When the ReAct loop cap is hit.
     * @throws ProviderException When the LLM API call fails after all retries.
     * @throws ToolException When the model calls an unregistered tool after the no-tools retry.
     */
    public function stream(mixed $input, callable $onToken): string
    {
        return (string) $this->claw->stream($this->message($input), $onToken);
    }

    /**
     * Return the input as message text.
     *
     * @param  mixed  $input  The message, as a string or Stringable.
     * @return string
     *
     * @throws PipelineException When the input is not a string or Stringable.
     */
    private function message(mixed $input): string
    {
        if (! is_string($input) && ! $input instanceof Stringable) {
            throw PipelineException::invalidInput(self::class, 'a string or Stringable input', $input);
        }

        return (string) $input;
    }
}

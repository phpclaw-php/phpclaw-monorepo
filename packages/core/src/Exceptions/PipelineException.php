<?php

declare(strict_types=1);

namespace PhpClaw\Exceptions;

/**
 * Thrown when a pipeline step gets an input type it cannot handle, or stream() has no ClawStep to stream.
 */
final class PipelineException extends PhpClawException
{
    /**
     * Build the exception for a step that was handed the wrong input type; names the type, never the value.
     *
     * @param  string  $step  Class name of the step that rejected the input.
     * @param  string  $expected  What the step accepts, e.g. "a string or Stringable input".
     * @param  mixed  $input  The rejected input; only its type is reported.
     * @return self
     */
    public static function invalidInput(string $step, string $expected, mixed $input): self
    {
        return new self(sprintf('%s expects %s, got %s.', $step, $expected, get_debug_type($input)));
    }

    /**
     * Build the exception for Pipeline::stream() called on a pipeline whose last step is not a ClawStep.
     *
     * @return self
     */
    public static function lastStepCannotStream(): self
    {
        return new self('Pipeline::stream() needs a ClawStep as the last step.');
    }
}

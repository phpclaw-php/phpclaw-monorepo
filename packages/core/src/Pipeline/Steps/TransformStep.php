<?php

declare(strict_types=1);

namespace PhpClaw\Pipeline\Steps;

use Closure;
use PhpClaw\Pipeline\Contracts\PipelineStepInterface;

/**
 * Pipeline step that runs a plain callable on its input.
 */
final class TransformStep implements PipelineStepInterface
{
    private readonly Closure $transform;

    /**
     * Wrap the callable that transforms each input.
     *
     * @param  callable(mixed): mixed  $transform  Receives the step input and returns the step output.
     */
    public function __construct(callable $transform)
    {
        $this->transform = $transform(...);
    }

    /**
     * Return what the callable returns for the input.
     *
     * @param  mixed  $input  Output of the previous step.
     * @return mixed
     */
    public function run(mixed $input): mixed
    {
        return ($this->transform)($input);
    }
}

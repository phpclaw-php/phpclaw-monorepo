<?php

declare(strict_types=1);

namespace PhpClaw\Pipeline\Contracts;

/**
 * One step of a Pipeline: takes the previous step's output and returns the next step's input.
 */
interface PipelineStepInterface
{
    /**
     * Transform the input into the next step's input.
     *
     * @param  mixed  $input  Output of the previous step, or the value passed to Pipeline::invoke().
     * @return mixed
     */
    public function run(mixed $input): mixed;
}

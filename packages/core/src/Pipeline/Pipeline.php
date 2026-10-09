<?php

declare(strict_types=1);

namespace PhpClaw\Pipeline;

use PhpClaw\Exceptions\PipelineException;
use PhpClaw\Pipeline\Contracts\PipelineStepInterface;
use PhpClaw\Pipeline\Steps\ClawStep;
use PhpClaw\Pipeline\Steps\TransformStep;

/**
 * Developer-facing chain of steps run in order; unrelated to Agent\InvocationPipeline, which wraps one
 * provider call in guards and hooks.
 */
final class Pipeline
{
    private array $steps = [];

    /**
     * Start an empty pipeline.
     *
     * @return self
     */
    public static function make(): self
    {
        return new self;
    }

    /**
     * Return a copy of this pipeline with the step appended; a plain callable becomes a TransformStep.
     *
     * @param  PipelineStepInterface|callable(mixed): mixed  $step  Step to run after the existing ones.
     * @return self
     */
    public function pipe(PipelineStepInterface|callable $step): self
    {
        $pipeline = clone $this;
        $pipeline->steps[] = $step instanceof PipelineStepInterface ? $step : new TransformStep($step);

        return $pipeline;
    }

    /**
     * Run the input through every step in order and return the last step's output.
     *
     * @param  mixed  $input  Input for the first step; returned unchanged when the pipeline is empty.
     * @return mixed
     */
    public function invoke(mixed $input): mixed
    {
        foreach ($this->steps as $step) {
            $input = $step->run($input);
        }

        return $input;
    }

    /**
     * Run every input through the pipeline, one after another; the first input that fails stops the batch.
     *
     * @param  array<array-key, mixed>  $inputs  Inputs keyed as the caller likes.
     * @return array<array-key, mixed> One output per input, under the same key.
     */
    public function batch(array $inputs): array
    {
        return array_map(fn (mixed $input): mixed => $this->invoke($input), $inputs);
    }

    /**
     * Run every step but the last, then stream the last step's reply token by token and return the full reply.
     *
     * @param  mixed  $input  Input for the first step.
     * @param  callable(string): void  $onToken  Called with each text token as it arrives.
     * @return string
     *
     * @throws PipelineException When the last step is not a ClawStep; checked before any step runs.
     */
    public function stream(mixed $input, callable $onToken): string
    {
        $last = $this->steps === [] ? null : $this->steps[array_key_last($this->steps)];

        if (! $last instanceof ClawStep) {
            throw PipelineException::lastStepCannotStream();
        }

        foreach (array_slice($this->steps, 0, -1) as $step) {
            $input = $step->run($input);
        }

        return $last->stream($input, $onToken);
    }
}

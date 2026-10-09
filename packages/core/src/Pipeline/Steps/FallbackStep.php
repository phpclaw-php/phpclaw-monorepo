<?php

declare(strict_types=1);

namespace PhpClaw\Pipeline\Steps;

use PhpClaw\Exceptions\PipelineException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Exceptions\StructuredOutputException;
use PhpClaw\Pipeline\Contracts\PipelineStepInterface;
use Throwable;

/**
 * Pipeline step that tries backup steps in order when the main step fails with one of the listed errors.
 */
final class FallbackStep implements PipelineStepInterface
{
    public const DEFAULT_FALLBACK_ON = [ProviderException::class, StructuredOutputException::class];

    private readonly PipelineStepInterface $step;

    private readonly array $fallbacks;

    private readonly array $fallbackOn;

    /**
     * Wrap the main step and its backups.
     *
     * @param  PipelineStepInterface  $step  Main step, tried first.
     * @param  list<PipelineStepInterface>  $fallbacks  Backup steps, tried in order with the same input.
     * @param  list<class-string<Throwable>>  $fallbackOn  Error classes that move on to the next step; any other error is thrown at once.
     *
     * @throws PipelineException When a fallback does not implement PipelineStepInterface.
     */
    public function __construct(
        PipelineStepInterface $step,
        array $fallbacks,
        array $fallbackOn = self::DEFAULT_FALLBACK_ON,
    ) {
        foreach ($fallbacks as $fallback) {
            if (! $fallback instanceof PipelineStepInterface) {
                throw PipelineException::invalidInput(self::class, 'fallback steps that implement PipelineStepInterface', $fallback);
            }
        }

        $this->step = $step;
        $this->fallbacks = array_values($fallbacks);
        $this->fallbackOn = $fallbackOn;
    }

    /**
     * Return the first output a step produces; when every step fails, throw the main step's error.
     *
     * @param  mixed  $input  Output of the previous step; every step tried gets the same input.
     * @return mixed
     *
     * @throws Throwable The main step's error when every step failed, or the first error that is not in the list.
     */
    public function run(mixed $input): mixed
    {
        try {
            return $this->step->run($input);
        } catch (Throwable $firstError) {
            $this->throwUnlessListed($firstError);
        }

        foreach ($this->fallbacks as $fallback) {
            try {
                return $fallback->run($input);
            } catch (Throwable $error) {
                $this->throwUnlessListed($error);
            }
        }

        throw $firstError;
    }

    /**
     * Rethrow the error when its class is not listed for a fallback.
     *
     * @param  Throwable  $error  Error thrown by the main step or a fallback.
     * @return void
     *
     * @throws Throwable The same error, when it is not in the list.
     */
    private function throwUnlessListed(Throwable $error): void
    {
        if (array_filter($this->fallbackOn, static fn (string $class): bool => $error instanceof $class) === []) {
            throw $error;
        }
    }
}

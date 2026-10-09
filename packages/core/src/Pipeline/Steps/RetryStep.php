<?php

declare(strict_types=1);

namespace PhpClaw\Pipeline\Steps;

use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Exceptions\StructuredOutputException;
use PhpClaw\Pipeline\Contracts\PipelineStepInterface;
use Throwable;

/**
 * Pipeline step that runs another step again when it fails with one of the listed errors.
 */
final class RetryStep implements PipelineStepInterface
{
    public const DEFAULT_ATTEMPTS = 3;

    public const DEFAULT_RETRY_ON = [ProviderException::class, StructuredOutputException::class];

    private readonly PipelineStepInterface $step;

    private readonly int $attempts;

    private readonly array $retryOn;

    /**
     * Wrap the step to retry.
     *
     * @param  PipelineStepInterface  $step  Step to run, and to run again on a listed error.
     * @param  int  $attempts  Most runs in total, the first included; a value below 1 counts as 1.
     * @param  list<class-string<Throwable>>  $retryOn  Error classes that cause another attempt; any other error is thrown at once.
     */
    public function __construct(
        PipelineStepInterface $step,
        int $attempts = self::DEFAULT_ATTEMPTS,
        array $retryOn = self::DEFAULT_RETRY_ON,
    ) {
        $this->step = $step;
        $this->attempts = max(1, $attempts);
        $this->retryOn = $retryOn;
    }

    /**
     * Return the wrapped step's output, retrying on a listed error until the attempts run out.
     *
     * @param  mixed  $input  Output of the previous step; every attempt gets the same input.
     * @return mixed
     *
     * @throws Throwable The last attempt's error, or the first error that is not in the retry list.
     */
    public function run(mixed $input): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->step->run($input);
            } catch (Throwable $error) {
                if ($attempt >= $this->attempts || ! $this->shouldRetry($error)) {
                    throw $error;
                }
            }
        }
    }

    /**
     * Whether the error is one of the classes listed for a retry.
     *
     * @param  Throwable  $error  Error thrown by the wrapped step.
     * @return bool
     */
    private function shouldRetry(Throwable $error): bool
    {
        return array_filter($this->retryOn, static fn (string $class): bool => $error instanceof $class) !== [];
    }
}

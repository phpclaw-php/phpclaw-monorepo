<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Pipeline\Steps;

use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\PipelineException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Exceptions\StructuredOutputException;
use PhpClaw\Pipeline\Contracts\PipelineStepInterface;
use PhpClaw\Pipeline\Steps\RetryStep;
use PHPUnit\Framework\TestCase;
use Throwable;

final class RetryStepTest extends TestCase
{
    private function failingStep(array $failures, mixed $success = 'ok'): PipelineStepInterface
    {
        return new class($failures, $success) implements PipelineStepInterface
        {
            public int $calls = 0;

            public function __construct(private array $failures, private readonly mixed $success) {}

            public function run(mixed $input): mixed
            {
                $this->calls++;
                $failure = array_shift($this->failures);
                if ($failure instanceof Throwable) {
                    throw $failure;
                }

                return $this->success;
            }
        };
    }

    public function test_run_returns_the_first_success_without_retrying(): void
    {
        $step = $this->failingStep([]);

        $this->assertSame('ok', (new RetryStep($step))->run('in'));
        $this->assertSame(1, $step->calls);
    }

    public function test_run_retries_a_provider_failure_and_returns_the_later_success(): void
    {
        $step = $this->failingStep([new ProviderException('timeout'), new ProviderException('timeout')]);

        $this->assertSame('ok', (new RetryStep($step))->run('in'));
        $this->assertSame(3, $step->calls);
    }

    public function test_run_retries_a_structured_output_failure_by_default(): void
    {
        $step = $this->failingStep([new StructuredOutputException('not json', ['reply is not valid JSON'])]);

        $this->assertSame('ok', (new RetryStep($step))->run('in'));
        $this->assertSame(2, $step->calls);
    }

    public function test_run_throws_the_last_error_once_every_attempt_failed(): void
    {
        $last = new ProviderException('third');
        $step = $this->failingStep([new ProviderException('first'), new ProviderException('second'), $last]);

        try {
            (new RetryStep($step))->run('in');
            $this->fail('Expected the provider error.');
        } catch (ProviderException $exception) {
            $this->assertSame($last, $exception);
        }

        $this->assertSame(3, $step->calls);
    }

    public function test_run_never_retries_an_error_outside_the_retry_list(): void
    {
        $blocked = new GuardException('blocked');
        $step = $this->failingStep([$blocked]);

        try {
            (new RetryStep($step))->run('in');
            $this->fail('Expected the guard error.');
        } catch (GuardException $exception) {
            $this->assertSame($blocked, $exception);
        }

        $this->assertSame(1, $step->calls);
    }

    public function test_run_retries_the_error_classes_the_caller_lists(): void
    {
        $step = $this->failingStep([new PipelineException('wrong type')]);

        $this->assertSame('ok', (new RetryStep($step, retryOn: [PipelineException::class]))->run('in'));
        $this->assertSame(2, $step->calls);
    }

    public function test_attempts_below_one_still_runs_the_step_once(): void
    {
        $step = $this->failingStep([new ProviderException('down')]);

        try {
            (new RetryStep($step, attempts: 0))->run('in');
            $this->fail('Expected the provider error.');
        } catch (ProviderException) {
            $this->assertSame(1, $step->calls);
        }
    }
}

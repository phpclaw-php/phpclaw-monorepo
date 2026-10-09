<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Pipeline\Steps;

use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\PipelineException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Pipeline\Contracts\PipelineStepInterface;
use PhpClaw\Pipeline\Steps\FallbackStep;
use PHPUnit\Framework\TestCase;
use Throwable;

final class FallbackStepTest extends TestCase
{
    private function step(mixed $outcome): PipelineStepInterface
    {
        return new class($outcome) implements PipelineStepInterface
        {
            public array $inputs = [];

            public function __construct(private readonly mixed $outcome) {}

            public function run(mixed $input): mixed
            {
                $this->inputs[] = $input;
                if ($this->outcome instanceof Throwable) {
                    throw $this->outcome;
                }

                return $this->outcome;
            }
        };
    }

    public function test_run_returns_the_primary_result_and_never_calls_a_fallback(): void
    {
        $backup = $this->step('backup');

        $this->assertSame('primary', (new FallbackStep($this->step('primary'), [$backup]))->run('in'));
        $this->assertSame([], $backup->inputs);
    }

    public function test_run_tries_each_fallback_in_order_with_the_same_input(): void
    {
        $second = $this->step(new ProviderException('also down'));
        $third = $this->step('third answered');

        $result = (new FallbackStep($this->step(new ProviderException('down')), [$second, $third]))->run('question');

        $this->assertSame('third answered', $result);
        $this->assertSame(['question'], $second->inputs);
        $this->assertSame(['question'], $third->inputs);
    }

    public function test_run_throws_the_first_error_when_every_step_fails(): void
    {
        $first = new ProviderException('primary down');
        $step = new FallbackStep($this->step($first), [$this->step(new ProviderException('backup down'))]);

        try {
            $step->run('in');
            $this->fail('Expected the primary error.');
        } catch (ProviderException $exception) {
            $this->assertSame($first, $exception);
        }
    }

    public function test_run_stops_on_an_error_outside_the_fallback_list(): void
    {
        $blocked = new GuardException('blocked');
        $backup = $this->step('backup');

        try {
            (new FallbackStep($this->step($blocked), [$backup]))->run('in');
            $this->fail('Expected the guard error.');
        } catch (GuardException $exception) {
            $this->assertSame($blocked, $exception);
        }

        $this->assertSame([], $backup->inputs);
    }

    public function test_run_falls_back_on_the_error_classes_the_caller_lists(): void
    {
        $step = new FallbackStep($this->step(new GuardException('blocked')), [$this->step('safe answer')], fallbackOn: [GuardException::class]);

        $this->assertSame('safe answer', $step->run('in'));
    }

    public function test_a_fallback_that_is_not_a_step_is_rejected_when_built(): void
    {
        $this->expectException(PipelineException::class);
        $this->expectExceptionMessage(FallbackStep::class.' expects fallback steps that implement PipelineStepInterface, got string.');

        new FallbackStep($this->step('primary'), ['not a step']);
    }
}

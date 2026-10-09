<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Pipeline\Steps;

use PhpClaw\Pipeline\Steps\TransformStep;
use PHPUnit\Framework\TestCase;

final class TransformStepTest extends TestCase
{
    public function test_run_returns_what_the_closure_returns_for_the_input(): void
    {
        $step = new TransformStep(static fn (array $order): float => $order['total'] * 2);

        $this->assertSame(175.0, $step->run(['total' => 87.5]));
    }

    public function test_run_accepts_a_callable_string(): void
    {
        $step = new TransformStep('strrev');

        $this->assertSame('olleh', $step->run('hello'));
    }
}

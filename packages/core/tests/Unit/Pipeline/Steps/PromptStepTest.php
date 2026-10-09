<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Pipeline\Steps;

use PhpClaw\Exceptions\PipelineException;
use PhpClaw\Exceptions\PromptTemplateException;
use PhpClaw\Pipeline\Steps\PromptStep;
use PHPUnit\Framework\TestCase;

final class PromptStepTest extends TestCase
{
    public function test_run_fills_every_placeholder_from_the_input_array(): void
    {
        $step = new PromptStep('Summarise ticket {id} for {team}.');

        $this->assertSame('Summarise ticket 4471 for billing.', $step->run(['id' => 4471, 'team' => 'billing']));
    }

    public function test_run_throws_when_a_placeholder_has_no_value(): void
    {
        $step = new PromptStep('Summarise ticket {id} for {team}.');

        $this->expectException(PromptTemplateException::class);
        $this->expectExceptionMessage("Missing value for placeholder 'team'.");

        $step->run(['id' => 4471]);
    }

    public function test_run_throws_when_the_input_is_not_an_array(): void
    {
        $step = new PromptStep('Summarise ticket {id}.');

        $this->expectException(PipelineException::class);
        $this->expectExceptionMessage(PromptStep::class.' expects an array of placeholder values, got string.');

        $step->run('4471');
    }
}

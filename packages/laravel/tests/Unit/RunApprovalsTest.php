<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit;

use Orchestra\Testbench\TestCase;
use PhpClaw\Claw;
use PhpClaw\Exceptions\RunStateException;
use PhpClaw\Laravel\PhpClawServiceProvider;
use PhpClaw\Laravel\RunApprovals;
use PhpClaw\Laravel\Tests\Feature\Durable\ScriptedProvider;

final class RunApprovalsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    public function test_loading_a_run_needs_a_memory_driver(): void
    {
        $claw = Claw::builder()->providerOverride(new ScriptedProvider([]))->useDefaultGuards(false)->build();

        $this->expectException(RunStateException::class);
        RunApprovals::load($claw, '01JUNKNOWNRUN0000000000000');
    }
}

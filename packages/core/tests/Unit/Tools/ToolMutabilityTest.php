<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Tools;

use PhpClaw\Agent\CliApprovalGate;
use PhpClaw\Exceptions\HumanDeniedException;
use PhpClaw\Tests\Unit\Agent\Durable\CountingMutatingTool;
use PhpClaw\Tests\Unit\Agent\Durable\CountingTool;
use PhpClaw\Tests\Unit\Agent\Durable\PerCallMutatingTool;
use PhpClaw\Tools\ToolMutability;
use PHPUnit\Framework\TestCase;

final class ToolMutabilityTest extends TestCase
{
    public function test_is_approval_required_matches_the_gate_for_all_three_tool_shapes(): void
    {
        $perCall = new PerCallMutatingTool;

        $cases = [
            'plain tool' => [new CountingTool('read'), [], false],
            'mutating tool' => [new CountingMutatingTool('write'), [], true],
            'per-call, read' => [$perCall, ['write' => false], false],
            'per-call, write' => [$perCall, ['write' => true], true],
            'unknown tool' => [null, [], false],
        ];
        $gate = new CliApprovalGate;

        foreach ($cases as $label => [$tool, $input, $expected]) {
            $this->assertSame($expected, ToolMutability::isApprovalRequired($tool, $input), $label);
            try {
                $gate->check('t', $input, $tool);
                $gateDenied = false;
            } catch (HumanDeniedException) {
                $gateDenied = true;
            }
            $this->assertSame($expected, $gateDenied, $label.' (CliApprovalGate agrees)');
        }
    }
}

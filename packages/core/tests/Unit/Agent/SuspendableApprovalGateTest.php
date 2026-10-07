<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Agent;

use PhpClaw\Agent\Contracts\ApprovalGateInterface;
use PhpClaw\Agent\SuspendableApprovalGate;
use PhpClaw\Exceptions\ApprovalPendingException;
use PhpClaw\Tests\Unit\Agent\Durable\CountingMutatingTool;
use PhpClaw\Tests\Unit\Agent\Durable\CountingTool;
use PHPUnit\Framework\TestCase;

final class SuspendableApprovalGateTest extends TestCase
{
    public function test_a_mutating_call_throws_approval_pending_with_its_name_and_input(): void
    {
        $gate = new SuspendableApprovalGate;

        try {
            $gate->check('write', ['path' => 'a'], new CountingMutatingTool('write'));
            $this->fail('expected ApprovalPendingException');
        } catch (ApprovalPendingException $e) {
            $this->assertSame('write', $e->toolName);
            $this->assertSame(['path' => 'a'], $e->toolInput);
        }
        $this->assertInstanceOf(ApprovalGateInterface::class, $gate);
    }

    public function test_a_read_only_or_unknown_call_passes(): void
    {
        $gate = new SuspendableApprovalGate;

        $gate->check('read', [], new CountingTool('read'));
        $gate->check('missing', [], null);

        $this->addToAssertionCount(1);
    }
}

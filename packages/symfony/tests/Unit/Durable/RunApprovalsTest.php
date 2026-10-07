<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit\Durable;

use PhpClaw\Agent\RunState;
use PhpClaw\Claw;
use PhpClaw\Exceptions\RunStateException;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Symfony\RunApprovals;
use PHPUnit\Framework\TestCase;

final class RunApprovalsTest extends TestCase
{
    use DurableFixture;

    protected function setUp(): void
    {
        $this->bootDurableFixture();
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
    }

    public function test_load_without_a_memory_driver_is_refused(): void
    {
        $this->expectException(RunStateException::class);

        $this->approvals()->load(Claw::builder()->providerOverride(new ScriptedProvider([]))->useDefaultGuards(false)->build(), '01JUNKNOWNRUN0000000000000');
    }

    public function test_a_run_without_a_conversation_has_no_owner_and_only_manage_all_may_decide_it(): void
    {
        $state = RunState::start('01JRUNWITHOUTCONVERSATION0', 'go', 'go');

        $this->actAsUser('alice');
        self::assertNull($this->approvals()->ownerOf($state));
        self::assertFalse($this->approvals()->canDecide($state));

        $this->actAsUser('admin', manageAll: true);
        self::assertTrue($this->approvals()->canDecide($state));
    }

    public function test_as_owner_restores_the_identity_that_was_acting_before(): void
    {
        $state = RunState::start('01JRUNWITHOUTCONVERSATION0', 'go', 'go');
        $this->identity->actAs('worker-user');

        $inside = $this->approvals()->asOwner($state, fn (): string => $this->identity->actingUserId());

        self::assertSame('', $inside);
        self::assertSame('worker-user', $this->identity->actingUserId());
    }

    public function test_without_doctrine_no_run_has_an_owner(): void
    {
        $state = RunState::start('01JRUN000000000000000000000', 'go', 'go', conversationId: '01JCONVERSATION00000000000');

        self::assertNull((new RunApprovals($this->identity))->ownerOf($state));
    }
}

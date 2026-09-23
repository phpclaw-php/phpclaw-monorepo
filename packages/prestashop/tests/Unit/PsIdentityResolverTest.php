<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit;

use PhpClaw\PrestaShop\PsIdentityResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PsIdentityResolver::class)]
final class PsIdentityResolverTest extends TestCase
{
    private const DEBUG_TAB_ID = 7;

    private const SETTINGS_TAB_ID = 3;

    private const CHAT_TIER_PROFILE = 4;

    protected function setUp(): void
    {
        parent::setUp();

        \Context::reset();
        \Tab::reset();
        \Profile::reset();

        \Tab::$idsByClass = [
            'AdminPhpClawDebug' => self::DEBUG_TAB_ID,
            'AdminPhpClawSettings' => self::SETTINGS_TAB_ID,
        ];
    }

    protected function tearDown(): void
    {
        \Context::reset();
        \Tab::reset();
        \Profile::reset();

        parent::tearDown();
    }

    public function test_has_tab_access_refuses_a_tab_the_caller_does_not_hold(): void
    {
        $this->actAsChatTierEmployee();

        self::assertTrue(PsIdentityResolver::hasTabAccess('AdminPhpClawDebug'));
        self::assertFalse(PsIdentityResolver::hasTabAccess('AdminPhpClawSettings'));
    }

    public function test_has_tab_access_refuses_a_level_the_caller_does_not_hold(): void
    {
        $this->actAsChatTierEmployee();

        self::assertTrue(PsIdentityResolver::hasTabAccess('AdminPhpClawDebug', 'view'));
        self::assertFalse(PsIdentityResolver::hasTabAccess('AdminPhpClawDebug', 'edit'));
    }

    public function test_has_tab_access_refuses_an_unregistered_tab(): void
    {
        $this->actAsChatTierEmployee();

        self::assertFalse(PsIdentityResolver::hasTabAccess('AdminPhpClawNoSuchTab'));
    }

    public function test_has_tab_access_refuses_when_no_employee_is_acting(): void
    {
        self::assertFalse(PsIdentityResolver::hasTabAccess('AdminPhpClawDebug'));
    }

    public function test_super_admin_holds_every_tab(): void
    {
        $employee = new \Employee;
        $employee->id = 1;
        $employee->id_profile = 1;
        $employee->superAdmin = true;
        \Context::getContext()->employee = $employee;

        self::assertTrue(PsIdentityResolver::hasTabAccess('AdminPhpClawDebug'));
        self::assertTrue(PsIdentityResolver::hasTabAccess('AdminPhpClawSettings'));
    }

    public function test_can_use_chat_reads_the_debug_tab(): void
    {
        $this->actAsChatTierEmployee();

        self::assertTrue(PsIdentityResolver::canUseChat());
    }

    public function test_chat_tier_and_manage_all_read_different_conditions(): void
    {
        $this->actAsChatTierEmployee();

        self::assertTrue(
            PsIdentityResolver::canUseChat(),
            'A non-SuperAdmin employee holding view on AdminPhpClawDebug is at the chat tier.',
        );
        self::assertFalse(
            PsIdentityResolver::manageAll(),
            'The chat tier must not carry the manage-all grant, or every chat employee is an administrator.',
        );
    }

    public function test_below_tier_employee_reaches_neither_tier(): void
    {
        $employee = new \Employee;
        $employee->id = 99;
        $employee->id_profile = 2;
        $employee->superAdmin = false;
        \Context::getContext()->employee = $employee;

        self::assertFalse(PsIdentityResolver::canUseChat());
        self::assertFalse(PsIdentityResolver::manageAll());
    }

    public function test_acting_employee_id_returns_the_context_employee(): void
    {
        $this->actAsChatTierEmployee();

        self::assertSame(42, PsIdentityResolver::actingEmployeeId());
    }

    private function actAsChatTierEmployee(): void
    {
        $employee = new \Employee;
        $employee->id = 42;
        $employee->id_profile = self::CHAT_TIER_PROFILE;
        $employee->superAdmin = false;

        \Context::getContext()->employee = $employee;

        \Profile::grant(self::CHAT_TIER_PROFILE, self::DEBUG_TAB_ID, 'view');
    }
}

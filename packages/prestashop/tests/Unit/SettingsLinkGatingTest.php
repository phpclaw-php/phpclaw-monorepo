<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit;

use PhpClaw\PrestaShop\PsIdentityResolver;
use PHPUnit\Framework\TestCase;

final class SettingsLinkGatingTest extends TestCase
{
    protected function tearDown(): void
    {
        PsIdentityResolver::unbindEmployee();

        parent::tearDown();
    }

    public function test_manage_all_is_granted_to_a_super_admin(): void
    {
        PsIdentityResolver::bindEmployee($this->employee(isSuperAdmin: true));

        self::assertTrue(
            PsIdentityResolver::manageAll(),
            'A SuperAdmin must hold manage-all, it is the grant that gates the Settings link.',
        );
    }

    public function test_manage_all_is_refused_to_an_ordinary_employee(): void
    {
        PsIdentityResolver::bindEmployee($this->employee(isSuperAdmin: false));

        self::assertFalse(
            PsIdentityResolver::manageAll(),
            'An ordinary employee must not hold manage-all, otherwise every Settings link is ungated.',
        );
    }

    public function test_manage_all_is_refused_when_no_employee_is_bound(): void
    {
        PsIdentityResolver::unbindEmployee();

        self::assertFalse(PsIdentityResolver::manageAll());
    }

    /**
     * Build an Employee stub at the requested tier.
     *
     * @param  bool  $isSuperAdmin  Whether the employee is a SuperAdmin.
     * @return \Employee The stubbed employee.
     */
    private function employee(bool $isSuperAdmin): \Employee
    {
        $employee = new \Employee;
        $employee->id = $isSuperAdmin ? 1 : 2;
        $employee->id_profile = $isSuperAdmin ? 1 : 4;
        $employee->email = $isSuperAdmin ? 'admin@example.test' : 'sales@example.test';
        $employee->superAdmin = $isSuperAdmin;
        $employee->active = true;

        return $employee;
    }
}

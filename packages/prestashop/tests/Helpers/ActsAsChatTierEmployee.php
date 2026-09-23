<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Helpers;

trait ActsAsChatTierEmployee
{
    protected function actAsChatTierEmployee(): void
    {
        \Tab::$idsByClass['AdminPhpClawDebug'] = 7;
        \Profile::grant(4, 7, 'view');

        $employee = new \Employee;
        $employee->id = 42;
        $employee->id_profile = 4;
        $employee->superAdmin = false;

        \Context::getContext()->employee = $employee;
    }

    protected function actAsEmployeeBelowChatTier(): void
    {
        \Tab::$idsByClass['AdminPhpClawDebug'] = 7;

        $employee = new \Employee;
        $employee->id = 99;
        $employee->id_profile = 2;
        $employee->superAdmin = false;

        \Context::getContext()->employee = $employee;
    }

    protected function stopActingAsEmployee(): void
    {
        \Context::reset();
        \Tab::reset();
        \Profile::reset();
    }
}

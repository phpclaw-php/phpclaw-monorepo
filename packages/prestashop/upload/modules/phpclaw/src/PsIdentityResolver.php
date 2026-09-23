<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop;

/**
 * Resolves the acting employee identity from the native PS session context.
 */
final class PsIdentityResolver
{
    /**
     * Bind an employee as the acting identity for this request, so a token-authenticated call resolves the same way a session does.
     *
     * @param  \Employee  $employee  Employee the caller is acting as.
     * @return void
     */
    public static function bindEmployee(\Employee $employee): void
    {
        if (! class_exists(\Context::class)) {
            return;
        }

        \Context::getContext()->employee = $employee;
    }

    /**
     * Clear the bound acting identity, so a refused token leaves no employee attached to the request.
     *
     * @return void
     */
    public static function unbindEmployee(): void
    {
        if (! class_exists(\Context::class)) {
            return;
        }

        \Context::getContext()->employee = null;
    }

    /**
     * The logged-in employee id, or the `0` "no verified identity" sentinel.
     *
     * @return int
     */
    public static function actingEmployeeId(): int
    {
        $employee = self::employee();

        return ($employee !== null && (int) $employee->id > 0) ? (int) $employee->id : 0;
    }

    /**
     * Whether the acting employee holds PrestaShop's SuperAdmin profile, the manage-all grant.
     *
     * @return bool
     */
    public static function manageAll(): bool
    {
        $employee = self::employee();

        return $employee !== null && (int) $employee->id > 0 && $employee->isSuperAdmin();
    }

    /**
     * Whether the acting employee holds `view` on the AdminPhpClawDebug tab, or is SuperAdmin.
     *
     * @return bool
     */
    public static function canUseChat(): bool
    {
        return self::hasTabAccess('AdminPhpClawDebug');
    }

    /**
     * Whether the acting employee holds the given access level on the given back-office tab.
     *
     * @param  string  $tabClass  Tab class name, for example AdminPhpClawDebug.
     * @param  string  $level  Access level to test, one of view, add, edit or delete.
     * @return bool
     */
    public static function hasTabAccess(string $tabClass, string $level = 'view'): bool
    {
        $employee = self::employee();

        if ($employee === null || (int) $employee->id <= 0) {
            return false;
        }

        if ($employee->isSuperAdmin()) {
            return true;
        }

        try {
            $idTab = (int) \Tab::getIdFromClassName($tabClass);

            if ($idTab <= 0) {
                return false;
            }

            $access = \Profile::getProfileAccess((int) $employee->id_profile, $idTab);

            return is_array($access) && ! empty($access[$level]);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The current PS employee from the controller context, then the admin cookie, else null.
     *
     * @return \Employee|null
     */
    private static function employee(): ?\Employee
    {
        if (! class_exists(\Context::class)) {
            return null;
        }

        $contextEmployee = \Context::getContext()->employee ?? null;
        if ($contextEmployee instanceof \Employee && (int) $contextEmployee->id > 0) {
            return $contextEmployee;
        }

        return self::employeeFromAdminCookie();
    }

    /**
     * Resolve and validate the active employee from the encrypted admin session cookie.
     *
     * @return \Employee|null
     */
    private static function employeeFromAdminCookie(): ?\Employee
    {
        if (! class_exists(\Cookie::class)) {
            return null;
        }

        $cookie = new \Cookie('psAdmin');

        if (! isset($cookie->id_employee) || (int) $cookie->id_employee <= 0) {
            return null;
        }

        $employee = new \Employee((int) $cookie->id_employee);

        if (! \Validate::isLoadedObject($employee) || ! $employee->active) {
            return null;
        }

        if (! $employee->checkPassword((int) $cookie->id_employee, (string) $cookie->passwd)) {
            return null;
        }

        return $employee;
    }
}

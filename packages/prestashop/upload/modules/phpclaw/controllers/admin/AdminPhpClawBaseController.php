<?php

declare(strict_types=1);

use PhpClaw\PrestaShop\PsPluginAccessor;

if (! defined('_PS_VERSION_')) {
    exit;
}

/**
 * Abstract base controller shared by all phpClaw Back Office admin pages.
 */
abstract class AdminPhpClawBaseController extends ModuleAdminController
{
    use PsPluginAccessor;

    /**
     * Bind the module instance and enable the Bootstrap 4 layout.
     */
    public function __construct()
    {
        $this->module = Module::getInstanceByName('phpclaw');
        $this->bootstrap = true;
        parent::__construct();
    }

    /**
     * Return true if the currently logged-in employee has the requested access level.
     *
     * @param  string  $level  'view' | 'add' | 'edit' | 'delete'
     * @return bool
     */
    protected function canDo(string $level): bool
    {
        return (bool) $this->access($level);
    }

    /**
     * Emit a JSON ajax response and stop. Works on PS8 (ajaxDie) and PS9 (ajaxRender, ajaxDie removed).
     *
     * @param  array<string, mixed>  $data
     * @return never
     */
    protected function respondJson(array $data): never
    {
        if (! headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }

        $this->ajaxRender(json_encode($data));
        exit;
    }
}

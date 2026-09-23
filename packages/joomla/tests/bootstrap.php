<?php

declare(strict_types=1);
use Joomla\CMS\Application\ConsoleApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Session\Session;
use Joomla\Router\Route;

/**
 * Unit-test bootstrap.
 *
 * Loads the Composer autoloader first, then declares lightweight stubs for
 * Joomla CMS classes that are NOT shipped as composer packages (joomla/cms is
 * only available inside a real Joomla install). Stubs must be registered AFTER
 * autoload so they never shadow real vendor classes.
 */
require_once __DIR__.'/../vendor/autoload.php';

if (! class_exists(Text::class, false)) {
    require_once __DIR__.'/Support/Stubs/JoomlaText.php';
}

if (! class_exists(Factory::class, false)) {
    require_once __DIR__.'/Support/Stubs/JoomlaFactory.php';
}

if (! class_exists(ConsoleApplication::class, false)) {
    require_once __DIR__.'/Support/Stubs/JoomlaConsoleApplication.php';
}

if (! class_exists(PluginHelper::class, false)) {
    require_once __DIR__.'/Support/Stubs/JoomlaPluginHelper.php';
}

if (! class_exists(BaseDatabaseModel::class, false)) {
    require_once __DIR__.'/Support/Stubs/JoomlaBaseDatabaseModel.php';
}

if (! class_exists(BaseController::class, false)) {
    require_once __DIR__.'/Support/Stubs/JoomlaMvcController.php';
}

if (! class_exists(Session::class, false)) {
    require_once __DIR__.'/Support/Stubs/JoomlaSession.php';
}

if (! class_exists(Route::class, false)) {
    require_once __DIR__.'/Support/Stubs/JoomlaApiRouting.php';
}

if (! interface_exists(InstallerScriptInterface::class, false)) {
    require_once __DIR__.'/Support/Stubs/JoomlaInstaller.php';
}

if (! defined('JPATH_ROOT')) {
    define('JPATH_ROOT', sys_get_temp_dir().'/phpclaw-joomla-tests');
}

if (! defined('JPATH_ADMINISTRATOR')) {
    define('JPATH_ADMINISTRATOR', JPATH_ROOT.'/administrator');
}

<?php

declare(strict_types=1);

namespace Joomla\CMS\Installer;

class InstallerAdapter
{
    public function __construct(public string $route = 'install') {}
}

interface InstallerScriptInterface
{
    public function preflight(string $type, InstallerAdapter $adapter): bool;

    public function install(InstallerAdapter $adapter): bool;

    public function update(InstallerAdapter $adapter): bool;

    public function uninstall(InstallerAdapter $adapter): bool;

    public function postflight(string $type, InstallerAdapter $adapter): bool;
}

<?php

declare(strict_types=1);

namespace Joomla\CMS\Plugin;

final class PluginHelper
{
    public static object|false $plugin = false;

    public static function getPlugin(string $type, string $element): object|false
    {
        return self::$plugin;
    }
}

<?php

declare(strict_types=1);

namespace Joomla\CMS;

final class Factory
{
    public static ?object $application = null;

    public static ?object $container = null;

    public static ?\Throwable $applicationError = null;

    public static function getApplication()
    {
        if (self::$applicationError !== null) {
            throw self::$applicationError;
        }

        return self::$application;
    }

    public static function getContainer()
    {
        return self::$container;
    }
}

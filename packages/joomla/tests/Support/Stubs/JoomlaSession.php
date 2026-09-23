<?php

declare(strict_types=1);

namespace Joomla\CMS\Session;

final class Session
{
    public static bool $tokenValid = true;

    public static function checkToken(string $method = 'post'): bool
    {
        return self::$tokenValid;
    }
}

<?php

declare(strict_types=1);

namespace Joomla\CMS\Language;

final class Text
{
    public static array $strings = [];

    public static function _(string $key, bool $jsSafe = false, bool $interpretBackSlashes = true): string
    {
        return self::$strings[$key] ?? $key;
    }

    public static function sprintf(string $key, mixed ...$args): string
    {
        return sprintf($key, ...$args);
    }
}

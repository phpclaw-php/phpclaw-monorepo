<?php

declare(strict_types=1);

namespace PhpClaw\Cloud;

final class CloudManager
{
    public static array $boots = [];

    public static function boot(string $key, array $disable = [], string $signingSecret = '', bool $failClosed = false): void
    {
        self::$boots[] = [$key, $disable, $signingSecret, $failClosed];
    }
}

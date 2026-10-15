<?php

declare(strict_types=1);

namespace Automattic\WooCommerce\Utilities;

final class FeaturesUtil
{
    public static array $declared = [];

    public static function declare_compatibility(string $feature, string $pluginFile, bool $positiveCompatibility = true): bool
    {
        self::$declared[] = [$feature, $pluginFile, $positiveCompatibility];

        return true;
    }
}

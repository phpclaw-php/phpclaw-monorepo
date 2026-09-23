<?php

declare(strict_types=1);

/**
 * Minimal WC_Tax stub for unit testing.
 */
if (! class_exists('WC_Tax')) {
    class WC_Tax
    {
        public static array $testClasses = [''];

        public static array $testRates = [];

        public static function get_tax_classes(): array
        {
            return self::$testClasses;
        }

        public static function get_rates_for_tax_class(string $class): array
        {
            return self::$testRates[$class] ?? self::$testRates[''] ?? [];
        }
    }
}

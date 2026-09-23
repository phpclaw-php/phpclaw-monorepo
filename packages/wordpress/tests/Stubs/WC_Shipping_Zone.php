<?php

declare(strict_types=1);

/**
 * Minimal WC_Shipping_Zone + WC_Shipping_Zones stubs for unit testing.
 */
if (! class_exists('WC_Shipping_Zone')) {
    class WC_Shipping_Zone
    {
        private int $id;

        private string $name;

        private array $methods;

        public function __construct(int $id = 0, string $name = 'Rest of the World', array $methods = [])
        {
            $this->id = $id;
            $this->name = $name;
            $this->methods = $methods;
        }

        public function get_zone_name(): string
        {
            return $this->name;
        }

        public function get_shipping_methods(): array
        {
            return $this->methods;
        }
    }
}

if (! class_exists('WC_Shipping_Zones')) {
    class WC_Shipping_Zones
    {
        public static array $testZones = [];

        public static function get_zones(): array
        {
            return self::$testZones;
        }
    }
}

<?php

declare(strict_types=1);

/**
 * Minimal WC_Product stub for unit testing without a WooCommerce installation.
 */
if (! class_exists('WC_Product')) {
    class WC_Product
    {
        public function __construct(
            private readonly int $id,
            private readonly string $name,
            private readonly string $stockStatus,
            private readonly ?int $stockQty,
        ) {}

        public function get_id(): int
        {
            return $this->id;
        }

        public function get_name(): string
        {
            return $this->name;
        }

        public function get_stock_status(): string
        {
            return $this->stockStatus;
        }

        public function get_stock_quantity(): ?int
        {
            return $this->stockQty;
        }
    }
}

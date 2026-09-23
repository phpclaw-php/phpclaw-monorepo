<?php

declare(strict_types=1);

/**
 * Minimal WC_Customer stub for unit testing without a WooCommerce installation.
 */
if (! class_exists('WC_Customer')) {
    class WC_Customer
    {
        public function __construct(
            private readonly int $id,
            private readonly int $orderCount,
            private readonly string $totalSpent,
        ) {}

        public function get_id(): int
        {
            return $this->id;
        }

        public function get_order_count(): int
        {
            return $this->orderCount;
        }

        public function get_total_spent(): string
        {
            return $this->totalSpent;
        }
    }
}

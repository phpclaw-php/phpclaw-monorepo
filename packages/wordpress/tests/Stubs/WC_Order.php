<?php

declare(strict_types=1);

/**
 * Minimal WC_Order stub for unit testing without a WooCommerce installation.
 */
if (! class_exists('WC_Order')) {
    class WC_Order
    {
        public function __construct(
            private readonly int $id,
            private readonly string $status,
            private readonly string $total,
        ) {}

        public function get_id(): int
        {
            return $this->id;
        }

        public function get_status(): string
        {
            return $this->status;
        }

        public function get_total(): string
        {
            return $this->total;
        }
    }
}

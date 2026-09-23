<?php

declare(strict_types=1);

/**
 * Minimal WC_Coupon stub for unit testing without WooCommerce.
 */
if (! class_exists('WC_Coupon')) {
    class WC_Coupon
    {
        public static array $testCoupons = [];

        private int $id;

        private string $code;

        private string $discountType;

        private string $amount;

        private int $usageCount;

        private int $usageLimit;

        private ?DateTimeImmutable $dateExpires;

        private bool $freeShipping;

        private string $minimumAmount;

        public function __construct(
            int $id = 0,
            string $code = '',
            string $discountType = 'percent',
            string $amount = '10',
            int $usageCount = 0,
            int $usageLimit = 0,
            ?DateTimeImmutable $dateExpires = null,
            bool $freeShipping = false,
            string $minimumAmount = ''
        ) {
            if ($id > 0 && $code === '' && isset(self::$testCoupons[$id])) {
                $data = self::$testCoupons[$id];
                $this->id = $id;
                $this->code = $data['code'] ?? '';
                $this->discountType = $data['discount_type'] ?? 'percent';
                $this->amount = $data['amount'] ?? '10';
                $this->usageCount = $data['usage_count'] ?? 0;
                $this->usageLimit = $data['usage_limit'] ?? 0;
                $this->dateExpires = $data['date_expires'] ?? null;
                $this->freeShipping = $data['free_shipping'] ?? false;
                $this->minimumAmount = $data['minimum_amount'] ?? '';

                return;
            }

            $this->id = $id;
            $this->code = $code;
            $this->discountType = $discountType;
            $this->amount = $amount;
            $this->usageCount = $usageCount;
            $this->usageLimit = $usageLimit;
            $this->dateExpires = $dateExpires;
            $this->freeShipping = $freeShipping;
            $this->minimumAmount = $minimumAmount;
        }

        public function get_id(): int
        {
            return $this->id;
        }

        public function get_code(): string
        {
            return $this->code;
        }

        public function get_discount_type(): string
        {
            return $this->discountType;
        }

        public function get_amount(): string
        {
            return $this->amount;
        }

        public function get_usage_count(): int
        {
            return $this->usageCount;
        }

        public function get_usage_limit(): int
        {
            return $this->usageLimit;
        }

        public function get_date_expires(): ?DateTimeImmutable
        {
            return $this->dateExpires;
        }

        public function get_free_shipping(): bool
        {
            return $this->freeShipping;
        }

        public function get_minimum_amount(): string
        {
            return $this->minimumAmount;
        }
    }
}

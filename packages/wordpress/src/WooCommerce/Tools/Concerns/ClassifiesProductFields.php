<?php

declare(strict_types=1);

namespace PhpClaw\WooCommerce\Tools\Concerns;

/**
 * Shared product field classification for the WooCommerce tools.
 */
trait ClassifiesProductFields
{
    /**
     * Field names that must never be returned, whatever the caller asks for.
     *
     * @return array<int, string>
     */
    protected static function blockedProductFields(): array
    {
        return [
            'downloads', 'download_ids', 'download_urls', 'downloadable_files',
            'file_paths', 'license_key', 'licence_key', 'licence_keys', 'license_keys',
            'gateway_credentials', 'api_key', 'api_secret',
        ];
    }

    /**
     * Meta key prefixes that must never be returned.
     *
     * @return array<int, string>
     */
    protected static function blockedProductMetaPrefixes(): array
    {
        return [
            '_downloadable_files', '_wc_licence', '_wc_license', '_license_',
            '_stripe_', '_ppcp_', '_paypal_', '_api_key',
        ];
    }

    /**
     * Commercial fields returned only on explicit request, with a warning.
     *
     * @return array<int, string>
     */
    protected static function sensitiveProductFields(): array
    {
        return ['cost_of_goods', 'supplier', 'supplier_sku'];
    }

    /**
     * The only meta keys any product tool will ever read, by field name.
     *
     * @return array<string, array<int, string>>
     */
    protected static function sensitiveProductMetaKeys(): array
    {
        return [
            'cost_of_goods' => ['_wc_cog_cost', '_cost_of_goods', '_purchase_price', '_wholesale_price'],
            'supplier' => ['_supplier', '_wc_supplier'],
            'supplier_sku' => ['_supplier_sku', '_wc_supplier_sku'],
        ];
    }

    /**
     * Report whether a requested field names downloadable, licence or gateway material.
     *
     * @param  string  $field  Requested field name.
     * @return bool
     */
    protected function isBlockedProductField(string $field): bool
    {
        $lower = strtolower($field);

        if (in_array($lower, array_map('strtolower', self::blockedProductFields()), true)) {
            return true;
        }

        foreach (self::blockedProductMetaPrefixes() as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Read the first non-empty value from the fixed key list for one sensitive field.
     *
     * @param  mixed  $product  A WC_Product or compatible object.
     * @param  string  $field  One of the sensitive field names.
     * @return string The first non-empty value, or an empty string.
     */
    protected function readSensitiveProductMeta(mixed $product, string $field): string
    {
        $keys = self::sensitiveProductMetaKeys()[$field] ?? [];

        if ($keys === [] || ! is_callable([$product, 'get_meta'])) {
            return '';
        }

        foreach ($keys as $key) {
            $value = $product->get_meta($key);

            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return '';
    }

    /**
     * Sum stock across a variable product's children in one batched read.
     *
     * @param  mixed  $product  A WC_Product or compatible object.
     * @param  callable(array<string, mixed>): mixed  $fetcher  Product fetcher.
     * @return int|null Summed variation stock, or null when the product has no children.
     */
    protected function sumVariationStock(mixed $product, callable $fetcher): ?int
    {
        if (! is_callable([$product, 'get_children'])) {
            return null;
        }

        $children = (array) $product->get_children();

        if ($children === []) {
            return null;
        }

        $variations = $fetcher([
            'include' => array_map('intval', $children),
            'limit' => count($children),
            'status' => 'publish',
            'type' => 'variation',
        ]);

        $total = 0;
        $seen = false;

        foreach ((is_array($variations) ? $variations : []) as $variation) {
            if (! is_callable([$variation, 'get_stock_quantity'])) {
                continue;
            }

            $qty = $variation->get_stock_quantity();

            if ($qty !== null) {
                $total += (int) $qty;
                $seen = true;
            }
        }

        return $seen ? $total : null;
    }
}

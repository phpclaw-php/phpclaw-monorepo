<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Service;

/**
 * Utility for parsing comma-separated admin field values into trimmed string arrays.
 */
final class CsvList
{
    /**
     * Parse a comma-separated string into a trimmed, non-empty string array.
     *
     * @param  string  $raw  Raw comma-separated value (e.g. from admin config).
     * @return array<int, string> Ordered list of non-empty trimmed values.
     */
    public static function parse(string $raw): array
    {
        return $raw !== ''
            ? array_values(array_filter(array_map('trim', explode(',', $raw))))
            : [];
    }
}

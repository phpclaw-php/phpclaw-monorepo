<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Service;

/**
 * Host-safety checks that block SSRF to loopback, link-local, private, and known-local hosts.
 */
final class UrlSafety
{
    /**
     * Whether the URL targets a private/loopback/reserved IP literal or a known-local hostname.
     *
     * @param  string  $url  Absolute URL whose host is checked.
     * @return bool True when the target is internal and must be refused.
     */
    public static function isInternalUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '') {
            return true;
        }

        if ($host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.internal')
            || str_ends_with($host, '.local')
        ) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }

        return false;
    }
}

<?php

declare(strict_types=1);

namespace PhpClaw\Support;

use PhpClaw\Exceptions\ToolException;

/**
 * Shared SSRF guard: resolves a URL's host via DNS and blocks private, loopback, link-local, and internal addresses.
 */
final class SsrfValidator
{
    private const BLOCKED_HOSTS = [
        'localhost',
        '127.',
        '0.',
        '10.',
        '169.254.',
        '192.168.',
        '::1',
        'fc00:',
        'fe80:',
    ];

    private const CGNAT_START = 100 * 256 * 256 * 256 + 64 * 256 * 256;

    private const CGNAT_END = 100 * 256 * 256 * 256 + 127 * 256 * 256 + 255 * 256 + 255;

    private const DEFAULT_PORT_HTTPS = 443;

    private const DEFAULT_PORT_HTTP = 80;

    private const IPV6_LOOPBACK_PACKED = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x01";

    private const IPV6_MAPPED_IPV4_PREFIX = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff";

    private const IPV6_MAPPED_IPV4_PREFIX_LEN = 12;

    private const IPV6_ULA_MASK = 0xFE;

    private const IPV6_ULA_PREFIX = 0xFC;

    private const IPV6_LINK_LOCAL_FIRST_BYTE = 0xFE;

    private const IPV6_LINK_LOCAL_SECOND_BYTE_MASK = 0xC0;

    private const IPV6_LINK_LOCAL_SECOND_BYTE_PREFIX = 0x80;

    /**
     * Validate the URL's host against all SSRF checks and return the vetted host, port, and resolved IP addresses for pinning.
     *
     * @param  string  $url  Full URL to validate.
     * @return array{host: string, port: int, ips: list<string>}
     *
     * @throws ToolException When the host is empty, uses non-standard numeric encoding, resolves to a private/internal address, or cannot be resolved.
     */
    public static function resolveValidated(string $url): array
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        $host = trim($host, '[]');

        if ($host === '') {
            throw new ToolException('Could not extract host from URL.');
        }

        $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));
        $parsedPort = parse_url($url, PHP_URL_PORT);
        $port = is_int($parsedPort) ? $parsedPort : ($scheme === 'https' ? self::DEFAULT_PORT_HTTPS : self::DEFAULT_PORT_HTTP);

        self::assertNotNumericEncoding($host);

        foreach (self::BLOCKED_HOSTS as $blocked) {
            if ($host === $blocked || str_starts_with($host, $blocked)) {
                throw new ToolException(
                    "Requests to '{$host}' are blocked (private/internal address)."
                );
            }
        }

        if (str_contains($host, ':')) {
            self::assertResolvedIpv6($host, $host);

            return ['host' => $host, 'port' => $port, 'ips' => [$host]];
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            self::assertBlockedIpv4($host, $host);

            return ['host' => $host, 'port' => $port, 'ips' => [$host]];
        }

        return self::resolveHostname($host, $port);
    }

    /**
     * Assert that the URL's host resolves only to public addresses.
     *
     * @param  string  $url  Full URL to validate.
     * @return void
     *
     * @throws ToolException When the host is empty, uses non-standard numeric encoding, or resolves to a private/internal address.
     */
    public static function assertPublicHost(string $url): void
    {
        self::resolveValidated($url);
    }

    /**
     * Non-throwing wrapper for callers that skip rather than abort (webhooks).
     *
     * @param  string  $url  Full URL to validate.
     * @return bool True when the host is public; false when blocked or unparseable.
     */
    public static function isPublicHost(string $url): bool
    {
        try {
            self::assertPublicHost($url);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Build a CURLOPT_RESOLVE pin entry string, or null when the host is already an IP literal.
     *
     * @param  array{host: string, port: int, ips: list<string>}  $resolved  Output of resolveValidated().
     * @return string|null Pin entry string, or null for IP-literal hosts.
     */
    public static function pinEntry(array $resolved): ?string
    {
        $host = (string) ($resolved['host'] ?? '');
        $port = (int) ($resolved['port'] ?? self::DEFAULT_PORT_HTTPS);
        $ips = (array) ($resolved['ips'] ?? []);

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false || str_contains($host, ':')) {
            return null;
        }

        if ($ips === []) {
            return null;
        }

        return $host.':'.$port.':'.implode(',', $ips);
    }

    /**
     * Resolve a hostname via DNS, validate every resolved address, and return the vetted result.
     *
     * @param  string  $host  Validated hostname (not an IP literal).
     * @param  int  $port  Port derived from the URL scheme or explicit port.
     * @return array{host: string, port: int, ips: list<string>}
     *
     * @throws ToolException When the host cannot be resolved or resolves to a private/internal address.
     */
    private static function resolveHostname(string $host, int $port): array
    {
        $ipv4Addresses = @gethostbynamel($host);
        $ipv6Records = @dns_get_record($host, DNS_AAAA);
        $ipv4Addresses = is_array($ipv4Addresses) ? $ipv4Addresses : [];
        $ipv6Records = is_array($ipv6Records) ? $ipv6Records : [];

        if ($ipv4Addresses === [] && $ipv6Records === []) {
            throw new ToolException(
                "Could not resolve host '{$host}': unresolvable hosts are blocked."
            );
        }

        $ips = [];

        foreach ($ipv4Addresses as $ipv4) {
            self::assertBlockedIpv4($ipv4, $host);
            $ips[] = $ipv4;
        }

        foreach ($ipv6Records as $record) {
            $ipv6 = strtolower((string) ($record['ipv6'] ?? ''));
            if ($ipv6 !== '') {
                self::assertResolvedIpv6($ipv6, $host);
                $ips[] = $ipv6;
            }
        }

        return ['host' => $host, 'port' => $port, 'ips' => $ips];
    }

    /**
     * Throw when the host uses non-standard numeric IP encoding.
     *
     * @param  string  $host  Host string extracted from the URL.
     * @return void
     *
     * @throws ToolException When the host matches a decimal, hex, dotted-hex, or dotted-octal pattern.
     */
    private static function assertNotNumericEncoding(string $host): void
    {
        $blocked = (bool) preg_match('/^[0-9]+$/', $host)
            || (bool) preg_match('/^0x[0-9a-f]+$/i', $host);

        if (! $blocked && str_contains($host, '.')) {
            foreach (explode('.', $host) as $part) {
                if (preg_match('/^0x[0-9a-f]+$/i', $part) || preg_match('/^0[0-7]+$/', $part)) {
                    $blocked = true;
                    break;
                }
            }
        }

        if ($blocked) {
            throw new ToolException(
                "Requests to '{$host}' are blocked (non-standard IP encoding)."
            );
        }
    }

    /**
     * Validate an IPv4 address against all blocked ranges using filter flags and an explicit CGNAT check.
     *
     * @param  string  $ip  Resolved IPv4 address (or IPv6 string when called from IPv4-mapped context).
     * @param  string  $host  Original host name (used in the error message).
     * @return void
     *
     * @throws ToolException When the address falls outside a public IPv4 range.
     */
    private static function assertBlockedIpv4(string $ip, string $host): void
    {
        $long = ip2long($ip);

        if ($long === false) {
            self::assertResolvedIpv6($ip, $host);

            return;
        }

        if ($long >= self::CGNAT_START && $long <= self::CGNAT_END) {
            throw new ToolException(
                "Requests to '{$host}' are blocked (resolves to CGNAT address {$ip})."
            );
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new ToolException(
                "Requests to '{$host}' are blocked (resolves to private IP {$ip})."
            );
        }
    }

    /**
     * Validate an IPv6 address against all blocked ranges.
     *
     * @param  string  $ip  IPv6 literal or AAAA result.
     * @param  string  $host  Original host name (used in the error message).
     * @return void
     *
     * @throws ToolException When the address is loopback, ULA, link-local, or IPv4-mapped to a blocked range.
     */
    private static function assertResolvedIpv6(string $ip, string $host): void
    {
        $packed = inet_pton($ip);

        if ($packed === false || strlen($packed) !== 16) {
            return;
        }

        $firstByte = ord($packed[0]);
        $secondByte = ord($packed[1]);

        if ($packed === self::IPV6_LOOPBACK_PACKED) {
            throw new ToolException(
                "Requests to '{$host}' are blocked (resolves to IPv6 loopback)."
            );
        }

        if (($firstByte & self::IPV6_ULA_MASK) === self::IPV6_ULA_PREFIX) {
            throw new ToolException(
                "Requests to '{$host}' are blocked (resolves to private IPv6 address {$ip})."
            );
        }

        if ($firstByte === self::IPV6_LINK_LOCAL_FIRST_BYTE && ($secondByte & self::IPV6_LINK_LOCAL_SECOND_BYTE_MASK) === self::IPV6_LINK_LOCAL_SECOND_BYTE_PREFIX) {
            throw new ToolException(
                "Requests to '{$host}' are blocked (resolves to link-local IPv6 address {$ip})."
            );
        }

        if (substr($packed, 0, self::IPV6_MAPPED_IPV4_PREFIX_LEN) === self::IPV6_MAPPED_IPV4_PREFIX) {
            $ipv4 = inet_ntop(substr($packed, self::IPV6_MAPPED_IPV4_PREFIX_LEN));
            if ($ipv4 !== false) {
                self::assertBlockedIpv4($ipv4, $host);
            }
        }
    }
}

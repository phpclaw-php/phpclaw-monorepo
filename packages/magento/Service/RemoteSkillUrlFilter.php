<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Service;

/**
 * Filters raw remote-skill-URL input down to valid HTTPS .md/.json URLs.
 */
final class RemoteSkillUrlFilter
{
    /**
     * Keep only HTTPS URLs whose path ends in .md or .json, dropping invalid entries silently.
     *
     * @param  array<int|string, mixed>|string  $raw  Comma-separated string, or an array of URL strings.
     * @return string Comma-joined valid URLs, or '' when none survive.
     */
    public static function filter(array|string $raw): string
    {
        $items = is_array($raw) ? array_map('strval', $raw) : CsvList::parse($raw);

        $valid = array_values(array_filter(
            array_map('trim', $items),
            static fn (string $url): bool => self::isValid($url),
        ));

        return implode(',', array_slice($valid, 0, 50));
    }

    /**
     * Whether a single URL is HTTPS and its path ends in .md or .json (case-insensitive).
     *
     * @param  string  $url  Candidate URL.
     * @return bool
     */
    private static function isValid(string $url): bool
    {
        if ($url === '' || ! preg_match('~^https://~i', $url) || UrlSafety::isInternalUrl($url)) {
            return false;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);

        return (bool) preg_match('/\.(md|json)$/i', $path);
    }
}

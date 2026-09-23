<?php

declare(strict_types=1);

namespace PhpClaw\Skills;

use PhpClaw\Config\EnvVars;
use PhpClaw\Support\Log;
use PhpClaw\Support\SsrfValidator;

/**
 * Loads skills (phpClaw JSON collection or raw SKILL.md, auto-detected) from an HTTPS URL into the SkillRegistry; SSRF-guarded, 512KB cap, 24h cache.
 */
final class RemoteSkillLoader
{
    private const CACHE_SUBDIR = 'phpclaw-skill-cache';

    private const CACHE_TTL = 86400;

    private const MAX_BYTES = 524288;

    private const TIMEOUT = 8;

    private const TAG_MIN_LENGTH = 4;

    private const MAX_TAGS = 25;

    /**
     * Load a remote skill collection or single markdown skill into the registry.
     *
     * @param  string  $url  HTTPS URL of a JSON collection or a single SKILL.md.
     * @param  string  $format  'auto', 'json', or 'markdown'.
     * @return int Number of skills registered.
     */
    public static function load(string $url, string $format = 'auto'): int
    {
        if (! str_starts_with($url, 'https://')) {
            Log::warning("[phpClaw] RemoteSkillLoader: HTTPS required, skipped: {$url}");

            return 0;
        }

        try {
            $resolved = SsrfValidator::resolveValidated($url);
        } catch (\Throwable $e) {
            Log::warning('[phpClaw] RemoteSkillLoader: '.$e->getMessage());

            return 0;
        }

        $raw = self::fetchCached($url, $resolved);
        if ($raw === null) {
            return 0;
        }

        $isJson = $format === 'json'
            || ($format === 'auto' && str_starts_with(ltrim($raw), '{'));

        return $isJson
            ? self::loadFromJson($raw)
            : self::loadFromMarkdown($url, $raw);
    }

    /**
     * Build the cURL option array for a pinned HTTPS fetch. Extracted as a public static method so tests can assert individual options (including CURLOPT_RESOLVE and CURLOPT_URL) without executing a real network request.
     *
     * @param  string  $url  Request URL: kept as the hostname URL to preserve Host header and SNI.
     * @param  array{host: string, port: int, ips: list<string>}  $resolved  Validated address set from SsrfValidator::resolveValidated().
     * @return array<int, mixed>
     */
    public static function buildCurlOptions(string $url, array $resolved): array
    {
        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_FAILONERROR => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'phpClaw-RemoteSkillLoader/1.0',
            CURLOPT_HTTPHEADER => ['Accept: application/json, text/markdown, text/plain'],
        ];

        $pin = SsrfValidator::pinEntry($resolved);
        if ($pin !== null) {
            $opts[CURLOPT_RESOLVE] = [$pin];
        }

        return $opts;
    }

    /**
     * Register every entry from a phpClaw JSON skill collection.
     *
     * @param  string  $json  Raw collection JSON.
     * @return int Number of skills registered.
     */
    private static function loadFromJson(string $json): int
    {
        $data = json_decode($json, true);
        if (! is_array($data) || ! is_array($data['skills'] ?? null)) {
            Log::warning('[phpClaw] RemoteSkillLoader: invalid JSON collection.');

            return 0;
        }

        $loaded = 0;
        foreach ($data['skills'] as $entry) {
            if (! is_array($entry) || empty($entry['name']) || empty($entry['content'])) {
                continue;
            }
            SkillRegistry::register(new ArraySkill(
                name: (string) $entry['name'],
                description: (string) ($entry['description'] ?? ''),
                tags: array_values(array_filter((array) ($entry['tags'] ?? []), 'is_string')),
                content: (string) $entry['content'],
            ));
            $loaded++;
        }

        return $loaded;
    }

    /**
     * Register a single markdown document as one skill, deriving name/description/tags from it.
     *
     * @param  string  $url  Source URL, used to derive the skill slug.
     * @param  string  $markdown  Raw markdown body.
     * @return int Always 1.
     */
    private static function loadFromMarkdown(string $url, string $markdown): int
    {
        $name = self::deriveName($url);

        preg_match('/^#{1,2}\s+(.+)$/m', $markdown, $h);
        $headingDescription = trim($h[1] ?? $name);

        $frontmatterDescription = self::extractFrontmatterDescription($markdown);

        $description = $frontmatterDescription === null
            ? $headingDescription
            : $headingDescription.' '.$frontmatterDescription;

        preg_match_all('/^#{2,3}\s+(.+)$/m', $markdown, $heads);
        preg_match_all('/\*\*([^*\n]{3,30})\*\*/', $markdown, $bolds);

        $tags = [];
        foreach (array_filter(array_merge($heads[1], $bolds[1])) as $phrase) {
            $words = preg_split('/[^a-z0-9]+/', strtolower($phrase), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            foreach ($words as $word) {
                if (strlen($word) >= self::TAG_MIN_LENGTH && ! in_array($word, SkillRegistry::STOPWORDS, true)) {
                    $tags[$word] = true;
                }
            }
        }

        $tags = array_slice(array_keys($tags), 0, self::MAX_TAGS);

        SkillRegistry::register(new ArraySkill(
            name: $name,
            description: $description,
            tags: $tags,
            content: $markdown,
        ));

        return 1;
    }

    /**
     * Reads the frontmatter description: field, which authors write densely with real trigger
     * words; falling back to a heading alone silently drops that content from the match corpus.
     *
     * @param  string  $markdown  Raw markdown body, frontmatter included.
     * @return string|null The frontmatter description, or null when absent.
     */
    private static function extractFrontmatterDescription(string $markdown): ?string
    {
        if (! preg_match('/^---\s*\n(.*?)\n---\s*\n/s', $markdown, $block)) {
            return null;
        }

        if (! preg_match('/^description:\s*(.+)$/m', $block[1], $line)) {
            return null;
        }

        $value = trim($line[1]);
        $value = trim($value, "'\"");

        return $value === '' ? null : $value;
    }

    /**
     * Derive a unique registry name from a markdown skill's URL, falling back to the parent directory when the filename stem is generic.
     *
     * @param  string  $url  Source URL to derive the skill slug from.
     * @return string Unique, slugified skill name.
     */
    private static function deriveName(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $stem = strtolower(trim((string) preg_replace('/\.[^.\/]+$/', '', basename($path)), '/'));

        $slug = in_array($stem, ['', 'skill', 'index', 'readme'], true)
            ? strtolower(trim((string) basename(dirname($path)), '/'))
            : $stem;

        $name = trim((string) preg_replace('/[^a-z0-9]+/', '_', $slug), '_');

        return $name === '' ? 'remote_skill_'.substr(md5($url), 0, 8) : $name;
    }

    /**
     * Fetch the URL body, serving from a 24h file cache when fresh.
     *
     * @param  string  $url  HTTPS URL to fetch.
     * @param  array{host: string, port: int, ips: list<string>}  $resolved  Validated address set for IP pinning.
     * @return string|null Body, or null on fetch failure or oversize.
     */
    private static function fetchCached(string $url, array $resolved): ?string
    {
        $dir = self::cacheDir();
        $cache = $dir.'/'.md5($url).'.cache';
        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (is_file($cache) && (time() - (int) filemtime($cache)) < self::CACHE_TTL) {
            $hit = file_get_contents($cache);

            return $hit === false ? null : $hit;
        }

        $raw = self::httpGet($url, $resolved);
        if ($raw === null || strlen($raw) > self::MAX_BYTES) {
            Log::warning("[phpClaw] RemoteSkillLoader: fetch failed or >512KB: {$url}");

            return null;
        }

        if (@file_put_contents($cache, $raw) !== false) {
            @chmod($cache, 0600);
        }

        return $raw;
    }

    /**
     * Execute a pinned cURL GET and return the raw response body, or null on any failure.
     *
     * @param  string  $url  HTTPS URL to request.
     * @param  array{host: string, port: int, ips: list<string>}  $resolved  Validated address set for IP pinning.
     * @return string|null Response body string, or null when curl_exec fails (including FAILONERROR on 4xx/5xx).
     */
    private static function httpGet(string $url, array $resolved): ?string
    {
        $ch = curl_init();
        curl_setopt_array($ch, self::buildCurlOptions($url, $resolved));
        $raw = curl_exec($ch);
        curl_close($ch);

        if ($raw === false) {
            return null;
        }

        return is_string($raw) ? $raw : null;
    }

    /**
     * Resolve the cache directory: PHPCLAW_CACHE_DIR override, else /tmp.
     *
     * @return string Absolute cache directory path for remote skills.
     */
    private static function cacheDir(): string
    {
        $base = EnvVars::get(EnvVars::PHPCLAW_CACHE_DIR, '/tmp');

        return rtrim($base, '/').'/'.self::CACHE_SUBDIR;
    }
}

<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Admin;

/**
 * Auto-updater for the phpClaw OpenCart extension.
 */
final class AutoUpdater
{
    private const TIMEOUT_SECONDS = 5;

    private readonly \Closure $fetcher;

    /**
     * Bind the running version, the update endpoint, and the HTTP fetcher used to check it.
     *
     * @param  string  $currentVersion  Running version string (e.g. '0.1.0').
     * @param  string  $updateServerUrl  URL of the update check endpoint.
     * @param  (callable(string, mixed): (string|false))|null  $fetcher  HTTP fetcher; defaults to file_get_contents.
     */
    public function __construct(
        private readonly string $currentVersion,
        private readonly string $updateServerUrl,
        ?callable $fetcher = null,
    ) {
        $this->fetcher = $fetcher !== null
            ? \Closure::fromCallable($fetcher)
            : static fn (string $url, mixed $context): string|false => @file_get_contents($url, context: $context);
    }

    /**
     * Check for a newer version of phpClaw OpenCart.
     *
     * @return array{version: string, download_url: string, changelog_url: string, released_at: string}|null
     */
    public function checkForUpdate(): ?array
    {
        if ($this->updateServerUrl === '') {
            return null;
        }

        try {
            $url = $this->updateServerUrl.'?'.http_build_query([
                'adapter' => 'opencart',
                'current' => $this->currentVersion,
                'php' => PHP_VERSION,
            ]);

            $context = stream_context_create([
                'http' => [
                    'timeout' => self::TIMEOUT_SECONDS,
                    'ignore_errors' => true,
                    'user_agent' => 'phpClaw-OpenCart/'.$this->currentVersion,
                ],
            ]);

            $raw = ($this->fetcher)($url, $context);

            if ($raw === false || $raw === '') {
                return null;
            }

            $data = json_decode($raw, associative: true);

            if (! is_array($data) || empty($data['version'])) {
                return null;
            }

            if (! version_compare($data['version'], $this->currentVersion, '>')) {
                return null;
            }

            return [
                'version' => (string) $data['version'],
                'download_url' => self::httpsUrlOrEmpty($data['download_url'] ?? ''),
                'changelog_url' => self::httpsUrlOrEmpty($data['changelog_url'] ?? '') ?: 'https://phpclaw.ai/docs',
                'released_at' => (string) ($data['released_at'] ?? ''),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Return a remote-supplied URL only when it is an absolute https URL, so a hostile
     * update response cannot place a javascript: or data: scheme into an admin link.
     *
     * @param  mixed  $raw  Raw value from the update-server response.
     * @return string The https URL, or an empty string.
     */
    private static function httpsUrlOrEmpty(mixed $raw): string
    {
        $url = is_string($raw) ? trim($raw) : '';

        if ($url === '' || ! str_starts_with(strtolower($url), 'https://')) {
            return '';
        }

        return filter_var($url, FILTER_VALIDATE_URL) === false ? '' : $url;
    }

    /**
     * Whether the installed version is the latest available.
     *
     * @return bool
     */
    public function isUpToDate(): bool
    {
        return $this->checkForUpdate() === null;
    }
}

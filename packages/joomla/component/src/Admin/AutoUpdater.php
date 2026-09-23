<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Component\Administrator\Admin;

/**
 * Auto-updater helper - checks for new phpClaw Joomla adapter releases.
 */
final class AutoUpdater
{
    private const UPDATE_URL = 'https://phpclaw.ai/api/joomla-update';

    private const TIMEOUT_SECONDS = 5;

    private readonly mixed $fetcher;

    /**
     * Bind the running version and the HTTP fetcher used to check for updates.
     *
     * @param  string  $currentVersion  Running version string (e.g. '0.1.0').
     * @param  callable|null  $fetcher  Injectable HTTP fetcher (defaults to file_get_contents).
     */
    public function __construct(
        private readonly string $currentVersion,
        ?callable $fetcher = null,
    ) {
        $this->fetcher = $fetcher ?? 'file_get_contents';
    }

    /**
     * Check if a newer version is available.
     *
     * @return array{version: string, url: string, notes: string}|null
     */
    public function checkForUpdate(): ?array
    {
        $current = $this->currentVersion;
        $url = self::UPDATE_URL.'?adapter=joomla&current='.$current;

        try {
            $ctx = stream_context_create(['http' => ['timeout' => self::TIMEOUT_SECONDS]]);
            $body = ($this->fetcher)($url, false, $ctx);

            if ($body === false || $body === '') {
                return null;
            }

            $data = json_decode($body, associative: true);

            if (! is_array($data) || empty($data['version'])) {
                return null;
            }

            if (version_compare((string) $data['version'], $current, '<=')) {
                return null;
            }

            return [
                'version' => (string) $data['version'],
                'url' => (string) ($data['url'] ?? ''),
                'notes' => (string) ($data['notes'] ?? ''),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Static convenience wrapper around the update check.
     *
     * @param  string  $currentVersion  Running version string (e.g. '0.1.0').
     * @param  callable|null  $fetcher  Optional HTTP fetcher (defaults to file_get_contents).
     * @return array{version: string, url: string, notes: string}|null
     */
    public static function check(string $currentVersion, ?callable $fetcher = null): ?array
    {
        return (new self($currentVersion, $fetcher))->checkForUpdate();
    }
}

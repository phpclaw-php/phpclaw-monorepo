<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Admin;

/**
 * Auto-updater: checks the phpClaw update server and returns newer-version metadata.
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
     * @return void
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
     * Check for a newer version of phpClaw PrestaShop.
     *
     * @return array{version: string, download_url: string, changelog_url: string, released_at: string}|null
     */
    public function checkForUpdate(): ?array
    {
        if ($this->updateServerUrl === '') {
            return null;
        }

        $isHttps = (bool) preg_match('~^https://~i', $this->updateServerUrl);
        $isLoopback = (bool) preg_match('~^http://(localhost|127\.0\.0\.1)(:\d+)?/~i', $this->updateServerUrl);
        if (! $isHttps && ! $isLoopback) {
            return null;
        }

        try {
            $url = $this->updateServerUrl.'?'.http_build_query([
                'adapter' => 'prestashop',
                'current' => $this->currentVersion,
                'php' => PHP_VERSION,
            ]);

            $context = stream_context_create([
                'http' => [
                    'timeout' => self::TIMEOUT_SECONDS,
                    'ignore_errors' => true,
                    'user_agent' => 'phpClaw-PrestaShop/'.$this->currentVersion,
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
                'download_url' => (string) ($data['download_url'] ?? ''),
                'changelog_url' => (string) ($data['changelog_url'] ?? 'https://phpclaw.ai/docs'),
                'released_at' => (string) ($data['released_at'] ?? ''),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Whether the current installation is up to date.
     *
     * @return bool
     */
    public function isUpToDate(): bool
    {
        return $this->checkForUpdate() === null;
    }
}

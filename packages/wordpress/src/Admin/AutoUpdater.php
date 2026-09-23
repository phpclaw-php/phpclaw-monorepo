<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Admin;

/**
 * Self-hosted auto-update for phpClaw WordPress plugin.
 */
final class AutoUpdater
{
    private const TRUSTED_UPDATE_HOSTS = [
        'phpclaw.ai', 'www.phpclaw.ai',
        'github.com', 'objects.githubusercontent.com',
        'codeload.github.com', 'raw.githubusercontent.com',
    ];

    private const FAILED_CHECK_CACHE_SECONDS = 3600;

    private string $pluginSlug;

    /**
     * Store the plugin file path, installed version, and update endpoint.
     *
     * @param  string  $pluginFile  Absolute path to the main plugin file (e.g. __FILE__).
     * @param  string  $currentVersion  Currently installed plugin version string.
     * @param  string  $updateUrl  Remote URL that returns the update JSON payload.
     */
    private function __construct(
        private readonly string $pluginFile,
        private readonly string $currentVersion,
        private readonly string $updateUrl,
    ) {
        $this->pluginSlug = plugin_basename($this->pluginFile);
    }

    /**
     * Initialize the auto-updater. Call once from Plugin::registerWordPressHooks().
     *
     * @param  string  $pluginFile  Absolute path to the main plugin file.
     * @param  string  $currentVersion  Currently installed plugin version string.
     * @param  string  $updateUrl  Remote URL that returns the update JSON payload.
     * @return void
     */
    public static function init(string $pluginFile, string $currentVersion, string $updateUrl): void
    {
        if ($updateUrl === '') {
            return;
        }

        $instance = new self($pluginFile, $currentVersion, $updateUrl);

        add_filter('pre_set_site_transient_update_plugins', [$instance, 'checkForUpdate']);
        add_filter('plugins_api', [$instance, 'pluginInfo'], 10, 3);
        add_filter('upgrader_post_install', [$instance, 'afterInstall'], 10, 3);
    }

    /**
     * Check the remote update server for a newer version.
     *
     * @param  object  $transient  The update_plugins transient data.
     * @return object
     */
    public function checkForUpdate(object $transient): object
    {
        if (empty($transient->checked)) {
            return $transient;
        }

        $remote = $this->fetchRemoteInfo();

        if ($remote === null) {
            return $transient;
        }

        if (! $this->isTrustedPackageUrl($remote['download_url'])) {
            error_log('phpClaw AutoUpdater: refusing update: download_url is not an approved HTTPS host.');

            return $transient;
        }

        if (version_compare($this->currentVersion, $remote['version'], '<')) {
            $transient->response[$this->pluginSlug] = (object) [
                'slug' => dirname($this->pluginSlug),
                'plugin' => $this->pluginSlug,
                'new_version' => $remote['version'],
                'package' => $remote['download_url'],
                'url' => $remote['homepage'] ?? 'https://phpclaw.ai',
                'requires_php' => $remote['requires_php'] ?? '8.1',
                'requires' => $remote['requires'] ?? '6.0',
                'tested' => $remote['tested'] ?? '',
            ];
        }

        return $transient;
    }

    /**
     * Provide plugin info for the "View Details" popup in wp-admin.
     *
     * @param  false|object  $result  Existing result from an earlier plugins_api filter, or false.
     * @param  string  $action  The plugins_api action being requested.
     * @param  object  $args  Arguments for the request, including the plugin slug.
     * @return false|object The populated plugin-info object, or the untouched result when not applicable.
     */
    public function pluginInfo(false|object $result, string $action, object $args): false|object
    {
        if ($action !== 'plugin_information') {
            return $result;
        }

        if (! isset($args->slug) || $args->slug !== dirname($this->pluginSlug)) {
            return $result;
        }

        $remote = $this->fetchRemoteInfo();

        if ($remote === null) {
            return $result;
        }

        if (! $this->isTrustedPackageUrl($remote['download_url'])) {
            return $result;
        }

        return (object) [
            'name' => $remote['name'] ?? 'phpClaw AI Agent',
            'slug' => dirname($this->pluginSlug),
            'version' => $remote['version'],
            'author' => '<a href="https://phpclaw.ai">phpClaw</a>',
            'homepage' => $remote['homepage'] ?? 'https://phpclaw.ai',
            'download_link' => $remote['download_url'],
            'requires_php' => $remote['requires_php'] ?? '8.1',
            'requires' => $remote['requires'] ?? '6.0',
            'tested' => $remote['tested'] ?? '',
            'sections' => [
                'description' => $remote['description'] ?? 'Universal AI agent engine for WordPress.',
                'changelog' => $remote['changelog'] ?? '',
            ],
        ];
    }

    /**
     * After update install, make sure the plugin folder name stays correct.
     *
     * @param  bool  $response  Installation response passed through unchanged.
     * @param  array<string, mixed>  $hookExtra  Extra arguments passed by the upgrader hook.
     * @param  array<string, mixed>  $result  Installation result, including the destination path.
     * @return array<string, mixed> The result with the destination corrected to the plugin directory.
     */
    public function afterInstall(bool $response, array $hookExtra, array $result): array
    {
        global $wp_filesystem;

        $pluginDir = WP_PLUGIN_DIR.'/'.dirname($this->pluginSlug);

        if ($result['destination'] !== $pluginDir && $wp_filesystem instanceof \WP_Filesystem_Base) {
            $wp_filesystem->move($result['destination'], $pluginDir);
            $result['destination'] = $pluginDir;
        }

        if (is_plugin_active($this->pluginSlug)) {
            activate_plugin($this->pluginSlug);
        }

        return $result;
    }

    /**
     * Whether a package/download URL is an HTTPS URL on an approved distribution host.
     *
     * @param  string  $url  Candidate package download URL from the update server.
     * @return bool True when the URL is HTTPS and its host is on the trusted allowlist.
     */
    private function isTrustedPackageUrl(string $url): bool
    {
        if (! str_starts_with(strtolower($url), 'https://')) {
            return false;
        }

        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));

        return in_array($host, self::TRUSTED_UPDATE_HOSTS, true);
    }

    /**
     * Fetch update info from the remote server.
     *
     * @return array<string, string>|null
     */
    private function fetchRemoteInfo(): ?array
    {
        if (! str_starts_with(strtolower($this->updateUrl), 'https://')) {
            error_log('phpClaw AutoUpdater: update endpoint must use HTTPS: check skipped.');

            return null;
        }

        $cacheKey = 'phpclaw_update_check';
        $cached = get_transient($cacheKey);

        if ($cached !== false) {
            return is_array($cached) ? $cached : null;
        }

        $response = wp_remote_get($this->updateUrl, [
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/json',
                'X-PhpClaw-Version' => $this->currentVersion,
            ],
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            $errMsg = is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_response_code($response);
            error_log("phpClaw AutoUpdater: update check failed: {$errMsg}");
            set_transient($cacheKey, 'none', self::FAILED_CHECK_CACHE_SECONDS);

            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (! is_array($body) || ! isset($body['version'], $body['download_url'])) {
            error_log('phpClaw AutoUpdater: update server returned unexpected response body.');
            set_transient($cacheKey, 'none', 12 * HOUR_IN_SECONDS);

            return null;
        }

        set_transient($cacheKey, $body, 12 * HOUR_IN_SECONDS);

        return $body;
    }
}

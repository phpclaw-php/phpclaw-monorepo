<?php

declare(strict_types=1);

namespace PhpClaw\Cloud;

use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Hooks\HookRegistry;

/**
 * Activates cloud features for the given subscription key.
 */
final class CloudManager
{
    private const MANIFEST_PATH = '/'.CloudHttp::API_VERSION.'/manifest';

    private const CACHE_TTL_SECONDS = 3600;

    private const CACHE_FILE_PREFIX = 'phpclaw_manifest_';

    private const CACHE_FILE_SUFFIX = '.json';

    private const CACHE_HASH_ALGO = 'sha256';

    private const CACHE_MAC_ALGO = 'sha256';

    private const CACHE_FILE_MODE = 0600;

    private const HOOK_PRIORITY = 100;

    private const GUARD_PRIORITY_DEFAULT = 50;

    private const MANIFEST_CONNECT_TIMEOUT_S = CloudHttp::GET_CONNECT_TIMEOUT_S;

    private const MANIFEST_TIMEOUT_S = CloudHttp::GET_TIMEOUT_S;

    private static array $bootedKeys = [];

    /**
     * Activate cloud features for the given key.
     *
     * @param  string  $key  phpClaw Cloud API key; '' short-circuits to no-op.
     * @param  list<string>  $disable  Feature names to exclude from activation, plus hide_ names that keep
     *                                 content off the cloud (hide_inputs also skips the scan guards).
     * @param  string  $signingSecret  Shared secret for verifying signed scan responses; '' leaves
     *                                 scan verification off (pre-signing behaviour).
     * @param  bool  $failClosed  When true, scan guards block messages the cloud cannot answer; default
     *                            false preserves availability (message allowed on scan transport failure).
     * @return void
     */
    public static function boot(string $key, array $disable = [], string $signingSecret = '', bool $failClosed = false): void
    {
        if ($key === '' || isset(self::$bootedKeys[$key])) {
            return;
        }
        self::$bootedKeys[$key] = true;

        $manifest = self::resolveManifest($key);

        self::registerHooks($manifest, $key, $disable);
        if (! in_array(CloudPayloadBuilder::HIDE_INPUTS, $disable, strict: true)) {
            self::registerGuards($manifest, $key, $disable, $signingSecret, $failClosed);
        }
    }

    /**
     * Clear the set of booted keys, test isolation only.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$bootedKeys = [];
    }

    /**
     * Register lifecycle hooks per the manifest: wildcard if custom events are enabled, otherwise one CloudWebhookHook per named event.
     *
     * @param  CloudManifest  $manifest  Resolved manifest dictating which hooks to register.
     * @param  string  $key  Cloud key passed through to each CloudWebhookHook.
     * @param  list<string>  $disable  Feature names to exclude from activation.
     * @return void
     */
    private static function registerHooks(
        CloudManifest $manifest,
        string $key,
        array $disable,
    ): void {
        if ($manifest->customEventsEnabled()) {
            if ($manifest->activeHookEvents($disable) === []) {
                return;
            }
            HookRegistry::onAny(
                new CloudWebhookHook($key, CloudWebhookHook::ANY_EVENT_MARKER, $disable),
                priority: self::HOOK_PRIORITY,
            );

            return;
        }

        foreach ($manifest->activeHookEvents($disable) as $event) {
            HookRegistry::on(
                $event,
                new CloudWebhookHook($key, $event, $disable),
                priority: self::HOOK_PRIORITY,
            );
        }
    }

    /**
     * Register cloud-scanning guards per the manifest.
     *
     * @param  CloudManifest  $manifest  Resolved manifest dictating which guards to register.
     * @param  string  $key  Cloud key passed through to each CloudScanGuard.
     * @param  list<string>  $disable  Feature names to exclude from activation.
     * @param  string  $signingSecret  Shared secret each CloudScanGuard verifies signed responses with.
     * @param  bool  $failClosed  Passed through to each CloudScanGuard; true blocks on scan transport failure.
     * @return void
     */
    private static function registerGuards(
        CloudManifest $manifest,
        string $key,
        array $disable,
        string $signingSecret,
        bool $failClosed = false,
    ): void {
        foreach ($manifest->activeGuardFeatures($disable) as $feature => $config) {
            GuardRegistry::register(
                new CloudScanGuard($key, $feature, $signingSecret, $failClosed),
                priority: (int) ($config['priority'] ?? self::GUARD_PRIORITY_DEFAULT),
            );
        }
    }

    /**
     * Resolve the active manifest: serve the on-disk cache while it is fresh, otherwise fetch from the API, otherwise fall back to the cache at any age, otherwise an empty manifest.
     *
     * @param  string  $key  Cloud key used for both the fetch and the per-key cache path.
     * @return CloudManifest Fresh-enough cached manifest, else a newly fetched one, else the stale cache, else empty.
     */
    private static function resolveManifest(string $key): CloudManifest
    {
        $cacheFile = self::cacheFilePath($key);
        $cached = self::readCache($cacheFile, $key);

        if ($cached !== null) {
            return $cached;
        }

        $fresh = self::fetchFromApi($key);

        if ($fresh !== null) {
            self::writeCache($cacheFile, $fresh, $key);

            return $fresh;
        }

        return self::readCache($cacheFile, $key, maxAge: PHP_INT_MAX) ?? CloudManifest::empty();
    }

    /**
     * Fetch the manifest from the cloud API. Returns null on any transport or decode failure.
     *
     * @param  string  $key  Cloud key sent as the bearer token on the GET request.
     * @return CloudManifest|null Manifest on success, null on any transport/parse error.
     */
    private static function fetchFromApi(string $key): ?CloudManifest
    {
        $data = CloudHttp::get(
            CloudHttp::baseUrl().self::MANIFEST_PATH,
            $key,
            connectTimeout: self::MANIFEST_CONNECT_TIMEOUT_S,
            timeout: self::MANIFEST_TIMEOUT_S,
        );

        return $data !== null ? CloudManifest::fromArray($data) : null;
    }

    /**
     * Read a cached manifest if present, younger than $maxAge seconds, and carrying a valid HMAC; a tampered or foreign file (wrong or missing MAC) is treated as a cache miss so it is refetched instead of trusted.
     *
     * @param  string  $file  Absolute path to the cache file.
     * @param  string  $key  Cloud key used to verify the envelope HMAC.
     * @param  int  $maxAge  Maximum acceptable file age in seconds.
     * @return CloudManifest|null Manifest on success, null when missing / stale / unreadable / unverified / invalid JSON.
     */
    private static function readCache(string $file, string $key, int $maxAge = self::CACHE_TTL_SECONDS): ?CloudManifest
    {
        if (! file_exists($file)) {
            return null;
        }

        $mtime = @filemtime($file);

        if ($mtime === false || (time() - $mtime) > $maxAge) {
            return null;
        }

        $content = @file_get_contents($file);

        if ($content === false || $content === '') {
            return null;
        }

        $payload = self::verifyEnvelope($content, $key);

        if ($payload === null) {
            return null;
        }

        try {
            $data = json_decode($payload, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return CloudManifest::fromArray($data);
    }

    /**
     * Verify an HMAC envelope and return the inner payload, or null when the envelope is malformed or the MAC fails.
     *
     * @param  string  $content  Raw file contents, a JSON envelope of the shape {mac, data}.
     * @param  string  $key  Cloud key the MAC is verified against.
     * @return string|null The verified inner payload string, or null on any malformed / tampered input.
     */
    private static function verifyEnvelope(string $content, string $key): ?string
    {
        try {
            $envelope = json_decode($content, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($envelope)) {
            return null;
        }

        $data = $envelope['data'] ?? null;
        $mac = $envelope['mac'] ?? null;

        if (! is_string($data) || ! is_string($mac)) {
            return null;
        }

        $expected = hash_hmac(self::CACHE_MAC_ALGO, $data, $key);

        if (! hash_equals($expected, $mac)) {
            return null;
        }

        return $data;
    }

    /**
     * Persist a manifest as an owner-only HMAC envelope via an O_EXCL temp file that is chmod'd owner-only before content then atomically renamed in, so a co-tenant can neither read it mid-write nor hijack the path with a symlink; failures are silently ignored.
     *
     * @param  string  $file  Absolute path of the cache file to write.
     * @param  CloudManifest  $manifest  Manifest to serialise.
     * @param  string  $key  Cloud key used to sign the envelope.
     * @return void
     */
    private static function writeCache(string $file, CloudManifest $manifest, string $key): void
    {
        $payload = json_encode($manifest->toArray());

        if ($payload === false) {
            return;
        }

        $envelope = json_encode([
            'mac' => hash_hmac(self::CACHE_MAC_ALGO, $payload, $key),
            'data' => $payload,
        ]);

        if ($envelope === false) {
            return;
        }

        try {
            $suffix = bin2hex(random_bytes(8));
        } catch (\Throwable) {
            return;
        }

        $tmp = $file.'.'.$suffix.'.tmp';
        $handle = @fopen($tmp, 'x');

        if ($handle === false) {
            return;
        }

        @chmod($tmp, self::CACHE_FILE_MODE);
        $written = @fwrite($handle, $envelope) !== false;
        @fclose($handle);

        if (! $written || ! @rename($tmp, $file)) {
            @unlink($tmp);
        }
    }

    /**
     * Per-key cache path under the system temp dir, namespaced by SHA-256 of the key.
     *
     * @param  string  $key  Cloud key used as the source for the per-key hash.
     * @return string Absolute path of the cache file for this key.
     */
    private static function cacheFilePath(string $key): string
    {
        return sys_get_temp_dir()
            .DIRECTORY_SEPARATOR
            .self::CACHE_FILE_PREFIX
            .hash(self::CACHE_HASH_ALGO, $key)
            .self::CACHE_FILE_SUFFIX;
    }
}

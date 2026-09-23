<?php

declare(strict_types=1);

namespace PhpClaw\AutoDiscovery;

use Composer\InstalledVersions;

/**
 * Reads every installed Composer package's `extra.phpclaw.{type}` blocks and exposes them as typed lists per phpClaw subsystem.
 */
final class ComposerExtras
{
    private const CANDIDATE_PATHS = [
        '/vendor/composer/installed.json',
        '/../../vendor/composer/installed.json',
        '/../../../vendor/composer/installed.json',
    ];

    private static ?array $cache = null;

    private static ?array $testOverride = null;

    /**
     * All declared `providers` slug → entry across every installed package.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function providers(): array
    {
        return self::collectMap('providers');
    }

    /**
     * All declared `memory` slug → entry across every installed package.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function memory(): array
    {
        return self::collectMap('memory');
    }

    /**
     * All declared `skills` entries across every installed package.
     *
     * @return list<array<string, mixed>>
     */
    public static function skills(): array
    {
        return self::collectList('skills');
    }

    /**
     * All declared `hooks` entries across every installed package.
     *
     * @return list<array<string, mixed>>
     */
    public static function hooks(): array
    {
        return self::collectList('hooks');
    }

    /**
     * All declared `guards` entries across every installed package.
     *
     * @return list<array<string, mixed>>
     */
    public static function guards(): array
    {
        return self::collectList('guards');
    }

    /**
     * All declared `tools` entries across every installed package.
     *
     * @return list<array<string, mixed>>
     */
    public static function tools(): array
    {
        return self::collectList('tools');
    }

    /**
     * Test-only: inject a synthetic `package-name => extras` map, bypassing the live `installed.json` lookup.
     *
     * @param  array<string, array<string, mixed>>  $rawExtrasMap  Raw extras map.
     * @return void
     */
    public static function withTestPayload(array $rawExtrasMap): void
    {
        self::$testOverride = $rawExtrasMap;
        self::$cache = null;
    }

    /**
     * Clear the in-memory cache and any test override. Intended for tests.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$cache = null;
        self::$testOverride = null;
    }

    /**
     * Collect a map-shaped category: providers / memory; last package loaded wins for any given slug.
     *
     * @param  string  $type  Key under `extra.phpclaw` to collect.
     * @return array<string, array<string, mixed>>
     */
    private static function collectMap(string $type): array
    {
        $out = [];

        foreach (self::readAll() as $packageName => $phpclaw) {
            $block = $phpclaw[$type] ?? null;

            if (! is_array($block)) {
                continue;
            }

            foreach ($block as $slug => $entry) {
                if (! is_string($slug) || ! is_array($entry)) {
                    continue;
                }

                $entry['_source'] = $packageName;
                $out[$slug] = $entry;
            }
        }

        return $out;
    }

    /**
     * Collect a list-shaped category: skills / hooks / guards / tools; entries in package-load order.
     *
     * @param  string  $type  Key under `extra.phpclaw` to collect.
     * @return list<array<string, mixed>>
     */
    private static function collectList(string $type): array
    {
        $out = [];

        foreach (self::readAll() as $packageName => $phpclaw) {
            $block = $phpclaw[$type] ?? null;

            if (! is_array($block)) {
                continue;
            }

            foreach ($block as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $entry['_source'] = $packageName;
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * Build the merged `package-name => extras` map; memoised after first call.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function readAll(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        if (self::$testOverride !== null) {
            return self::$cache = self::$testOverride;
        }

        $packages = self::loadInstalledPackages();
        $extras = [];

        foreach ($packages as $pkg) {
            $name = $pkg['name'] ?? null;
            $phpclaw = $pkg['extra']['phpclaw'] ?? null;

            if (! is_string($name) || ! is_array($phpclaw)) {
                continue;
            }

            $extras[$name] = $phpclaw;
        }

        return self::$cache = $extras;
    }

    /**
     * Load the installed-package list, preferring Composer's runtime API.
     *
     * @return list<array<string, mixed>> Each element is one package's installed.json entry.
     */
    private static function loadInstalledPackages(): array
    {
        if (class_exists(InstalledVersions::class)) {
            $packages = [];

            $rawData = InstalledVersions::getAllRawData();

            foreach ($rawData as $entry) {
                if (isset($entry['root']) && is_array($entry['root'])) {
                    $packages[] = self::normaliseRoot($entry['root']);
                }

                $versions = $entry['versions'] ?? [];

                if (! is_array($versions)) {
                    continue;
                }

                foreach ($versions as $name => $info) {
                    if (! is_string($name) || ! is_array($info)) {
                        continue;
                    }

                    $info['name'] = $info['name'] ?? $name;
                    $packages[] = $info;
                }
            }

            return $packages;
        }

        $jsonPath = self::locateInstalledJson();

        if ($jsonPath === null) {
            return [];
        }

        $raw = @file_get_contents($jsonPath);

        if ($raw === false) {
            return [];
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        $packages = $data['packages'] ?? $data;

        return is_array($packages) ? array_values($packages) : [];
    }

    /**
     * Normalise the Composer "root" package shape into the same array shape as `versions` elements.
     *
     * @param  array<string, mixed>  $root  Root package descriptor from getAllRawData().
     * @return array<string, mixed>
     */
    private static function normaliseRoot(array $root): array
    {
        return [
            'name' => $root['name'] ?? '__root__',
            'extra' => $root['extra'] ?? [],
        ];
    }

    /**
     * Hunt for `installed.json` at common paths relative to this file.
     *
     * @return string|null Absolute path on success, null when no candidate exists.
     */
    private static function locateInstalledJson(): ?string
    {
        foreach (self::CANDIDATE_PATHS as $relative) {
            $absolute = __DIR__.$relative;

            if (is_file($absolute)) {
                return $absolute;
            }
        }

        $cwdCandidate = getcwd().'/vendor/composer/installed.json';

        return is_file($cwdCandidate) ? $cwdCandidate : null;
    }
}

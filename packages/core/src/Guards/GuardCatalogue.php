<?php

declare(strict_types=1);

namespace PhpClaw\Guards;

use PhpClaw\AutoDiscovery\Bootstrap;
use PhpClaw\AutoDiscovery\CatalogueInterface;
use PhpClaw\AutoDiscovery\ComposerExtras;
use PhpClaw\AutoDiscovery\DiscoveryCache;
use PhpClaw\Guards\Contracts\GuardInterface;

/**
 * Single source of truth for prompt-injection guards discovered via attributes or `extra.phpclaw.guards` in `composer.json`.
 */
final class GuardCatalogue implements CatalogueInterface
{
    public const DEFAULT_ENTRY_PRIORITY = 50;

    private static array $custom = [];

    /**
     * Every registered guard entry: attribute-discovered entries first, customs after.
     *
     * @return list<array{class: class-string<GuardInterface>, key: string, priority: int, enabled_by_default: bool, label?: string}>
     */
    public static function all(): array
    {
        return [...self::discovered(), ...self::$custom];
    }

    /**
     * Look up a guard by key.
     *
     * @param  string  $key  Storage key.
     * @return array{class: class-string<GuardInterface>, key: string, priority: int, enabled_by_default: bool, label?: string}|null
     */
    public static function find(string $key): ?array
    {
        foreach (self::all() as $entry) {
            if ($entry['key'] === $key) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Return the registered guard class names.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_map(static fn (array $e): string => $e['key'], self::all());
    }

    /**
     * Guard keys that should be enabled by default in fresh installs.
     *
     * @return list<string>
     */
    public static function defaultEnabledKeys(): array
    {
        $out = [];
        foreach (self::all() as $entry) {
            if ($entry['enabled_by_default'] === true) {
                $out[] = $entry['key'];
            }
        }

        return $out;
    }

    /**
     * Register a custom guard at runtime.
     *
     * @param  string  $key  Unique guard key.
     * @param  class-string<GuardInterface>  $class  FQCN of the GuardInterface implementation.
     * @param  int  $priority  Lower runs first. Default: {@see DEFAULT_ENTRY_PRIORITY}.
     * @param  bool  $enabledByDefault  Whether the guard ships enabled on fresh installs.
     * @param  string|null  $label  Optional human-readable label.
     * @return void
     */
    public static function register(
        string $key,
        string $class,
        int $priority = self::DEFAULT_ENTRY_PRIORITY,
        bool $enabledByDefault = false,
        ?string $label = null,
    ): void {
        self::$custom = array_values(array_filter(
            self::$custom,
            static fn (array $e): bool => $e['key'] !== $key,
        ));

        $entry = [
            'class' => $class,
            'key' => $key,
            'priority' => $priority,
            'enabled_by_default' => $enabledByDefault,
        ];

        if ($label !== null) {
            $entry['label'] = $label;
        }

        self::$custom[] = $entry;
    }

    /**
     * Auto-discover guards from every installed Composer package's `extra.phpclaw.guards` block; invalid entries are silently skipped.
     *
     * @return void
     */
    public static function boot(): void
    {
        foreach (ComposerExtras::guards() as $info) {
            if (! isset($info['class']) || ! is_string($info['class'])) {
                continue;
            }

            $class = $info['class'];

            if (! class_exists($class)) {
                continue;
            }

            if (! is_a($class, GuardInterface::class, true)) {
                continue;
            }

            $key = isset($info['key']) && is_string($info['key'])
                ? $info['key']
                : self::keyFromClass($class);

            $priority = isset($info['priority']) && is_int($info['priority'])
                ? $info['priority']
                : self::DEFAULT_ENTRY_PRIORITY;

            $enabledByDefault = ($info['enabled_by_default'] ?? false) === true;

            $label = isset($info['label']) && is_string($info['label']) ? $info['label'] : null;

            self::register($key, $class, $priority, $enabledByDefault, $label);
        }
    }

    /**
     * Instantiate every enabled guard and register it with {@see GuardRegistry} at the priority declared in its catalogue entry.
     *
     * @param  list<string>  $enabledKeys  Guard keys the user has opted in to.
     * @return list<GuardInterface> The instances that were registered.
     */
    public static function activateEnabled(array $enabledKeys): array
    {
        $instances = [];

        foreach ($enabledKeys as $key) {
            if (! is_string($key)) {
                continue;
            }

            $entry = self::find($key);

            if ($entry === null) {
                continue;
            }

            $class = $entry['class'];

            if (! class_exists($class) || ! is_a($class, GuardInterface::class, true)) {
                continue;
            }

            if (GuardRegistry::hasClass($class)) {
                continue;
            }

            $priority = $entry['priority'] ?? self::DEFAULT_ENTRY_PRIORITY;

            $instance = new $class;
            GuardRegistry::register($instance, $priority);
            $instances[] = $instance;
        }

        return $instances;
    }

    /**
     * Convenience: activate built-ins / declared default-enabled guards.
     *
     * @return list<GuardInterface>
     */
    public static function activateDefaults(): array
    {
        return self::activateEnabled(self::defaultEnabledKeys());
    }

    /**
     * Bootstrap integration: invoked by {@see Bootstrap::activateFromSettings()}.
     *
     * @param  array<string, mixed>  $settings  Settings.
     * @return list<string> Always empty (guards register into GuardRegistry).
     */
    public static function activateFromSettings(array $settings): array
    {
        if (array_key_exists('guards_enabled', $settings) && is_array($settings['guards_enabled'])) {
            $keys = array_values(array_filter($settings['guards_enabled'], 'is_string'));
        } else {
            $keys = self::defaultEnabledKeys();
        }

        self::activateEnabled($keys);

        return [];
    }

    /**
     * Clear every custom registration: attribute-discovered entries remain.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$custom = [];
    }

    /**
     * Derive a snake_case key from a class FQCN.
     *
     * @param  class-string  $fqcn  Fqcn.
     * @return string
     */
    private static function keyFromClass(string $fqcn): string
    {
        $short = (string) (strrchr($fqcn, '\\') ?: $fqcn);
        $short = ltrim($short, '\\');
        $short = preg_replace('/Guard$/', '', $short) ?? $short;
        $snake = strtolower(preg_replace('/(?<!^)([A-Z])/', '_$1', $short) ?? $short);

        return trim($snake, '_') ?: 'guard';
    }

    /**
     * Pull attribute-discovered guards out of {@see DiscoveryCache} and normalise into the catalogue's entry shape.
     *
     * @return list<array{class: class-string<GuardInterface>, key: string, priority: int, enabled_by_default: bool, label?: string}>
     */
    private static function discovered(): array
    {
        $out = [];

        foreach (DiscoveryCache::load()['guards'] as $class => $attr) {
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }

            if (! is_a($class, GuardInterface::class, true)) {
                continue;
            }

            $key = (string) ($attr['name'] ?? '');
            if ($key === '') {
                $key = self::keyFromClass($class);
            }

            $entry = [
                'class' => $class,
                'key' => $key,
                'priority' => (int) ($attr['priority'] ?? self::DEFAULT_ENTRY_PRIORITY),
                'enabled_by_default' => (bool) ($attr['enabledByDefault'] ?? false),
            ];

            $label = (string) ($attr['label'] ?? '');
            if ($label !== '') {
                $entry['label'] = $label;
            }

            $out[] = $entry;
        }

        return $out;
    }
}

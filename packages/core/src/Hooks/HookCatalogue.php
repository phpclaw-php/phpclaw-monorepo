<?php

declare(strict_types=1);

namespace PhpClaw\Hooks;

use PhpClaw\AutoDiscovery\Bootstrap;
use PhpClaw\AutoDiscovery\CatalogueInterface;
use PhpClaw\AutoDiscovery\ComposerExtras;
use PhpClaw\AutoDiscovery\DiscoveryCache;
use PhpClaw\Hooks\Contracts\HookInterface;

/**
 * Single source of truth for extra hook listeners declared via composer.json extras.
 */
final class HookCatalogue implements CatalogueInterface
{
    public const DEFAULT_PRIORITY = 50;

    private static array $custom = [];

    /**
     * Every registered hook entry: attribute-discovered entries first, customs after.
     *
     * @return list<array{event: string, class: class-string<HookInterface>, key: string, priority?: int, enabled_by_default?: bool, label?: string}>
     */
    public static function all(): array
    {
        return [...self::discovered(), ...self::$custom];
    }

    /**
     * Look up a single hook by key.
     *
     * @param  string  $key  Storage key.
     * @return array{event: string, class: class-string<HookInterface>, key: string, priority?: int, enabled_by_default?: bool, label?: string}|null
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
     * Return the registered hook class names.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_map(static fn (array $e): string => $e['key'], self::all());
    }

    /**
     * Hooks that should be enabled by default in fresh installs.
     *
     * @return list<string> Keys of `enabled_by_default => true` entries.
     */
    public static function defaultEnabledKeys(): array
    {
        $out = [];
        foreach (self::all() as $entry) {
            if (($entry['enabled_by_default'] ?? false) === true) {
                $out[] = $entry['key'];
            }
        }

        return $out;
    }

    /**
     * Register a custom hook entry at runtime.
     *
     * @param  string  $key  Unique hook key.
     * @param  string  $event  phpClaw lifecycle event the hook listens to.
     * @param  class-string<HookInterface>  $class  FQCN of the HookInterface implementation.
     * @param  int  $priority  Listener priority (default: {@see DEFAULT_PRIORITY}).
     * @param  bool  $enabledByDefault  Whether the hook ships enabled on fresh installs.
     * @param  string|null  $label  Optional human-readable label.
     * @return void
     */
    public static function register(
        string $key,
        string $event,
        string $class,
        int $priority = self::DEFAULT_PRIORITY,
        bool $enabledByDefault = false,
        ?string $label = null,
    ): void {
        self::$custom = array_values(array_filter(
            self::$custom,
            static fn (array $e): bool => $e['key'] !== $key,
        ));

        $entry = [
            'event' => $event,
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
     * Auto-discover hooks from every installed Composer package's `extra.phpclaw.hooks` block. Invalid entries are silently skipped.
     *
     * @return void
     */
    public static function boot(): void
    {
        foreach (ComposerExtras::hooks() as $info) {
            if (! isset($info['event'], $info['class'])) {
                continue;
            }

            $event = $info['event'];
            $class = $info['class'];

            if (! is_string($event) || ! is_string($class) || $event === '') {
                continue;
            }

            if (! class_exists($class)) {
                continue;
            }

            if (! is_a($class, HookInterface::class, true)) {
                continue;
            }

            $key = isset($info['key']) && is_string($info['key'])
                ? $info['key']
                : self::keyFromClass($class);

            $priority = isset($info['priority']) && is_int($info['priority'])
                ? $info['priority']
                : self::DEFAULT_PRIORITY;

            $enabledByDefault = ($info['enabled_by_default'] ?? false) === true;

            $label = isset($info['label']) && is_string($info['label']) ? $info['label'] : null;

            self::register($key, $event, $class, $priority, $enabledByDefault, $label);
        }
    }

    /**
     * Instantiate every enabled hook and register it with {@see HookRegistry} against the event + priority declared in its catalogue entry.
     *
     * @param  list<string>  $enabledKeys  Hook keys the user has opted in to.
     * @return list<HookInterface> The instances that were registered.
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

            if (! class_exists($class) || ! is_a($class, HookInterface::class, true)) {
                continue;
            }

            $event = $entry['event'];
            $priority = $entry['priority'] ?? self::DEFAULT_PRIORITY;

            $instance = new $class;
            HookRegistry::on($event, $instance, $priority);
            $instances[] = $instance;
        }

        return $instances;
    }

    /**
     * Convenience: activate the built-in / declared default-enabled hooks.
     *
     * @return list<HookInterface>
     */
    public static function activateDefaults(): array
    {
        return self::activateEnabled(self::defaultEnabledKeys());
    }

    /**
     * Bootstrap integration: invoked by {@see Bootstrap::activateFromSettings()}.
     *
     * @param  array<string, mixed>  $settings  Settings.
     * @return list<string> Always empty (hooks attach via HookRegistry::on).
     */
    public static function activateFromSettings(array $settings): array
    {
        if (array_key_exists('hooks_enabled', $settings) && is_array($settings['hooks_enabled'])) {
            $keys = array_values(array_filter($settings['hooks_enabled'], 'is_string'));
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
        $short = preg_replace('/Hook$/', '', $short) ?? $short;
        $snake = strtolower(preg_replace('/(?<!^)([A-Z])/', '_$1', $short) ?? $short);

        return trim($snake, '_') ?: 'hook';
    }

    /**
     * Pull attribute-discovered hooks out of {@see DiscoveryCache} and normalise into the catalogue's entry shape.
     *
     * @return list<array{event: string, class: class-string<HookInterface>, key: string, priority?: int, enabled_by_default?: bool, label?: string}>
     */
    private static function discovered(): array
    {
        $out = [];

        foreach (DiscoveryCache::load()['hooks'] as $class => $entries) {
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }

            if (! is_a($class, HookInterface::class, true)) {
                continue;
            }

            foreach ($entries as $i => $attr) {
                $event = (string) ($attr['event'] ?? '');
                if ($event === '') {
                    continue;
                }

                $name = (string) ($attr['name'] ?? '');
                if ($name === '') {
                    $name = self::keyFromClass($class);
                    if (count($entries) > 1) {
                        $name .= '_'.str_replace('.', '_', $event);
                    }
                }

                $entry = [
                    'event' => $event,
                    'class' => $class,
                    'key' => $name,
                    'priority' => (int) ($attr['priority'] ?? self::DEFAULT_PRIORITY),
                    'enabled_by_default' => (bool) ($attr['enabledByDefault'] ?? false),
                ];

                $label = (string) ($attr['label'] ?? '');
                if ($label !== '') {
                    $entry['label'] = $label;
                }

                $out[] = $entry;
            }
        }

        return $out;
    }
}

<?php

declare(strict_types=1);

namespace PhpClaw\Skills;

use PhpClaw\AutoDiscovery\CatalogueInterface;
use PhpClaw\AutoDiscovery\ComposerExtras;
use PhpClaw\AutoDiscovery\DiscoveryCache;
use PhpClaw\Hooks\Dispatchers\SkillEventDispatcher;
use PhpClaw\Skills\Contracts\SkillInterface;

/**
 * Single source of truth for extra skills shipped in any community package that declares its own skill class.
 */
final class SkillCatalogue implements CatalogueInterface
{
    private static array $custom = [];

    /**
     * Every registered skill entry: attribute-discovered entries followed by customs.
     *
     * @return list<array{class: class-string<SkillInterface>, key: string, label?: string}>
     */
    public static function all(): array
    {
        return [...self::discovered(), ...self::$custom];
    }

    /**
     * Look up a single skill entry by its key.
     *
     * @param  string  $key  Skill key.
     * @return array{class: class-string<SkillInterface>, key: string, label?: string}|null
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
     * Return the registered skill class names.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_map(static fn (array $e): string => $e['key'], self::all());
    }

    /**
     * Register a custom skill at runtime.
     *
     * @param  string  $key  Unique skill key.
     * @param  class-string<SkillInterface>  $class  FQCN of the SkillInterface implementation.
     * @param  string|null  $label  Optional human-readable label.
     * @return void
     */
    public static function register(string $key, string $class, ?string $label = null): void
    {
        self::$custom = array_values(array_filter(
            self::$custom,
            static fn (array $e): bool => $e['key'] !== $key,
        ));

        $entry = ['class' => $class, 'key' => $key];

        if ($label !== null) {
            $entry['label'] = $label;
        }

        self::$custom[] = $entry;
        SkillEventDispatcher::registered($key, $class, $label);
    }

    /**
     * Auto-discover skills from every installed Composer package's `extra.phpclaw.skills` block.
     *
     * @return void
     */
    public static function boot(): void
    {
        foreach (ComposerExtras::skills() as $info) {
            if (! isset($info['class']) || ! is_string($info['class'])) {
                continue;
            }

            $class = $info['class'];

            if (! self::isLoadableSkillClass($class)) {
                continue;
            }

            $key = isset($info['key']) && is_string($info['key']) ? $info['key'] : self::keyFromClass($class);
            $label = isset($info['label']) && is_string($info['label']) ? $info['label'] : null;

            self::register($key, $class, $label);
        }
    }

    /**
     * Instantiate every enabled skill and register it into {@see SkillRegistry}.
     *
     * @param  list<string>  $enabledKeys  Skill keys the user has opted in to.
     * @return list<SkillInterface> The instances that were registered (useful for tests).
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

            if (! self::isLoadableSkillClass($class)) {
                continue;
            }

            $instance = new $class;
            SkillRegistry::register($instance);
            $instances[] = $instance;
        }

        return $instances;
    }

    /**
     * Convenience: activate the default-enabled subset. Returns the same shape as {@see activateEnabled()}.
     *
     * @return list<SkillInterface>
     */
    public static function activateDefaults(): array
    {
        return self::activateEnabled(self::keys());
    }

    /**
     * Skills are not admin-toggleable. Every discovered skill is registered by {@see activateDefaults()}, so this hook is intentionally a no-op. Required by {@see CatalogueInterface}.
     *
     * @param  array<string, mixed>  $settings  Settings.
     * @return list<string>
     */
    public static function activateFromSettings(array $settings): array
    {
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
     * Whether the given class exists and implements {@see SkillInterface}.
     *
     * @param  string  $class  Fully-qualified class name to test.
     * @return bool
     */
    private static function isLoadableSkillClass(string $class): bool
    {
        return class_exists($class) && is_a($class, SkillInterface::class, true);
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
        $short = preg_replace('/Skill$/', '', $short) ?? $short;
        $snake = strtolower(preg_replace('/(?<!^)([A-Z])/', '_$1', $short) ?? $short);

        return trim($snake, '_') ?: 'skill';
    }

    /**
     * Pull attribute-discovered skills out of {@see DiscoveryCache} and normalise into the list-of-entries shape.
     *
     * @return list<array{class: class-string<SkillInterface>, key: string, label?: string}>
     */
    private static function discovered(): array
    {
        $out = [];

        foreach (DiscoveryCache::load()['skills'] as $class => $attr) {
            $key = (string) ($attr['name'] ?? '');
            if ($key === '') {
                continue;
            }

            if (! is_string($class) || ! self::isLoadableSkillClass($class)) {
                continue;
            }

            $entry = ['class' => $class, 'key' => $key];

            $label = (string) ($attr['label'] ?? '');
            if ($label !== '') {
                $entry['label'] = $label;
            }

            $out[] = $entry;
        }

        return $out;
    }
}

<?php

declare(strict_types=1);

namespace PhpClaw\Memory;

use PhpClaw\AutoDiscovery\CatalogueInterface;
use PhpClaw\AutoDiscovery\ComposerExtras;
use PhpClaw\AutoDiscovery\DiscoveryCache;
use PhpClaw\Memory\Contracts\MemoryInterface;

/**
 * Single source of truth for extra memory drivers shipped in any community package that declares its own memory driver.
 */
final class MemoryCatalogue implements CatalogueInterface
{
    private static array $custom = [];

    /**
     * Every registered memory driver: attribute-discovered entries merged with custom registrations. Customs win on slug clash (lets host code override).
     *
     * @return array<string, array{label: string, class: class-string<MemoryInterface>, factory?: callable|string}>
     */
    public static function all(): array
    {
        return [...self::discovered(), ...self::$custom];
    }

    /**
     * Look up a single driver entry by slug.
     *
     * @param  string  $key  Storage key.
     * @return array{label: string, class: class-string<MemoryInterface>, factory?: callable|string}|null
     */
    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /**
     * Return the registered memory-driver class names.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * Register a custom memory driver at runtime, overrides attribute-discovered entries with the same slug.
     *
     * @param  string  $key  Storage key.
     * @param  string  $label  Human-readable label, if any.
     * @param  class-string<MemoryInterface>  $class  FQCN of the MemoryInterface implementation.
     * @param  callable|string|null  $factory  Optional factory that builds the instance from runtime config.
     * @return void
     */
    public static function register(string $key, string $label, string $class, callable|string|null $factory = null): void
    {
        $entry = ['label' => $label, 'class' => $class];

        if ($factory !== null) {
            $entry['factory'] = $factory;
        }

        self::$custom[$key] = $entry;
    }

    /**
     * Import memory drivers from every installed Composer package's `extra.phpclaw.memory` block.
     *
     * @return void
     */
    public static function boot(): void
    {
        foreach (ComposerExtras::memory() as $slug => $info) {
            if (! isset($info['label'], $info['class'])) {
                continue;
            }

            $label = $info['label'];
            $class = $info['class'];

            if (! is_string($label) || ! is_string($class)) {
                continue;
            }

            if (! class_exists($class)) {
                continue;
            }

            if (! is_a($class, MemoryInterface::class, true)) {
                continue;
            }

            $factory = $info['factory'] ?? null;

            if ($factory !== null && ! is_string($factory) && ! is_callable($factory)) {
                $factory = null;
            }

            self::register($slug, $label, $class, $factory);
        }
    }

    /**
     * Register every discovered memory driver into MemoryRegistry.
     *
     * @param  array<string, array<string, mixed>>  $configByKey  Optional per-slug config passed to factory callbacks.
     * @return void
     */
    public static function activateAll(array $configByKey = []): void
    {
        foreach (self::all() as $slug => $info) {
            if (MemoryRegistry::has($slug)) {
                continue;
            }

            $class = $info['class'];

            if (! class_exists($class) || ! is_a($class, MemoryInterface::class, true)) {
                continue;
            }

            $factory = $info['factory'] ?? null;
            $config = $configByKey[$slug] ?? [];

            if ($factory !== null && is_callable($factory)) {
                MemoryRegistry::register($slug, static fn (): MemoryInterface => $factory($config));
            } else {
                MemoryRegistry::register($slug, static fn (): MemoryInterface => new $class);
            }
        }
    }

    /**
     * Bootstrap integration: invoked by activateFromSettings().
     *
     * @param  array<string, mixed>  $settings  Settings.
     * @return list<string> Always empty: memory writes into MemoryRegistry directly.
     */
    public static function activateFromSettings(array $settings): array
    {
        $config = is_array($settings['memory_config'] ?? null) ? $settings['memory_config'] : [];
        self::activateAll($config);

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
     * Pull attribute-discovered memory drivers out of {@see DiscoveryCache} and normalise into the `{slug => {label, class}}` shape.
     *
     * @return array<string, array{label: string, class: class-string<MemoryInterface>}>
     */
    private static function discovered(): array
    {
        $out = [];

        foreach (DiscoveryCache::load()['memory'] as $class => $attr) {
            $slug = (string) ($attr['driver'] ?? '');
            if ($slug === '') {
                continue;
            }

            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }

            if (! is_a($class, MemoryInterface::class, true)) {
                continue;
            }

            $label = (string) ($attr['label'] ?? '');
            if ($label === '') {
                $label = ucfirst($slug);
            }

            $out[$slug] = ['label' => $label, 'class' => $class];
        }

        return $out;
    }
}

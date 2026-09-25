<?php

declare(strict_types=1);

namespace PhpClaw\Providers;

use PhpClaw\AutoDiscovery\CatalogueInterface;
use PhpClaw\AutoDiscovery\ComposerExtras;
use PhpClaw\AutoDiscovery\DiscoveryCache;
use PhpClaw\Providers\Contracts\ProviderInterface;

/**
 * Single source of truth for every provider registered in core.
 */
final class ProviderCatalogue implements CatalogueInterface
{
    private static array $custom = [];

    /**
     * Return every provider in the catalogue: attribute-discovered + presets + custom registrations.
     *
     * @return array<string, array{label: string, class: class-string<ProviderInterface>}>
     */
    public static function all(): array
    {
        return [...self::discovered(), ...self::presets(), ...self::$custom];
    }

    /**
     * Look up a single provider entry by its slug.
     *
     * @param  string  $key  Storage key.
     * @return array{label: string, class: class-string<ProviderInterface>}|null
     */
    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /**
     * Return every registered provider slug.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * Register a custom provider at runtime: overrides any attribute-declared entry with the same slug.
     *
     * @param  string  $key  Storage key.
     * @param  string  $label  Human-readable label, if any.
     * @param  class-string<ProviderInterface>  $class  FQCN of the ProviderInterface implementation.
     * @return void
     */
    public static function register(string $key, string $label, string $class): void
    {
        self::$custom[$key] = ['label' => $label, 'class' => $class];
    }

    /**
     * Import providers from every installed Composer package's `extra.phpclaw.providers` block.
     *
     * @return void
     */
    public static function boot(): void
    {
        foreach (ComposerExtras::providers() as $slug => $info) {
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

            if (! is_a($class, ProviderInterface::class, true)) {
                continue;
            }

            self::register($slug, $label, $class);
        }
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
     * Required by {@see CatalogueInterface} but always a no-op: providers are always active and not user-toggleable.
     *
     * @param  array<string, mixed>  $settings  Adapter settings (unused).
     * @return list<string>
     */
    public static function activateFromSettings(array $settings): array
    {
        return [];
    }

    /**
     * OpenAI-compatible presets, all served by the single OpenAIProvider engine.
     *
     * @return array<string, array{label: string, class: class-string<ProviderInterface>}>
     */
    private static function presets(): array
    {
        $out = [];

        foreach (OpenAIPresets::all() as $slug => $preset) {
            $out[$slug] = ['label' => $preset['label'], 'class' => OpenAIProvider::class];
        }

        return $out;
    }

    /**
     * Pull attribute-discovered providers out of {@see DiscoveryCache} and return them in the catalogue's slug-keyed shape.
     *
     * @return array<string, array{label: string, class: class-string<ProviderInterface>}>
     */
    private static function discovered(): array
    {
        $out = [];

        foreach (DiscoveryCache::load()['providers'] as $class => $attributes) {
            $slug = (string) ($attributes['name'] ?? '');
            if ($slug === '') {
                continue;
            }

            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }

            if (! is_a($class, ProviderInterface::class, true)) {
                continue;
            }

            $label = (string) ($attributes['label'] ?? '');
            if ($label === '') {
                $label = ucfirst($slug);
            }

            $out[$slug] = ['label' => $label, 'class' => $class];
        }

        return $out;
    }
}

<?php

declare(strict_types=1);

namespace PhpClaw\Tools;

use PhpClaw\AutoDiscovery\Bootstrap;
use PhpClaw\AutoDiscovery\CatalogueInterface;
use PhpClaw\AutoDiscovery\ComposerExtras;
use PhpClaw\AutoDiscovery\DiscoveryCache;
use PhpClaw\Tools\Contracts\AuthorizableToolInterface;
use PhpClaw\Tools\Contracts\ToolAuthorizerInterface;
use PhpClaw\Tools\Contracts\ToolInterface;

/**
 * Single source of truth for every tool registered in core.
 */
final class ToolCatalogue implements CatalogueInterface
{
    private static array $custom = [];

    /**
     * Return every registered tool class: attribute-discovered first, custom registrations after, with duplicates collapsed.
     *
     * @return list<class-string<ToolInterface>>
     */
    public static function all(): array
    {
        $merged = [];

        foreach (self::discovered() as $class) {
            $merged[$class] = true;
        }
        foreach (self::$custom as $class) {
            $merged[$class] = true;
        }

        $list = array_keys($merged);

        return $list;
    }

    /**
     * Check whether a tool class is registered.
     *
     * @param  class-string<ToolInterface>  $class  FQCN of the ToolInterface implementation to look up.
     * @return bool True when the class appears in the built-in or custom list.
     */
    public static function has(string $class): bool
    {
        return in_array($class, self::all(), true);
    }

    /**
     * Register a custom tool class at runtime.
     *
     * @param  class-string<ToolInterface>  $class  FQCN of the ToolInterface implementation to register.
     * @return void
     */
    public static function register(string $class): void
    {
        if (! in_array($class, self::$custom, true)) {
            self::$custom[] = $class;
        }
    }

    /**
     * Auto-discover tools from every installed Composer package's `extra.phpclaw.tools` block. Each entry must include a `class` key that names a `ToolInterface` implementation. Invalid entries are silently skipped.
     *
     * @return void
     */
    public static function boot(): void
    {
        foreach (ComposerExtras::tools() as $info) {
            if (! isset($info['class']) || ! is_string($info['class'])) {
                continue;
            }

            $class = $info['class'];

            if (! class_exists($class)) {
                continue;
            }

            if (! is_a($class, ToolInterface::class, true)) {
                continue;
            }

            if (self::has($class)) {
                continue;
            }

            self::register($class);
        }
    }

    /**
     * Return the validated class FQCN list for every enabled tool, for the adapter to pass into its engine builder via the `extraToolClasses` parameter (EngineFactory instantiates internally).
     *
     * @param  list<class-string<ToolInterface>>|null  $enabledClasses  Class FQCNs to activate. Null = every catalogue entry.
     * @return list<class-string<ToolInterface>> Validated FQCNs.
     */
    public static function activateEnabled(?array $enabledClasses = null): array
    {
        $candidates = $enabledClasses ?? self::all();
        $out = [];

        foreach ($candidates as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }

            if (! is_a($class, ToolInterface::class, true)) {
                continue;
            }

            $out[] = $class;
        }

        return $out;
    }

    /**
     * Bootstrap integration: invoked by {@see Bootstrap::activateFromSettings()}.
     *
     * @param  array<string, mixed>  $settings  Settings.
     * @return list<class-string> Validated tool class FQCNs.
     */
    public static function activateFromSettings(array $settings): array
    {
        $value = $settings['tools_enabled'] ?? null;

        if ($value !== null && ! is_array($value)) {
            return [];
        }

        $enabled = is_array($value)
            ? array_values(array_filter($value, 'is_string'))
            : null;

        return self::activateEnabled($enabled);
    }

    /**
     * Remove all custom registrations. Attribute-discovered entries remain.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$custom = [];
    }

    /**
     * Instantiate every tool whose `#[Tool]` attribute has `default: true`.
     *
     * @param  array<string, mixed>  $config  Adapter-supplied config values.
     * @return list<ToolInterface> Fully-instantiated tool objects.
     */
    public static function instantiateDefaults(array $config = []): array
    {
        $out = [];

        foreach (DiscoveryCache::load()['tools'] as $class => $attr) {
            if (! ($attr['default'] ?? false)) {
                continue;
            }

            if (! class_exists($class)) {
                continue;
            }

            $needsConfig = (array) ($attr['needsConfig'] ?? []);
            $args = [];

            foreach ($needsConfig as $argName => $_typeHint) {
                if (array_key_exists($argName, $config)) {
                    $args[] = $config[$argName];
                }
            }

            $instance = new $class(...$args);

            if ($instance instanceof AuthorizableToolInterface && ($config['authorizer'] ?? null) instanceof ToolAuthorizerInterface) {
                $instance->withAuthorizer($config['authorizer']);
            }

            $out[] = $instance;
        }

        return $out;
    }

    /**
     * Pull attribute-discovered tools out of {@see DiscoveryCache}.
     *
     * @return list<class-string<ToolInterface>>
     */
    private static function discovered(): array
    {
        $out = [];

        foreach (array_keys(DiscoveryCache::load()['tools']) as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }

            if (! is_a($class, ToolInterface::class, true)) {
                continue;
            }

            $out[] = $class;
        }

        return $out;
    }
}

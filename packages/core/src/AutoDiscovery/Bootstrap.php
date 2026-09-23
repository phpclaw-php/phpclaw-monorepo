<?php

declare(strict_types=1);

namespace PhpClaw\AutoDiscovery;

use PhpClaw\AutoDiscovery\Attributes\Guard;
use PhpClaw\AutoDiscovery\Attributes\Hook;
use PhpClaw\AutoDiscovery\Attributes\Memory;
use PhpClaw\AutoDiscovery\Attributes\Provider;
use PhpClaw\AutoDiscovery\Attributes\Skill;
use PhpClaw\AutoDiscovery\Attributes\Tool;
use PhpClaw\Guards\GuardCatalogue;
use PhpClaw\Hooks\HookCatalogue;
use PhpClaw\Memory\MemoryCatalogue;
use PhpClaw\Providers\ProviderCatalogue;
use PhpClaw\Skills\SkillCatalogue;
use PhpClaw\Tools\ToolCatalogue;

/**
 * Central auto-discovery facade for the six phpClaw subsystems.
 */
final class Bootstrap
{
    public const CATALOGUES = [
        ToolCatalogue::class,
        ProviderCatalogue::class,
        MemoryCatalogue::class,
        SkillCatalogue::class,
        HookCatalogue::class,
        GuardCatalogue::class,
    ];

    /**
     * Snapshot every catalogue's attribute-discovered entries into its registry.
     *
     * @return void
     */
    public static function boot(): void
    {
        foreach (self::CATALOGUES as $class) {
            $class::boot();
        }
    }

    /**
     * Forward admin-saved settings to every catalogue and aggregate the class-strings they want merged into the adapter's engine builder.
     *
     * @param  array<string, mixed>  $settings  Settings.
     * @return list<string>
     */
    public static function activateFromSettings(array $settings): array
    {
        $merged = [];

        foreach (self::CATALOGUES as $class) {
            $merged = [...$merged, ...$class::activateFromSettings($settings)];
        }

        return $merged;
    }

    /**
     * Tools discovered via {@see Tool}.
     *
     * @return array<string, class-string> name => class-string
     */
    public static function tools(): array
    {
        return self::mapByName(DiscoveryCache::load()['tools']);
    }

    /**
     * Providers discovered via {@see Provider}.
     *
     * @return array<string, class-string> name => class-string
     */
    public static function providers(): array
    {
        return self::mapByName(DiscoveryCache::load()['providers']);
    }

    /**
     * Memory drivers discovered via {@see Memory}.
     *
     * @return array<string, class-string> driver => class-string
     */
    public static function memory(): array
    {
        $out = [];
        foreach (DiscoveryCache::load()['memory'] as $class => $attr) {
            $driver = (string) ($attr['driver'] ?? '');
            if ($driver !== '') {
                $out[$driver] = $class;
            }
        }

        return $out;
    }

    /**
     * Skills discovered via {@see Skill}.
     *
     * @return array<string, class-string> name => class-string
     */
    public static function skills(): array
    {
        return self::mapByName(DiscoveryCache::load()['skills']);
    }

    /**
     * Hooks discovered via {@see Hook}.
     *
     * @return array<string, list<class-string>> event => listener class-strings
     */
    public static function hooks(): array
    {
        $byEvent = [];

        foreach (DiscoveryCache::load()['hooks'] as $class => $entries) {
            foreach ($entries as $entry) {
                $event = (string) ($entry['event'] ?? '');
                if ($event === '') {
                    continue;
                }
                $byEvent[$event][] = ['class' => $class, 'priority' => self::priorityOf($entry)];
            }
        }

        $out = [];
        foreach ($byEvent as $event => $entries) {
            $out[$event] = array_map(
                static fn (array $e): string => $e['class'],
                self::sortByPriority($entries),
            );
        }

        return $out;
    }

    /**
     * Guards discovered via {@see Guard}.
     *
     * @return list<array{class: class-string, priority: int}>
     */
    public static function guards(): array
    {
        $out = [];
        foreach (DiscoveryCache::load()['guards'] as $class => $attr) {
            $out[] = [
                'class' => $class,
                'priority' => self::priorityOf($attr),
            ];
        }

        return self::sortByPriority($out);
    }

    /**
     * Extract an entry's discovery priority, defaulting to 100 when unset.
     *
     * @param  array<string, mixed>  $attr  Attribute-discovered entry.
     * @return int
     */
    private static function priorityOf(array $attr): int
    {
        return (int) ($attr['priority'] ?? 100);
    }

    /**
     * Sort priority-tagged entries into ascending priority order.
     *
     * @param  list<array{class: class-string, priority: int}>  $entries  Entries.
     * @return list<array{class: class-string, priority: int}>
     */
    private static function sortByPriority(array $entries): array
    {
        usort($entries, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        return $entries;
    }

    /**
     * Collapse a `class => attr-array` map into `name => class`; entries with an empty/missing `name` are dropped silently.
     *
     * @param  array<class-string, array<string, mixed>>  $entries  Entries.
     * @return array<string, class-string>
     */
    private static function mapByName(array $entries): array
    {
        $out = [];
        foreach ($entries as $class => $attr) {
            $name = (string) ($attr['name'] ?? '');
            if ($name !== '') {
                $out[$name] = $class;
            }
        }

        return $out;
    }
}

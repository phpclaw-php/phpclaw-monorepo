<?php

declare(strict_types=1);

namespace PhpClaw\AutoDiscovery;

/**
 * Contract every catalogue satisfies: one per phpClaw subsystem (tools, providers, memory, skills, hooks, guards).
 */
interface CatalogueInterface
{
    /**
     * Snapshot attribute-discovered entries into the catalogue's registry, idempotent.
     *
     * @return void
     */
    public static function boot(): void;

    /**
     * Activate the subset of entries the admin has enabled in settings.
     *
     * @param  array<string, mixed>  $settings  Settings.
     * @return list<string>
     */
    public static function activateFromSettings(array $settings): array;
}

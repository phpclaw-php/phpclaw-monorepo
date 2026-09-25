<?php

declare(strict_types=1);

namespace PhpClaw\AutoDiscovery;

/**
 * Contract every catalogue satisfies: one per phpClaw subsystem (tools, providers, memory, skills, hooks, guards).
 */
interface CatalogueInterface
{
    /**
     * Register Composer-declared (extra.phpclaw) entries into this catalogue, idempotent.
     *
     * @return void
     */
    public static function boot(): void;

    /**
     * Activate the subset of entries the admin has enabled in settings.
     *
     * @param  array<string, mixed>  $settings  Admin settings array forwarded from Bootstrap::activateFromSettings().
     * @return list<string>
     */
    public static function activateFromSettings(array $settings): array;
}

<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\AutoDiscovery;

use PhpClaw\AutoDiscovery\Bootstrap;
use PhpClaw\Guards\GuardCatalogue;
use PhpClaw\Hooks\HookCatalogue;
use PhpClaw\Memory\MemoryCatalogue;
use PhpClaw\Providers\ProviderCatalogue;
use PhpClaw\Skills\SkillCatalogue;
use PhpClaw\Tools\ToolCatalogue;
use PHPUnit\Framework\TestCase;

final class BootstrapTest extends TestCase
{
    public function test_catalogues_constant_lists_the_six_phpclaw_subsystems_in_order(): void
    {
        self::assertSame(
            [
                ToolCatalogue::class,
                ProviderCatalogue::class,
                MemoryCatalogue::class,
                SkillCatalogue::class,
                HookCatalogue::class,
                GuardCatalogue::class,
            ],
            Bootstrap::CATALOGUES,
        );
    }

    public function test_boot_invokes_boot_on_every_catalogue_without_fatal(): void
    {
        Bootstrap::boot();

        Bootstrap::boot();

        $this->expectNotToPerformAssertions();
    }

    public function test_activate_from_settings_returns_an_array_for_empty_settings(): void
    {
        $result = Bootstrap::activateFromSettings([]);

        self::assertIsArray($result);
    }

    public function test_activate_from_settings_returns_only_string_entries(): void
    {
        $result = Bootstrap::activateFromSettings([
            'tools_enabled' => [],
            'memory_driver' => '',
            'hooks_enabled' => [],
            'guards_enabled' => [],
            'providers_enabled' => [],
        ]);

        self::assertSame(
            count($result),
            count(array_filter($result, 'is_string')),
            'every aggregated entry must be a string',
        );
    }
}

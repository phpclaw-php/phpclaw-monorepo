<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Unit\Skills;

use PhpClaw\AutoDiscovery\DiscoveryCache;
use PhpClaw\Skills\SkillCatalogue;
use PhpClaw\Skills\SkillRegistry;
use PHPUnit\Framework\TestCase;

final class SkillFiringTest extends TestCase
{
    protected function setUp(): void
    {
        SkillRegistry::reset();
    }

    protected function tearDown(): void
    {
        SkillRegistry::reset();
    }

    public function test_skill_catalogue_activate_defaults_registers_php_best_practices(): void
    {
        $this->skipIfDiscoveryUnavailable();

        SkillCatalogue::activateDefaults();

        $this->assertTrue(SkillRegistry::has('php_best_practices'));
    }

    private function skipIfDiscoveryUnavailable(): void
    {
        try {
            DiscoveryCache::load();
        } catch (\Throwable $e) {
            $this->markTestSkipped('DiscoveryCache scan failed ('.$e->getMessage().').');
        }
    }
}

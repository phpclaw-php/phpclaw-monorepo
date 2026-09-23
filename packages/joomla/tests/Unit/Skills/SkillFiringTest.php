<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Unit\Skills;

use PhpClaw\AutoDiscovery\DiscoveryCache;
use PhpClaw\Skills\PhpBestPracticesSkill;
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

    public function test_skill_registry_match_returns_zero_for_unrelated_message(): void
    {
        SkillRegistry::register(new PhpBestPracticesSkill);

        $matches = SkillRegistry::match('weather today', 3);

        $this->assertCount(0, $matches);
    }

    public function test_skill_registry_match_returns_one_for_php_review_prompt(): void
    {
        SkillRegistry::register(new PhpBestPracticesSkill);

        $matches = SkillRegistry::match('review this php class for best practices', 3);

        $this->assertCount(1, $matches);
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

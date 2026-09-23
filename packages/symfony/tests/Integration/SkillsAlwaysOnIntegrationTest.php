<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Integration;

use PhpClaw\Claw as PhpClaw;
use PhpClaw\Skills\SkillRegistry;

final class SkillsAlwaysOnIntegrationTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        SkillRegistry::reset();
        parent::setUp();
    }

    public function test_discovered_skill_is_active_by_default_without_any_toggle(): void
    {
        IntegrationKernel::configure([]);

        self::bootKernel();

        $this->assertContains('php_best_practices', SkillRegistry::names(), 'Every discovered skill must be active by default (always-on).');
    }

    public function test_engine_service_is_resolvable_with_skills_always_on(): void
    {
        IntegrationKernel::configure([]);

        $kernel = self::bootKernel();
        $container = $kernel->getContainer();

        $this->assertTrue($container->has(PhpClaw::class));
        $this->assertInstanceOf(PhpClaw::class, $container->get(PhpClaw::class));
    }
}

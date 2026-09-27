<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Skills;

use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use PhpClaw\Skills\ArraySkill;
use PhpClaw\Skills\SkillRegistry;
use PHPUnit\Framework\TestCase;

final class SkillRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        SkillRegistry::reset();
        HookRegistry::reset();
    }

    protected function tearDown(): void
    {
        SkillRegistry::reset();
        HookRegistry::reset();
    }

    public function test_registered_skill_appears_in_all(): void
    {
        $skill = new ArraySkill('deploy', 'deployment guide', ['docker'], 'Deploy with docker.');
        SkillRegistry::register($skill);

        $this->assertCount(1, SkillRegistry::all());
        $this->assertSame('deploy', SkillRegistry::all()[0]->name());
    }

    public function test_registering_same_name_overwrites(): void
    {
        SkillRegistry::register(new ArraySkill('s', 'first', [], 'content a'));
        SkillRegistry::register(new ArraySkill('s', 'second', [], 'content b'));

        $all = SkillRegistry::all();
        $this->assertCount(1, $all);
        $this->assertSame('content b', $all[0]->content());
    }

    public function test_reset_clears_all_skills(): void
    {
        SkillRegistry::register(new ArraySkill('s', 'desc', [], 'content'));
        SkillRegistry::reset();

        $this->assertSame([], SkillRegistry::all());
    }

    public function test_register_fires_skill_loaded_event(): void
    {
        $captured = [];
        HookRegistry::on(LifecycleEvent::SkillLoaded->value, function (array $ctx) use (&$captured): void {
            $captured[] = $ctx;
        });

        $skill = new ArraySkill('deploy', 'deployment guide', ['docker'], 'Deploy with docker.');
        SkillRegistry::register($skill);

        $this->assertCount(1, $captured);
        $this->assertSame('deploy', $captured[0]['skill_name']);
        $this->assertSame(ArraySkill::class, $captured[0]['skill_class']);
    }
}

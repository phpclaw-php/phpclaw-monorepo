<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Hooks\Dispatchers;

use PhpClaw\Hooks\Dispatchers\SkillEventDispatcher;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use PHPUnit\Framework\TestCase;

final class SkillEventDispatcherTest extends TestCase
{
    private array $captured = [];

    protected function setUp(): void
    {
        HookRegistry::reset();
        $this->captured = [];

        $capture = function (string $event): callable {
            return function (array $context) use ($event): void {
                $this->captured[] = ['event' => $event, 'context' => $context];
            };
        };

        HookRegistry::on(LifecycleEvent::SkillRegistered->value, $capture(LifecycleEvent::SkillRegistered->value));
        HookRegistry::on(LifecycleEvent::SkillLoaded->value, $capture(LifecycleEvent::SkillLoaded->value));
        HookRegistry::on(LifecycleEvent::SkillMatched->value, $capture(LifecycleEvent::SkillMatched->value));
        HookRegistry::on(LifecycleEvent::SkillNotMatched->value, $capture(LifecycleEvent::SkillNotMatched->value));
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
    }

    public function test_registered_fires_with_key_class_and_label(): void
    {
        SkillEventDispatcher::registered(
            key: 'php_best_practices',
            class: 'PhpClaw\\Skills\\PhpBestPracticesSkill',
            label: 'PHP Best Practices',
            runId: 'run-001',
            parentRunId: 'parent-001',
        );

        $this->assertCount(1, $this->captured);
        $this->assertSame(LifecycleEvent::SkillRegistered->value, $this->captured[0]['event']);
        $this->assertSame('php_best_practices', $this->captured[0]['context']['key']);
        $this->assertSame('PhpClaw\\Skills\\PhpBestPracticesSkill', $this->captured[0]['context']['class']);
        $this->assertSame('PHP Best Practices', $this->captured[0]['context']['label']);
    }

    public function test_registered_accepts_null_label(): void
    {
        SkillEventDispatcher::registered('my-skill', 'Acme\\MySkill');

        $this->assertCount(1, $this->captured);
        $this->assertNull($this->captured[0]['context']['label']);
    }

    public function test_loaded_fires_with_skill_name_and_class(): void
    {
        SkillEventDispatcher::loaded(
            skillName: 'php_best_practices',
            skillClass: 'PhpClaw\\Skills\\PhpBestPracticesSkill',
            runId: 'run-002',
            parentRunId: 'parent-002',
        );

        $this->assertCount(1, $this->captured);
        $this->assertSame(LifecycleEvent::SkillLoaded->value, $this->captured[0]['event']);
        $this->assertSame('php_best_practices', $this->captured[0]['context']['skill_name']);
        $this->assertSame('PhpClaw\\Skills\\PhpBestPracticesSkill', $this->captured[0]['context']['skill_class']);
    }

    public function test_matched_fires_with_skills_excerpt_and_count(): void
    {
        SkillEventDispatcher::matched(
            matchedSkills: ['php_best_practices', 'sql_guide'],
            messageExcerpt: 'review my php class please',
            runId: 'run-003',
        );

        $this->assertCount(1, $this->captured);
        $this->assertSame(LifecycleEvent::SkillMatched->value, $this->captured[0]['event']);
        $this->assertSame(['php_best_practices', 'sql_guide'], $this->captured[0]['context']['matched_skills']);
        $this->assertSame('review my php class please', $this->captured[0]['context']['message_excerpt']);
        $this->assertSame(2, $this->captured[0]['context']['matched_count']);
    }

    public function test_not_matched_fires_with_excerpt_and_available_skills(): void
    {
        SkillEventDispatcher::notMatched(
            messageExcerpt: 'what is the weather today',
            availableSkills: ['php_best_practices'],
            runId: 'run-004',
        );

        $this->assertCount(1, $this->captured);
        $this->assertSame(LifecycleEvent::SkillNotMatched->value, $this->captured[0]['event']);
        $this->assertSame('what is the weather today', $this->captured[0]['context']['message_excerpt']);
        $this->assertSame(['php_best_practices'], $this->captured[0]['context']['available_skills']);
    }
}

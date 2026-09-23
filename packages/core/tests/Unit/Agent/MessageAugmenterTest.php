<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Agent;

use PhpClaw\Agent\MessageAugmenter;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use PhpClaw\Skills\ArraySkill;
use PhpClaw\Skills\SkillRegistry;
use PHPUnit\Framework\TestCase;

final class MessageAugmenterTest extends TestCase
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

    public function test_skill_matched_event_fires_when_skill_applies(): void
    {
        $captured = [];
        HookRegistry::on(LifecycleEvent::SkillMatched->value, function (array $ctx) use (&$captured): void {
            $captured[] = $ctx;
        });

        SkillRegistry::register(new ArraySkill(
            'php-best-practices',
            'PHP coding standards',
            ['php', 'code'],
            'Follow PSR standards.',
        ));

        $augmenter = new MessageAugmenter(null);
        $augmenter->augment('review my php class please');

        $this->assertCount(1, $captured);
        $this->assertContains('php-best-practices', $captured[0]['matched_skills']);
        $this->assertSame(1, $captured[0]['matched_count']);
        $this->assertStringContainsString('review my php class please', $captured[0]['message_excerpt']);
    }

    public function test_skill_not_matched_event_fires_when_no_skill_applies(): void
    {
        $captured = [];
        HookRegistry::on(LifecycleEvent::SkillNotMatched->value, function (array $ctx) use (&$captured): void {
            $captured[] = $ctx;
        });

        SkillRegistry::register(new ArraySkill(
            'php-best-practices',
            'PHP coding standards',
            ['php', 'code'],
            'Follow PSR standards.',
        ));

        $augmenter = new MessageAugmenter(null);
        $augmenter->augment('what is the weather today');

        $this->assertCount(1, $captured);
        $this->assertStringContainsString('what is the weather today', $captured[0]['message_excerpt']);
        $this->assertContains('php-best-practices', $captured[0]['available_skills']);
    }

    public function test_skill_matched_and_not_matched_are_mutually_exclusive(): void
    {
        $matched = [];
        $notMatched = [];
        HookRegistry::on(LifecycleEvent::SkillMatched->value, function (array $ctx) use (&$matched): void {
            $matched[] = $ctx;
        });
        HookRegistry::on(LifecycleEvent::SkillNotMatched->value, function (array $ctx) use (&$notMatched): void {
            $notMatched[] = $ctx;
        });

        SkillRegistry::register(new ArraySkill('php-best-practices', 'PHP coding standards', ['php'], 'Content.'));

        $augmenter = new MessageAugmenter(null);
        $augmenter->augment('fix my php issue');

        $this->assertCount(1, $matched, 'exactly one skill.matched must fire');
        $this->assertCount(0, $notMatched, 'skill.not_matched must NOT fire on a match');
    }

    public function test_skill_match_limit_caps_injected_skills(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            SkillRegistry::register(new ArraySkill("skill{$i}", "handles alpha task {$i}", ['alpha'], "SKILL{$i}-BODY"));
        }

        $out = (new MessageAugmenter(null, 5))->augment('please help with an alpha task');

        $this->assertSame(5, $this->countInjected($out));
    }

    public function test_skill_match_limit_defaults_to_registry_default(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            SkillRegistry::register(new ArraySkill("skill{$i}", "handles alpha task {$i}", ['alpha'], "SKILL{$i}-BODY"));
        }

        $out = (new MessageAugmenter(null))->augment('please help with an alpha task');

        $this->assertSame(SkillRegistry::DEFAULT_MATCH_LIMIT, $this->countInjected($out));
    }

    private function countInjected(string $out): int
    {
        $injected = 0;
        for ($i = 1; $i <= 6; $i++) {
            if (str_contains($out, "SKILL{$i}-BODY")) {
                $injected++;
            }
        }

        return $injected;
    }
}

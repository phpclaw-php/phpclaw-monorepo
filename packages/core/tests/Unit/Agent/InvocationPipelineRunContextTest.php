<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Agent;

use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\InvocationPipeline;
use PhpClaw\Agent\MessageAugmenter;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Skills\ArraySkill;
use PhpClaw\Skills\SkillRegistry;
use PHPUnit\Framework\TestCase;

final class InvocationPipelineRunContextTest extends TestCase
{
    protected function setUp(): void
    {
        HookRegistry::reset();
        SkillRegistry::reset();
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
        SkillRegistry::reset();
    }

    public function test_skill_matched_event_carries_the_run_id(): void
    {
        SkillRegistry::register(new ArraySkill(
            'widget_builder',
            'Build widgets',
            ['widget', 'build'],
            'Widget building instructions.',
        ));

        $skillRunId = null;
        HookRegistry::on('skill.matched', static function (array $ctx) use (&$skillRunId): void {
            $skillRunId = $ctx['run_id'] ?? '';
        });

        $pipeline = new InvocationPipeline(new MessageAugmenter(null), null);

        $response = $pipeline->execute(
            'please build a widget',
            false,
            static fn (string $augmented, string $runId): AgentResponse => new AgentResponse(
                text: 'done',
                provider: 'test',
                model: 'test',
                iterations: 1,
                runId: $runId,
            ),
        );

        $this->assertNotNull($skillRunId, 'skill.matched did not fire');
        $this->assertNotSame('', $skillRunId, 'skill.matched fired outside the run context (empty run_id)');
        $this->assertSame($response->runId, $skillRunId);
    }
}

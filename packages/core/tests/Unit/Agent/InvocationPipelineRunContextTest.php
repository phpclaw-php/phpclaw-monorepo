<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Agent;

use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\InvocationPipeline;
use PhpClaw\Agent\MessageAugmenter;
use PhpClaw\Hooks\HookDispatcher;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\HookRunContext;
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
            'please build a widget with widget_builder',
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

    private function captureAgentEvents(): \ArrayObject
    {
        $captured = new \ArrayObject;
        foreach (['agent.before', 'agent.after'] as $event) {
            HookRegistry::on($event, static function (array $ctx) use ($captured): void {
                $captured[] = $ctx;
            });
        }

        return $captured;
    }

    private function respond(): \Closure
    {
        return static fn (mixed ...$args): AgentResponse => new AgentResponse(text: 'done', provider: 'test', model: 'test', iterations: 1);
    }

    public function test_execute_at_the_top_level_reports_no_parent_run(): void
    {
        $captured = $this->captureAgentEvents();
        $parentSeenInside = null;

        (new InvocationPipeline(new MessageAugmenter(null), null))->execute('hello', false, static function () use (&$parentSeenInside): AgentResponse {
            $parentSeenInside = HookRunContext::currentParentRunId();

            return new AgentResponse(text: 'done', provider: 'test', model: 'test', iterations: 1);
        });

        $this->assertSame('', $parentSeenInside);
        $this->assertCount(2, $captured);
        foreach ($captured as $ctx) {
            $this->assertArrayNotHasKey('parent_run_id', $ctx);
        }
    }

    public function test_execute_inside_an_enclosing_run_reports_that_run_as_its_parent(): void
    {
        $captured = $this->captureAgentEvents();
        $parentSeenInside = null;

        HookDispatcher::withRun('outer-run', function () use (&$parentSeenInside): void {
            (new InvocationPipeline(new MessageAugmenter(null), null))->execute('hello', false, static function () use (&$parentSeenInside): AgentResponse {
                $parentSeenInside = HookRunContext::currentParentRunId();

                return new AgentResponse(text: 'done', provider: 'test', model: 'test', iterations: 1);
            });
        });

        $this->assertSame('outer-run', $parentSeenInside);
        $this->assertCount(2, $captured);
        foreach ($captured as $ctx) {
            $this->assertSame('outer-run', $ctx['parent_run_id']);
            $this->assertNotSame('outer-run', $ctx['run_id']);
        }
    }

    public function test_a_conversation_run_inside_an_enclosing_run_reports_that_run_as_its_parent(): void
    {
        $captured = $this->captureAgentEvents();

        HookDispatcher::withRun('outer-run', function (): void {
            (new InvocationPipeline(new MessageAugmenter(null), null))->executeInConversation(
                Conversation::start(),
                'hello',
                false,
                null,
                $this->respond(),
            );
        });

        $this->assertCount(2, $captured);
        foreach ($captured as $ctx) {
            $this->assertSame('outer-run', $ctx['parent_run_id']);
        }
    }

    public function test_a_resumed_run_inside_an_enclosing_run_reports_that_run_as_its_parent(): void
    {
        $captured = $this->captureAgentEvents();

        HookDispatcher::withRun('outer-run', function (): void {
            (new InvocationPipeline(new MessageAugmenter(null), null))->resume('saved-run', 'hello', $this->respond());
        });

        $this->assertCount(2, $captured);
        foreach ($captured as $ctx) {
            $this->assertSame('saved-run', $ctx['run_id']);
            $this->assertSame('outer-run', $ctx['parent_run_id']);
        }
    }
}

<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Hooks;

use PhpClaw\Hooks\LifecycleEvent;
use PHPUnit\Framework\TestCase;

final class LifecycleEventTest extends TestCase
{
    public function test_all_returns_a_string_list_of_every_case(): void
    {
        $all = LifecycleEvent::all();

        $this->assertSame(count(LifecycleEvent::cases()), count($all));
        $this->assertContainsOnly('string', $all);
    }

    public function test_all_contains_well_known_canonical_events(): void
    {
        $all = LifecycleEvent::all();

        foreach (
            [
                'agent.before',
                'agent.after',
                'agent.iteration',
                'agent.error',
                'agent.max_iterations',
                'provider.request',
                'provider.response',
                'provider.token',
                'tool.before',
                'tool.after',
                'tool.error',
                'tool.not_found',
                'context.overflow',
                'guard.blocked',
                'conversation.start',
                'conversation.end',
                'stream.start',
                'stream.end',
                'stream.abort',
                'shell.exec',
                'shell.denied',
                'memory.read',
                'memory.write',
                'memory.forget',
                'job.started',
                'job.completed',
                'job.failed',
            ] as $expected
        ) {
            $this->assertContains($expected, $all, "Missing canonical event '{$expected}'");
        }
    }

    public function test_every_case_uses_dot_separated_namespace_value(): void
    {
        foreach (LifecycleEvent::cases() as $event) {
            $this->assertMatchesRegularExpression(
                '/^[a-z]+\.[a-z_]+$/',
                $event->value,
                "Case {$event->name} has malformed value '{$event->value}'",
            );
        }
    }

    public function test_event_values_are_unique(): void
    {
        $values = LifecycleEvent::all();
        $this->assertSame($values, array_values(array_unique($values)));
    }

    public function test_is_agent_groups_agent_events(): void
    {
        $this->assertTrue(LifecycleEvent::AgentBefore->isAgent());
        $this->assertTrue(LifecycleEvent::AgentError->isAgent());
        $this->assertFalse(LifecycleEvent::ProviderRequest->isAgent());
        $this->assertFalse(LifecycleEvent::ToolBefore->isAgent());
    }

    public function test_is_provider_groups_provider_events(): void
    {
        $this->assertTrue(LifecycleEvent::ProviderRequest->isProvider());
        $this->assertTrue(LifecycleEvent::ProviderToken->isProvider());
        $this->assertFalse(LifecycleEvent::AgentBefore->isProvider());
    }

    public function test_is_tool_groups_tool_events(): void
    {
        $this->assertTrue(LifecycleEvent::ToolBefore->isTool());
        $this->assertTrue(LifecycleEvent::ToolNotFound->isTool());
        $this->assertFalse(LifecycleEvent::AgentBefore->isTool());
    }

    public function test_is_guard_groups_guard_events(): void
    {
        $this->assertTrue(LifecycleEvent::GuardBlocked->isGuard());
        $this->assertTrue(LifecycleEvent::GuardOutputPhpTagRemoved->isGuard());
        $this->assertFalse(LifecycleEvent::AgentBefore->isGuard());
    }

    public function test_is_conversation_groups_conversation_events(): void
    {
        $this->assertTrue(LifecycleEvent::ConversationStart->isConversation());
        $this->assertTrue(LifecycleEvent::ConversationEnd->isConversation());
        $this->assertFalse(LifecycleEvent::AgentBefore->isConversation());
    }

    public function test_is_stream_groups_stream_events(): void
    {
        $this->assertTrue(LifecycleEvent::StreamStart->isStream());
        $this->assertTrue(LifecycleEvent::StreamAbort->isStream());
        $this->assertFalse(LifecycleEvent::AgentBefore->isStream());
    }

    public function test_is_memory_groups_memory_events(): void
    {
        $this->assertTrue(LifecycleEvent::MemoryRead->isMemory());
        $this->assertTrue(LifecycleEvent::MemoryForget->isMemory());
        $this->assertFalse(LifecycleEvent::AgentBefore->isMemory());
    }

    public function test_is_shell_groups_shell_events(): void
    {
        $this->assertTrue(LifecycleEvent::ShellExec->isShell());
        $this->assertTrue(LifecycleEvent::ShellDenied->isShell());
        $this->assertFalse(LifecycleEvent::AgentBefore->isShell());
    }

    public function test_is_job_groups_job_events(): void
    {
        $this->assertTrue(LifecycleEvent::JobStarted->isJob());
        $this->assertTrue(LifecycleEvent::JobFailed->isJob());
        $this->assertFalse(LifecycleEvent::AgentBefore->isJob());
    }

    public function test_from_resolves_value_to_case(): void
    {
        $this->assertSame(LifecycleEvent::AgentBefore, LifecycleEvent::from('agent.before'));
        $this->assertSame(LifecycleEvent::ProviderToken, LifecycleEvent::from('provider.token'));
    }
}

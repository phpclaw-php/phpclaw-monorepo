<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Hooks\Dispatchers;

use PhpClaw\Claw;
use PhpClaw\Hooks\Dispatchers\ShellEventDispatcher;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\HookRunContext;
use PhpClaw\Hooks\LifecycleEvent;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Tools\ShellTool;
use PHPUnit\Framework\TestCase;

final class ShellEventDispatcherTest extends TestCase
{
    private array $captured = [];

    protected function setUp(): void
    {
        HookRegistry::reset();
        $this->captured = [];

        foreach ([LifecycleEvent::ShellExec, LifecycleEvent::ShellDenied, LifecycleEvent::ToolBefore] as $event) {
            HookRegistry::on($event->value, function (array $context) use ($event): void {
                $this->captured[] = ['event' => $event->value, 'context' => $context];
            });
        }
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
    }

    public function test_shell_exec_carries_active_run_id(): void
    {
        HookRunContext::withRun('R1', fn () => ShellEventDispatcher::exec('whoami', 'whoami'));

        $this->assertSame(LifecycleEvent::ShellExec->value, $this->captured[0]['event']);
        $this->assertSame('R1', $this->captured[0]['context']['run_id']);
        $this->assertSame('whoami', $this->captured[0]['context']['command']);
    }

    public function test_shell_denied_carries_active_run_id(): void
    {
        HookRunContext::withRun('R1', fn () => ShellEventDispatcher::denied('cat .env', 'cat', 'sensitive_file', '.env'));

        $this->assertSame(LifecycleEvent::ShellDenied->value, $this->captured[0]['event']);
        $this->assertSame('R1', $this->captured[0]['context']['run_id']);
        $this->assertSame('.env', $this->captured[0]['context']['file']);
    }

    public function test_shell_exec_outside_a_run_has_no_run_keys(): void
    {
        ShellEventDispatcher::exec('whoami', 'whoami');

        $this->assertSame(['command' => 'whoami', 'cmd_name' => 'whoami', 'event' => LifecycleEvent::ShellExec->value], $this->captured[0]['context']);
    }

    public function test_shell_denied_without_a_file_omits_the_file_key(): void
    {
        ShellEventDispatcher::denied('rm -rf /', 'rm', 'hard_blocked');

        $this->assertSame(['command' => 'rm -rf /', 'cmd_name' => 'rm', 'reason' => 'hard_blocked', 'event' => LifecycleEvent::ShellDenied->value], $this->captured[0]['context']);
    }

    public function test_shell_event_carries_parent_run_id(): void
    {
        HookRunContext::withRun('R1', fn () => ShellEventDispatcher::exec('whoami', 'whoami'), 'P1');

        $this->assertSame('R1', $this->captured[0]['context']['run_id']);
        $this->assertSame('P1', $this->captured[0]['context']['parent_run_id']);
    }

    public function test_a_command_run_by_the_agent_reports_shell_exec_under_the_run_of_its_tool_call(): void
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('anthropic');
        $provider->method('model')->willReturn('claude-haiku-4-5-20251001');
        $provider->method('send')->willReturnOnConsecutiveCalls(
            [
                'type' => 'tool_use_batch',
                'calls' => [['tool_use_id' => 'toolu_01', 'tool_name' => 'shell_exec', 'tool_input' => ['command' => 'echo phpclaw']]],
            ],
            ['type' => 'text', 'text' => 'Done.'],
        );

        Claw::builder()
            ->providerOverride($provider)
            ->tools([new ShellTool(allowlist: ['echo'])])
            ->build()
            ->send('Run echo phpclaw on the server.');

        $byEvent = [];
        foreach ($this->captured as $row) {
            $byEvent[$row['event']][] = $row['context'];
        }
        $this->assertCount(1, $byEvent[LifecycleEvent::ShellExec->value] ?? []);
        $this->assertNotSame('', $byEvent[LifecycleEvent::ToolBefore->value][0]['run_id'] ?? '');
        $this->assertSame(
            $byEvent[LifecycleEvent::ToolBefore->value][0]['run_id'],
            $byEvent[LifecycleEvent::ShellExec->value][0]['run_id'] ?? '',
        );
    }
}

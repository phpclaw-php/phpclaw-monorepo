<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Agent;

use PhpClaw\Agent\Agent;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\ToolRegistry;
use PHPUnit\Framework\TestCase;

final class RunIdPropagationTest extends TestCase
{
    protected function setUp(): void
    {
        HookRegistry::reset();
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
    }

    private function makeTextProvider(string $text = 'Done.'): ProviderInterface
    {
        $mock = $this->createMock(ProviderInterface::class);
        $mock->method('name')->willReturn('anthropic');
        $mock->method('model')->willReturn('claude-haiku-4-5-20251001');
        $mock->method('send')->willReturn(['type' => 'text', 'text' => $text]);

        return $mock;
    }

    private function makeToolProvider(string $toolName): ProviderInterface
    {
        $mock = $this->createMock(ProviderInterface::class);
        $mock->method('name')->willReturn('anthropic');
        $mock->method('model')->willReturn('claude-haiku-4-5-20251001');
        $mock->method('send')->willReturnOnConsecutiveCalls(
            [
                'type' => 'tool_use_batch',
                'calls' => [[
                    'tool_use_id' => 'tu_1',
                    'tool_name' => $toolName,
                    'tool_input' => ['q' => 'test'],
                ]],
            ],
            ['type' => 'text', 'text' => 'Done.'],
        );

        return $mock;
    }

    private function makeTool(string $name, string $result = 'ok'): ToolInterface
    {
        $mock = $this->createMock(ToolInterface::class);
        $mock->method('name')->willReturn($name);
        $mock->method('description')->willReturn('test tool');
        $mock->method('inputSchema')->willReturn([]);
        $mock->method('execute')->willReturn($result);

        return $mock;
    }

    public function test_all_hooks_share_same_run_id_on_text_run(): void
    {
        $captured = [];

        foreach (['agent.iteration', 'provider.request', 'provider.response'] as $event) {
            HookRegistry::on($event, static function (array $ctx) use ($event, &$captured): void {
                $captured[$event] = $ctx['run_id'] ?? '';
            });
        }

        $agent = new Agent($this->makeTextProvider(), new ToolRegistry);
        $response = $agent->run('hello', [], 'test-run-id-123');

        $this->assertSame('test-run-id-123', $captured['agent.iteration']);
        $this->assertSame('test-run-id-123', $captured['provider.request']);
        $this->assertSame('test-run-id-123', $captured['provider.response']);
        $this->assertSame('test-run-id-123', $response->runId);
    }

    public function test_tool_hooks_carry_run_id(): void
    {
        $toolRunIds = [];

        HookRegistry::on('tool.before', static function (array $ctx) use (&$toolRunIds): void {
            $toolRunIds['before'] = $ctx['run_id'] ?? '';
        });

        HookRegistry::on('tool.after', static function (array $ctx) use (&$toolRunIds): void {
            $toolRunIds['after'] = $ctx['run_id'] ?? '';
        });

        $registry = new ToolRegistry;
        $registry->register([$this->makeTool('search')]);

        $agent = new Agent($this->makeToolProvider('search'), $registry);
        $response = $agent->run('search something', [], 'run-abc');

        $this->assertSame('run-abc', $toolRunIds['before']);
        $this->assertSame('run-abc', $toolRunIds['after']);
        $this->assertSame('run-abc', $response->runId);
    }

    public function test_agent_iteration_carries_history(): void
    {
        $capturedHistory = null;

        HookRegistry::on('agent.iteration', static function (array $ctx) use (&$capturedHistory): void {
            $capturedHistory = $ctx['history'] ?? null;
        });

        $agent = new Agent($this->makeTextProvider(), new ToolRegistry);
        $agent->run('hello world', [], 'run-xyz');

        $this->assertIsArray($capturedHistory);
        $this->assertNotEmpty($capturedHistory);
    }

    public function test_two_runs_have_different_run_ids(): void
    {
        $runIds = [];

        HookRegistry::on('agent.iteration', static function (array $ctx) use (&$runIds): void {
            $runIds[] = $ctx['run_id'] ?? '';
        });

        $agent = new Agent($this->makeTextProvider(), new ToolRegistry);
        $agent->run('first', [], 'run-111');
        $agent->run('second', [], 'run-222');

        $this->assertCount(2, $runIds);
        $this->assertSame('run-111', $runIds[0]);
        $this->assertSame('run-222', $runIds[1]);
        $this->assertNotSame($runIds[0], $runIds[1]);
    }

    public function test_response_run_id_matches_hook_run_id(): void
    {
        $hookRunId = null;

        HookRegistry::on('agent.iteration', static function (array $ctx) use (&$hookRunId): void {
            $hookRunId = $ctx['run_id'] ?? null;
        });

        $agent = new Agent($this->makeTextProvider(), new ToolRegistry);
        $response = $agent->run('test', [], 'my-run-id');

        $this->assertNotNull($hookRunId);
        $this->assertSame($response->runId, $hookRunId);
    }

    public function test_max_iterations_hook_carries_run_id(): void
    {
        $capturedRunId = null;

        HookRegistry::on('agent.max_iterations', static function (array $ctx) use (&$capturedRunId): void {
            $capturedRunId = $ctx['run_id'] ?? '';
        });

        $tool = $this->makeTool('loop_tool');
        $registry = new ToolRegistry;
        $registry->register([$tool]);

        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('anthropic');
        $provider->method('model')->willReturn('claude-haiku-4-5-20251001');
        $provider->method('send')->willReturn([
            'type' => 'tool_use_batch',
            'calls' => [[
                'tool_use_id' => 'tu_1',
                'tool_name' => 'loop_tool',
                'tool_input' => [],
            ]],
        ]);

        $agent = new Agent($provider, $registry, maxIterations: 2);

        try {
            $agent->run('loop', [], 'run-loop');
        } catch (MaxIterationsException) {
        }

        $this->assertSame('run-loop', $capturedRunId);
    }
}

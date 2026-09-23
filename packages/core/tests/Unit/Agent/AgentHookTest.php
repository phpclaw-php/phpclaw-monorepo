<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Agent;

use PhpClaw\Agent\Agent;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\ToolRegistry;
use PHPUnit\Framework\TestCase;

final class AgentHookTest extends TestCase
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

    private function toolUseBatch(string $toolName, array $input = ['q' => 'test']): array
    {
        return [
            'type' => 'tool_use_batch',
            'calls' => [[
                'tool_use_id' => 'tu_1',
                'tool_name' => $toolName,
                'tool_input' => $input,
            ]],
        ];
    }

    private function makeRegisteredToolProvider(string $toolName): ProviderInterface
    {
        $mock = $this->createMock(ProviderInterface::class);
        $mock->method('name')->willReturn('anthropic');
        $mock->method('model')->willReturn('claude-haiku-4-5-20251001');
        $mock->method('send')->willReturnOnConsecutiveCalls(
            $this->toolUseBatch($toolName),
            ['type' => 'text', 'text' => 'Result.'],
        );

        return $mock;
    }

    private function makeUnregisteredToolProvider(string $toolName): ProviderInterface
    {
        $mock = $this->createMock(ProviderInterface::class);
        $mock->method('name')->willReturn('anthropic');
        $mock->method('model')->willReturn('claude-haiku-4-5-20251001');
        $mock->method('send')->willReturnOnConsecutiveCalls(
            $this->toolUseBatch($toolName),
            $this->toolUseBatch($toolName),
        );

        return $mock;
    }

    private function makeDummyTool(string $name): ToolInterface
    {
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('name')->willReturn($name);
        $tool->method('description')->willReturn('A test tool.');
        $tool->method('inputSchema')->willReturn(['type' => 'object', 'properties' => []]);
        $tool->method('execute')->willReturn('tool result');

        return $tool;
    }

    private function makeAgent(
        ProviderInterface $provider,
        array $tools = [],
        int $maxHistoryLength = 0,
    ): Agent {
        $registry = new ToolRegistry;
        if (! empty($tools)) {
            $registry->register($tools);
        }

        return new Agent($provider, $registry, maxIterations: 10, maxRetries: 0, maxHistoryLength: $maxHistoryLength);
    }

    public function test_tool_not_found_fires_when_unregistered_tool_called(): void
    {
        $context = [];

        HookRegistry::on('tool.not_found', function (array $ctx) use (&$context): void {
            $context = $ctx;
        });

        $provider = $this->makeUnregisteredToolProvider('ghost_tool');
        $agent = $this->makeAgent($provider);

        try {
            $agent->run('call ghost tool');
        } catch (\Throwable) {
        }

        $this->assertSame('ghost_tool', $context['tool_name'] ?? null);
        $this->assertArrayHasKey('tool_input', $context);
        $this->assertArrayHasKey('available_tools', $context);
    }

    public function test_tool_not_found_includes_available_tools_list(): void
    {
        $context = [];

        HookRegistry::on('tool.not_found', function (array $ctx) use (&$context): void {
            $context = $ctx;
        });

        $provider = $this->makeUnregisteredToolProvider('nonexistent');
        $agent = $this->makeAgent($provider, tools: [$this->makeDummyTool('real_tool')]);

        try {
            $agent->run('go');
        } catch (\Throwable) {
        }

        $this->assertContains('real_tool', $context['available_tools'] ?? []);
        $this->assertNotContains('nonexistent', $context['available_tools'] ?? []);
    }

    public function test_tool_not_found_does_not_fire_for_registered_tool(): void
    {
        $fired = false;

        HookRegistry::on('tool.not_found', function () use (&$fired): void {
            $fired = true;
        });

        $provider = $this->makeRegisteredToolProvider('real_tool');
        $agent = $this->makeAgent($provider, tools: [$this->makeDummyTool('real_tool')]);

        $agent->run('call real tool');

        $this->assertFalse($fired, 'tool.not_found must NOT fire when the tool is registered');
    }

    public function test_tool_not_found_includes_tool_input_in_context(): void
    {
        $context = [];

        HookRegistry::on('tool.not_found', function (array $ctx) use (&$context): void {
            $context = $ctx;
        });

        $provider = $this->makeUnregisteredToolProvider('ghost');
        $agent = $this->makeAgent($provider);

        try {
            $agent->run('go');
        } catch (\Throwable) {
        }

        $this->assertSame(['q' => 'test'], $context['tool_input'] ?? null);
    }

    public function test_context_overflow_fires_when_history_exceeds_limit(): void
    {
        $context = [];

        HookRegistry::on('context.overflow', function (array $ctx) use (&$context): void {
            $context = $ctx;
        });

        $mock = $this->createMock(ProviderInterface::class);
        $mock->method('name')->willReturn('anthropic');
        $mock->method('model')->willReturn('claude-haiku-4-5-20251001');
        $mock->method('send')->willReturnOnConsecutiveCalls(
            [
                'type' => 'tool_use_batch',
                'calls' => [[
                    'tool_use_id' => 'tu_1',
                    'tool_name' => 'real_tool',
                    'tool_input' => [],
                ]],
            ],
            ['type' => 'text', 'text' => 'Summary.'],
            ['type' => 'text', 'text' => 'Done.'],
        );

        $agent = $this->makeAgent($mock, tools: [$this->makeDummyTool('real_tool')], maxHistoryLength: 1);
        $agent->run('hello');

        $this->assertNotEmpty($context, 'context.overflow must fire');
        $this->assertArrayHasKey('history_length', $context);
        $this->assertArrayHasKey('max_history_length', $context);
        $this->assertSame(1, $context['max_history_length']);
        $this->assertGreaterThan(1, $context['history_length']);
    }

    public function test_context_overflow_does_not_fire_when_disabled(): void
    {
        $fired = false;

        HookRegistry::on('context.overflow', function () use (&$fired): void {
            $fired = true;
        });

        $agent = $this->makeAgent($this->makeTextProvider(), maxHistoryLength: 0);
        $agent->run('hello');

        $this->assertFalse($fired, 'context.overflow must NOT fire when maxHistoryLength is 0');
    }

    public function test_context_overflow_includes_provider_and_model(): void
    {
        $context = [];

        HookRegistry::on('context.overflow', function (array $ctx) use (&$context): void {
            $context = $ctx;
        });

        $mock = $this->createMock(ProviderInterface::class);
        $mock->method('name')->willReturn('anthropic');
        $mock->method('model')->willReturn('claude-haiku-4-5-20251001');
        $mock->method('send')->willReturnOnConsecutiveCalls(
            [
                'type' => 'tool_use_batch',
                'calls' => [[
                    'tool_use_id' => 'tu_1',
                    'tool_name' => 'real_tool',
                    'tool_input' => [],
                ]],
            ],
            ['type' => 'text', 'text' => 'Summary.'],
            ['type' => 'text', 'text' => 'Done.'],
        );

        $agent = $this->makeAgent($mock, tools: [$this->makeDummyTool('real_tool')], maxHistoryLength: 1);
        $agent->run('hello');

        $this->assertSame('anthropic', $context['provider'] ?? null);
        $this->assertSame('claude-haiku-4-5-20251001', $context['model'] ?? null);
    }

    public function test_context_overflow_does_not_fire_when_within_limit(): void
    {
        $fired = false;

        HookRegistry::on('context.overflow', function () use (&$fired): void {
            $fired = true;
        });

        $agent = $this->makeAgent($this->makeTextProvider(), maxHistoryLength: 100);
        $agent->run('hello');

        $this->assertFalse($fired, 'context.overflow must NOT fire when history is within limit');
    }
}

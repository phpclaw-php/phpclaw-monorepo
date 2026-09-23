<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Service;

use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Magento\Service\ToolCall;
use PhpClaw\Magento\Service\ToolCallCollector;
use PHPUnit\Framework\TestCase;

final class ToolCallCollectorTest extends TestCase
{
    protected function setUp(): void
    {
        HookRegistry::reset();
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
    }

    public function test_collects_named_tool_calls_from_hook(): void
    {
        $collector = new ToolCallCollector;
        $collector->listen();

        HookRegistry::fire('tool.after', [
            'tool_name' => 'db_query',
            'tool_input' => ['query' => 'SELECT 1'],
            'tool_result' => 'ok',
        ]);

        self::assertFalse($collector->isEmpty());
        $list = $collector->toArrayList();
        self::assertCount(1, $list);
        self::assertSame('db_query', $list[0]['tool_name']);
        self::assertSame(['query' => 'SELECT 1'], $list[0]['tool_input']);
    }

    public function test_drops_tool_calls_with_empty_name(): void
    {
        $collector = new ToolCallCollector;
        $collector->listen();

        HookRegistry::fire('tool.after', ['tool_name' => '', 'tool_result' => 'x']);

        self::assertTrue($collector->isEmpty());
        self::assertSame([], $collector->toArrayList());
    }

    public function test_on_collect_callback_runs_only_for_named_calls(): void
    {
        $collector = new ToolCallCollector;
        $seen = [];
        $collector->listen(static function (ToolCall $call) use (&$seen): void {
            $seen[] = $call->toolName;
        });

        HookRegistry::fire('tool.after', ['tool_name' => 'a', 'tool_result' => '1']);
        HookRegistry::fire('tool.after', ['tool_name' => '',  'tool_result' => '2']);

        self::assertSame(['a'], $seen);
    }
}

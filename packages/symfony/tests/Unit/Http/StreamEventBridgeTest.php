<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit\Http;

use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use PhpClaw\Symfony\Http\StreamEventBridge;
use PHPUnit\Framework\TestCase;

final class StreamEventBridgeTest extends TestCase
{
    protected function setUp(): void
    {
        HookRegistry::reset();
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
    }

    public function test_end_clears_emitter_and_tool_calls_after_normal_run(): void
    {
        $emitted = [];
        $bridge = new StreamEventBridge;
        $bridge->begin(static function (string $event, array $payload) use (&$emitted): void {
            $emitted[] = [$event, $payload];
        });

        HookRegistry::on(LifecycleEvent::ToolAfter->value, $bridge);

        HookRegistry::fire(LifecycleEvent::ToolAfter->value, [
            'event' => LifecycleEvent::ToolAfter->value,
            'tool_name' => 'db_query',
            'tool_input' => [],
            'tool_result' => 'rows',
        ]);

        self::assertCount(1, $bridge->toolCalls());
        self::assertCount(1, $emitted);

        $bridge->end();

        self::assertCount(0, $bridge->toolCalls());

        HookRegistry::fire(LifecycleEvent::ToolAfter->value, [
            'event' => LifecycleEvent::ToolAfter->value,
            'tool_name' => 'db_query',
            'tool_input' => [],
            'tool_result' => 'rows2',
        ]);

        self::assertCount(1, $emitted, 'No additional emit must happen after end().');
        self::assertCount(0, $bridge->toolCalls(), 'tool_calls must remain empty after end().');
    }

    public function test_end_clears_state_after_mid_stream_exception(): void
    {
        $emitCount = 0;
        $bridge = new StreamEventBridge;
        $bridge->begin(static function (string $event, array $payload) use (&$emitCount): void {
            $emitCount++;
        });

        HookRegistry::on(LifecycleEvent::ToolBefore->value, $bridge);

        HookRegistry::fire(LifecycleEvent::ToolBefore->value, [
            'event' => LifecycleEvent::ToolBefore->value,
            'tool_name' => 'log',
            'tool_input' => [],
        ]);

        self::assertSame(1, $emitCount);

        $bridge->end();

        self::assertCount(0, $bridge->toolCalls());

        HookRegistry::fire(LifecycleEvent::ToolBefore->value, [
            'event' => LifecycleEvent::ToolBefore->value,
            'tool_name' => 'log',
            'tool_input' => [],
        ]);

        self::assertSame(1, $emitCount, 'No emit must occur after end(), even if another request fires the hook.');
    }

    public function test_handle_noop_when_no_stream_active(): void
    {
        $bridge = new StreamEventBridge;
        $emitted = false;

        $bridge->handle(['event' => LifecycleEvent::ToolAfter->value, 'tool_name' => 'test', 'tool_input' => [], 'tool_result' => 'x']);

        self::assertFalse($emitted);
        self::assertCount(0, $bridge->toolCalls());
    }

    public function test_tool_before_emits_tool_before_event(): void
    {
        $events = [];
        $bridge = new StreamEventBridge;
        $bridge->begin(static function (string $event, array $payload) use (&$events): void {
            $events[] = $event;
        });

        $bridge->handle([
            'event' => LifecycleEvent::ToolBefore->value,
            'tool_name' => 'db_query',
            'tool_input' => ['sql' => 'SELECT 1'],
        ]);

        self::assertSame(['tool_before'], $events);
        self::assertCount(0, $bridge->toolCalls(), 'tool_before must not add to toolCalls.');
    }

    public function test_tool_after_collects_in_tool_calls(): void
    {
        $bridge = new StreamEventBridge;
        $bridge->begin(static function (string $event, array $payload): void {});

        $bridge->handle([
            'event' => LifecycleEvent::ToolAfter->value,
            'tool_name' => 'read_log',
            'tool_input' => [],
            'tool_result' => 'ok',
        ]);

        $calls = $bridge->toolCalls();
        self::assertCount(1, $calls);
        self::assertSame('read_log', $calls[0]['tool_name']);
        self::assertSame('ok', $calls[0]['tool_result']);
    }

    public function test_tool_after_with_empty_tool_name_is_ignored(): void
    {
        $bridge = new StreamEventBridge;
        $bridge->begin(static function (string $event, array $payload): void {});

        $bridge->handle([
            'event' => LifecycleEvent::ToolAfter->value,
            'tool_name' => '',
            'tool_input' => [],
            'tool_result' => 'x',
        ]);

        self::assertCount(0, $bridge->toolCalls());
    }

    public function test_separate_instances_do_not_share_stream_state(): void
    {
        $first = new StreamEventBridge;
        $second = new StreamEventBridge;

        $first->begin(static function (string $event, array $payload): void {});

        $second->handle([
            'event' => LifecycleEvent::ToolAfter->value,
            'tool_name' => 'db_query',
            'tool_input' => [],
            'tool_result' => 'rows',
        ]);

        self::assertCount(0, $first->toolCalls());
        self::assertCount(0, $second->toolCalls());
    }
}

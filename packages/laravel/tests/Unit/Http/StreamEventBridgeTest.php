<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit\Http;

use PhpClaw\Hooks\LifecycleEvent;
use PhpClaw\Laravel\Http\StreamEventBridge;
use PHPUnit\Framework\TestCase;

final class StreamEventBridgeTest extends TestCase
{
    protected function setUp(): void
    {
        StreamEventBridge::end();
    }

    protected function tearDown(): void
    {
        StreamEventBridge::end();
    }

    public function test_handle_is_noop_when_no_emitter_set(): void
    {
        $called = false;

        $bridge = new StreamEventBridge;
        $bridge->handle(['event' => LifecycleEvent::ToolBefore->value, 'tool_name' => 'shell']);

        $this->assertFalse($called);
        $this->assertSame([], StreamEventBridge::toolCalls());
    }

    public function test_tool_before_event_emits_via_emitter(): void
    {
        $emitted = [];
        StreamEventBridge::begin(function (string $event, array $data) use (&$emitted): void {
            $emitted[] = ['event' => $event, 'data' => $data];
        });

        $bridge = new StreamEventBridge;
        $bridge->handle([
            'event' => LifecycleEvent::ToolBefore->value,
            'tool_name' => 'shell',
            'tool_input' => ['command' => 'ls'],
        ]);

        $this->assertCount(1, $emitted);
        $this->assertSame('tool_before', $emitted[0]['event']);
        $this->assertSame('shell', $emitted[0]['data']['tool_name']);
        $this->assertSame(['command' => 'ls'], $emitted[0]['data']['tool_input']);
    }

    public function test_tool_before_event_does_not_accumulate_tool_calls(): void
    {
        StreamEventBridge::begin(function (string $event, array $data): void {});

        $bridge = new StreamEventBridge;
        $bridge->handle([
            'event' => LifecycleEvent::ToolBefore->value,
            'tool_name' => 'shell',
            'tool_input' => [],
        ]);

        $this->assertSame([], StreamEventBridge::toolCalls());
    }

    public function test_tool_after_event_accumulates_tool_calls(): void
    {
        $emitted = [];
        StreamEventBridge::begin(function (string $event, array $data) use (&$emitted): void {
            $emitted[] = $event;
        });

        $bridge = new StreamEventBridge;
        $bridge->handle([
            'event' => LifecycleEvent::ToolAfter->value,
            'tool_name' => 'db_query',
            'tool_input' => ['sql' => 'SELECT 1'],
            'tool_result' => 'result-row',
        ]);

        $calls = StreamEventBridge::toolCalls();
        $this->assertCount(1, $calls);
        $this->assertSame('db_query', $calls[0]['tool_name']);
        $this->assertSame(['sql' => 'SELECT 1'], $calls[0]['tool_input']);
        $this->assertSame('result-row', $calls[0]['tool_result']);
        $this->assertContains('tool_after', $emitted);
    }

    public function test_multiple_tool_after_events_accumulate_in_order(): void
    {
        StreamEventBridge::begin(function (string $event, array $data): void {});

        $bridge = new StreamEventBridge;
        $bridge->handle([
            'event' => LifecycleEvent::ToolAfter->value,
            'tool_name' => 'tool_a',
            'tool_input' => [],
            'tool_result' => 'a',
        ]);
        $bridge->handle([
            'event' => LifecycleEvent::ToolAfter->value,
            'tool_name' => 'tool_b',
            'tool_input' => [],
            'tool_result' => 'b',
        ]);

        $calls = StreamEventBridge::toolCalls();
        $this->assertCount(2, $calls);
        $this->assertSame('tool_a', $calls[0]['tool_name']);
        $this->assertSame('tool_b', $calls[1]['tool_name']);
    }

    public function test_tool_after_with_empty_tool_name_is_skipped(): void
    {
        StreamEventBridge::begin(function (string $event, array $data): void {});

        $bridge = new StreamEventBridge;
        $bridge->handle([
            'event' => LifecycleEvent::ToolAfter->value,
            'tool_name' => '',
            'tool_input' => [],
            'tool_result' => 'result',
        ]);

        $this->assertSame([], StreamEventBridge::toolCalls());
    }

    public function test_end_resets_both_static_emitter_and_tool_calls(): void
    {
        $emitCount = 0;
        StreamEventBridge::begin(function (string $event, array $data) use (&$emitCount): void {
            $emitCount++;
        });

        $bridge = new StreamEventBridge;
        $bridge->handle([
            'event' => LifecycleEvent::ToolAfter->value,
            'tool_name' => 'shell',
            'tool_input' => [],
            'tool_result' => 'ok',
        ]);

        $this->assertCount(1, StreamEventBridge::toolCalls());

        StreamEventBridge::end();

        $this->assertSame([], StreamEventBridge::toolCalls());

        $before = $emitCount;
        $bridge->handle([
            'event' => LifecycleEvent::ToolAfter->value,
            'tool_name' => 'shell',
            'tool_input' => [],
            'tool_result' => 'post-end',
        ]);
        $this->assertSame($before, $emitCount);
        $this->assertSame([], StreamEventBridge::toolCalls());
    }

    public function test_end_resets_both_statics_even_when_exception_thrown_mid_stream(): void
    {
        $bridge = new StreamEventBridge;
        $emitted = [];

        StreamEventBridge::begin(function (string $event, array $data) use (&$emitted): void {
            $emitted[] = $event;
        });
        $bridge->handle([
            'event' => LifecycleEvent::ToolAfter->value,
            'tool_name' => 'http',
            'tool_input' => [],
            'tool_result' => 'response',
        ]);

        $this->assertCount(1, StreamEventBridge::toolCalls());
        $emittedDuringStream = $emitted;

        try {
            try {
                throw new \RuntimeException('Simulated mid-stream exception');
            } finally {
                StreamEventBridge::end();
            }
        } catch (\RuntimeException) {
        }

        $this->assertSame([], StreamEventBridge::toolCalls(), 'end() must clear the collected tool calls');

        $bridge->handle([
            'event' => LifecycleEvent::ToolBefore->value,
            'tool_name' => 'shell',
            'tool_input' => [],
        ]);

        $this->assertSame(
            $emittedDuringStream,
            $emitted,
            'end() must clear the emitter, so a later handle() emits nothing',
        );
    }

    public function test_begin_resets_tool_calls_from_previous_stream(): void
    {
        StreamEventBridge::begin(function (string $event, array $data): void {});

        $bridge = new StreamEventBridge;
        $bridge->handle([
            'event' => LifecycleEvent::ToolAfter->value,
            'tool_name' => 'old_tool',
            'tool_input' => [],
            'tool_result' => 'stale',
        ]);

        $this->assertCount(1, StreamEventBridge::toolCalls());

        StreamEventBridge::begin(function (string $event, array $data): void {});

        $this->assertSame([], StreamEventBridge::toolCalls());
    }

    public function test_handle_ignores_unknown_event_types(): void
    {
        $emitted = [];
        StreamEventBridge::begin(function (string $event, array $data) use (&$emitted): void {
            $emitted[] = $event;
        });

        $bridge = new StreamEventBridge;
        $bridge->handle(['event' => 'agent.before', 'tool_name' => 'shell']);

        $this->assertSame([], $emitted);
        $this->assertSame([], StreamEventBridge::toolCalls());
    }
}

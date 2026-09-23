<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Hooks;

use PhpClaw\Hooks\HookEventBridge;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use PHPUnit\Framework\TestCase;

final class HookEventBridgeTest extends TestCase
{
    protected function setUp(): void
    {
        HookRegistry::reset();
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
    }

    public function test_default_events_returns_every_lifecycle_event(): void
    {
        $bridge = new HookEventBridge(static fn (string $e, array $c) => null);

        $this->assertSame(LifecycleEvent::all(), $bridge->resolvedEvents());
    }

    public function test_explicit_events_list_is_honoured(): void
    {
        $bridge = new HookEventBridge(
            dispatcher: static fn (string $e, array $c) => null,
            events: ['agent.before', 'tool.after'],
        );

        $this->assertSame(['agent.before', 'tool.after'], $bridge->resolvedEvents());
    }

    public function test_register_attaches_one_listener_per_event(): void
    {
        $calls = [];
        $bridge = new HookEventBridge(
            dispatcher: static function (string $event, array $context) use (&$calls): void {
                $calls[] = [$event, $context];
            },
            events: ['agent.before', 'tool.after'],
        );

        $bridge->register();

        HookRegistry::fire('agent.before', ['run_id' => 'abc']);
        HookRegistry::fire('tool.after', ['tool_name' => 'foo']);

        $this->assertSame(
            [
                ['agent.before', ['run_id' => 'abc', 'event' => 'agent.before']],
                ['tool.after',   ['tool_name' => 'foo', 'event' => 'tool.after']],
            ],
            $calls,
        );
    }

    public function test_unregistered_events_do_not_trigger_dispatcher(): void
    {
        $calls = [];
        $bridge = new HookEventBridge(
            dispatcher: static function (string $event, array $context) use (&$calls): void {
                $calls[] = $event;
            },
            events: ['agent.before'],
        );

        $bridge->register();

        HookRegistry::fire('tool.after', []);
        HookRegistry::fire('provider.request', []);

        $this->assertSame([], $calls);
    }

    public function test_register_with_default_events_bridges_every_lifecycle_event(): void
    {
        $bridged = [];
        $bridge = new HookEventBridge(
            dispatcher: static function (string $event, array $context) use (&$bridged): void {
                $bridged[] = $event;
            },
        );

        $bridge->register();

        foreach (LifecycleEvent::all() as $event) {
            HookRegistry::fire($event, []);
        }

        $this->assertSame(LifecycleEvent::all(), $bridged);
    }

    public function test_two_bridge_instances_can_register_for_overlapping_events(): void
    {
        $first = [];
        $second = [];

        (new HookEventBridge(
            dispatcher: static function (string $event, array $context) use (&$first): void {
                $first[] = $event;
            },
            events: ['agent.before'],
        ))->register();

        (new HookEventBridge(
            dispatcher: static function (string $event, array $context) use (&$second): void {
                $second[] = $event;
            },
            events: ['agent.before', 'tool.after'],
        ))->register();

        HookRegistry::fire('agent.before', []);
        HookRegistry::fire('tool.after', []);

        $this->assertSame(['agent.before'], $first);
        $this->assertSame(['agent.before', 'tool.after'], $second);
    }

    public function test_dispatcher_receives_event_name_and_context(): void
    {
        $captured = null;
        $bridge = new HookEventBridge(
            dispatcher: static function (string $event, array $context) use (&$captured): void {
                $captured = [$event, $context];
            },
            events: ['provider.response'],
        );

        $bridge->register();

        HookRegistry::fire('provider.response', [
            'provider' => 'anthropic',
            'model' => 'claude-opus-4-7',
            'duration_ms' => 1234,
        ]);

        $this->assertSame(
            [
                'provider.response',
                [
                    'provider' => 'anthropic',
                    'model' => 'claude-opus-4-7',
                    'duration_ms' => 1234,
                    'event' => 'provider.response',
                ],
            ],
            $captured,
        );
    }

    public function test_priority_accessor_returns_constructor_value(): void
    {
        $bridge = new HookEventBridge(
            dispatcher: static fn (string $e, array $c) => null,
            priority: 100,
        );

        $this->assertSame(100, $bridge->priority());
    }

    public function test_default_priority_is_fifty(): void
    {
        $bridge = new HookEventBridge(static fn (string $e, array $c) => null);

        $this->assertSame(50, $bridge->priority());
    }

    public function test_empty_events_list_is_a_no_op(): void
    {
        $calls = [];
        $bridge = new HookEventBridge(
            dispatcher: static function (string $event, array $context) use (&$calls): void {
                $calls[] = $event;
            },
            events: [],
        );

        $bridge->register();

        HookRegistry::fire('agent.before', []);
        HookRegistry::fire('tool.after', []);

        $this->assertSame([], $calls);
    }
}

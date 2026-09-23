<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit\Events;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Orchestra\Testbench\TestCase;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use PhpClaw\Laravel\Events\HookEventBridge;
use PhpClaw\Laravel\PhpClawServiceProvider;

final class HookEventBridgeTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('phpclaw.api_key', 'test-api-key');
        $app['config']->set('phpclaw.memory_driver', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();
        HookRegistry::reset();

        (new HookEventBridge(events: $this->app->make(Dispatcher::class)))
            ->register();
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
        parent::tearDown();
    }

    public function test_bridge_covers_every_core_lifecycle_event(): void
    {
        $bridged = [];
        foreach (LifecycleEvent::all() as $event) {
            Event::listen('phpclaw.'.$event, function () use ($event, &$bridged): void {
                $bridged[] = $event;
            });
        }

        foreach (LifecycleEvent::all() as $event) {
            HookRegistry::fire($event, ['evt' => $event]);
        }

        $this->assertSame(
            LifecycleEvent::all(),
            $bridged,
            'Every phpClaw lifecycle event must bridge to the Laravel dispatcher.',
        );
    }

    public function test_agent_after_event_is_rebroadcast_through_laravel_dispatcher(): void
    {
        $captured = null;

        Event::listen('phpclaw.agent.after', function (array $ctx) use (&$captured): void {
            $captured = $ctx;
        });

        HookRegistry::fire('agent.after', [
            'message' => 'hello',
            'provider' => 'anthropic',
            'model' => 'claude-haiku-4-5-20251001',
        ]);

        $this->assertNotNull($captured, 'phpclaw.agent.after did not reach the Laravel dispatcher');
        $this->assertSame('anthropic', $captured['provider']);
        $this->assertSame('hello', $captured['message']);
    }

    public function test_memory_events_are_bridged(): void
    {
        $hits = [];
        Event::listen('phpclaw.memory.write', function (array $ctx) use (&$hits): void {
            $hits[] = ['evt' => 'write', 'ctx' => $ctx];
        });
        Event::listen('phpclaw.memory.read', function (array $ctx) use (&$hits): void {
            $hits[] = ['evt' => 'read', 'ctx' => $ctx];
        });
        Event::listen('phpclaw.memory.forget', function (array $ctx) use (&$hits): void {
            $hits[] = ['evt' => 'forget', 'ctx' => $ctx];
        });

        HookRegistry::fire('memory.write', ['key' => 'k', 'namespace' => 'ns', 'driver' => 'array']);
        HookRegistry::fire('memory.read', ['key' => 'k', 'namespace' => 'ns', 'driver' => 'array', 'hit' => true]);
        HookRegistry::fire('memory.forget', ['key' => 'k', 'namespace' => 'ns', 'driver' => 'array']);

        $this->assertCount(3, $hits);
        $this->assertSame(['write', 'read', 'forget'], array_column($hits, 'evt'));
    }

    public function test_tool_events_are_bridged(): void
    {
        $captured = [];
        Event::listen('phpclaw.tool.before', function (array $ctx) use (&$captured): void {
            $captured[] = 'before';
        });
        Event::listen('phpclaw.tool.after', function (array $ctx) use (&$captured): void {
            $captured[] = 'after';
        });
        Event::listen('phpclaw.tool.error', function (array $ctx) use (&$captured): void {
            $captured[] = 'error';
        });

        HookRegistry::fire('tool.before', ['tool_name' => 'shell']);
        HookRegistry::fire('tool.after', ['tool_name' => 'shell']);
        HookRegistry::fire('tool.error', ['tool_name' => 'shell', 'error' => 'boom']);

        $this->assertSame(['before', 'after', 'error'], $captured);
    }

    public function test_guard_blocked_event_is_bridged(): void
    {
        $captured = null;
        Event::listen('phpclaw.guard.blocked', function (array $ctx) use (&$captured): void {
            $captured = $ctx;
        });

        HookRegistry::fire('guard.blocked', ['message' => 'attempt', 'reason' => 'injection_pattern']);

        $this->assertSame('injection_pattern', $captured['reason']);
    }

    public function test_shell_events_are_bridged(): void
    {
        $captured = [];
        Event::listen('phpclaw.shell.exec', function (array $ctx) use (&$captured): void {
            $captured[] = 'exec';
        });
        Event::listen('phpclaw.shell.denied', function (array $ctx) use (&$captured): void {
            $captured[] = 'denied';
        });

        HookRegistry::fire('shell.exec', ['command' => 'ls', 'cmd_name' => 'ls']);
        HookRegistry::fire('shell.denied', ['command' => 'rm -rf /', 'cmd_name' => 'rm', 'reason' => 'hard_blocked']);

        $this->assertSame(['exec', 'denied'], $captured);
    }

    public function test_unbridged_events_do_not_reach_laravel_dispatcher(): void
    {
        $hits = 0;
        Event::listen('phpclaw.unknown.future', function () use (&$hits): void {
            $hits++;
        });

        HookRegistry::fire('unknown.future', ['x' => 'y']);

        $this->assertSame(0, $hits);
    }

    public function test_register_is_idempotent_second_call_is_no_op(): void
    {
        HookRegistry::reset();

        $bridge = new HookEventBridge(
            events: $this->app->make(Dispatcher::class),
        );
        $bridge->register();
        $bridge->register();

        $fireCount = 0;
        Event::listen('phpclaw.agent.before', function () use (&$fireCount): void {
            $fireCount++;
        });

        HookRegistry::fire('agent.before', []);

        $this->assertSame(1, $fireCount, 'register() called twice on same bridge must not double-fire the event');
    }

    public function test_bridge_does_not_swallow_existing_hook_listeners(): void
    {
        $hookCalled = false;
        $laravelCalled = false;

        HookRegistry::on('agent.after', function () use (&$hookCalled): void {
            $hookCalled = true;
        });

        Event::listen('phpclaw.agent.after', function () use (&$laravelCalled): void {
            $laravelCalled = true;
        });

        HookRegistry::fire('agent.after', ['message' => 'x']);

        $this->assertTrue($hookCalled, 'Original HookRegistry listener should still fire');
        $this->assertTrue($laravelCalled, 'Bridged Laravel listener should also fire');
    }
}

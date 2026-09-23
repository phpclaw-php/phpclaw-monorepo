<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit\Events;

use PhpClaw\Hooks\HookEventBridge;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use PhpClaw\Symfony\Events\PhpClawEvent;
use PhpClaw\Symfony\PhpClawBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class HookEventBridgeTest extends TestCase
{
    private EventDispatcher $dispatcher;

    protected function setUp(): void
    {
        HookRegistry::reset();
        PhpClawBundle::resetEventBridge();
        $this->dispatcher = new EventDispatcher;
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
        PhpClawBundle::resetEventBridge();
    }

    private function makeDispatchFn(EventDispatcher $dispatcher): \Closure
    {
        return static function (string $event, array $ctx) use ($dispatcher): void {
            $dispatcher->dispatch(new PhpClawEvent($event, $ctx), 'phpclaw.'.$event);
        };
    }

    public function test_fires_symfony_event_on_hookregistry_fire(): void
    {
        $observed = null;
        $this->dispatcher->addListener('phpclaw.agent.after', function (PhpClawEvent $e) use (&$observed): void {
            $observed = $e;
        });

        (new HookEventBridge($this->makeDispatchFn($this->dispatcher)))->register();
        HookRegistry::fire('agent.after', ['msg' => 'hi', 'tokens' => 5]);

        self::assertInstanceOf(PhpClawEvent::class, $observed);
        self::assertSame('agent.after', $observed->name);
        self::assertSame(['msg' => 'hi', 'tokens' => 5, 'event' => 'agent.after'], $observed->context);
    }

    public function test_bridges_every_lifecycle_event(): void
    {
        (new HookEventBridge($this->makeDispatchFn($this->dispatcher)))->register();

        foreach (LifecycleEvent::all() as $event) {
            $fired = false;
            $this->dispatcher->addListener('phpclaw.'.$event, function () use (&$fired): void {
                $fired = true;
            });

            HookRegistry::fire($event, ['x' => 1]);

            self::assertTrue($fired, "phpclaw.{$event} must reach Symfony EventDispatcher.");
        }
    }

    public function test_bridged_event_count_matches_all_lifecycle_events(): void
    {
        $fired = [];

        foreach (LifecycleEvent::all() as $event) {
            $this->dispatcher->addListener('phpclaw.'.$event, function () use ($event, &$fired): void {
                $fired[] = $event;
            });
        }

        (new HookEventBridge($this->makeDispatchFn($this->dispatcher)))->register();

        foreach (LifecycleEvent::all() as $event) {
            HookRegistry::fire($event, []);
        }

        self::assertCount(count(LifecycleEvent::all()), $fired, 'Every LifecycleEvent must be bridged exactly once.');
    }

    public function test_unknown_event_does_not_bridge_as_phpclaw_prefixed(): void
    {
        (new HookEventBridge($this->makeDispatchFn($this->dispatcher)))->register();

        $fired = false;
        $this->dispatcher->addListener('phpclaw.unknown.future', function () use (&$fired): void {
            $fired = true;
        });

        HookRegistry::fire('unknown.future', ['x' => 1]);

        self::assertFalse($fired, 'Non-LifecycleEvent names must not be bridged to the Symfony dispatcher.');
    }

    public function test_single_registration_fires_once_per_event(): void
    {
        $count = 0;
        $this->dispatcher->addListener('phpclaw.agent.after', function () use (&$count): void {
            $count++;
        });

        (new HookEventBridge($this->makeDispatchFn($this->dispatcher)))->register();
        HookRegistry::fire('agent.after', []);
        HookRegistry::fire('agent.after', []);

        self::assertSame(2, $count);
    }

    public function test_original_hook_still_fires(): void
    {
        $hookFired = false;
        $eventFired = false;

        HookRegistry::on('agent.before', static function () use (&$hookFired): void {
            $hookFired = true;
        });

        $this->dispatcher->addListener('phpclaw.agent.before', function () use (&$eventFired): void {
            $eventFired = true;
        });

        (new HookEventBridge($this->makeDispatchFn($this->dispatcher)))->register();
        HookRegistry::fire('agent.before', []);

        self::assertTrue($hookFired);
        self::assertTrue($eventFired);
    }
}

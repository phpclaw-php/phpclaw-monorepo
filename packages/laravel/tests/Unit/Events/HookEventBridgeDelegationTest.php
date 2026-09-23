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

final class HookEventBridgeDelegationTest extends TestCase
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

    public function test_canonical_feature_every_bridged_event_fires_as_phpclaw_event(): void
    {
        $events = LifecycleEvent::all();
        $fired = [];

        foreach ($events as $event) {
            Event::listen('phpclaw.'.$event, function (array $ctx) use ($event, &$fired): void {
                $fired[] = $event;
            });
        }

        foreach ($events as $event) {
            HookRegistry::fire($event, ['event' => $event]);
        }

        $this->assertCount(count($events), $fired);
        $this->assertSame($events, $fired);
    }

    public function test_mixed_entries_bridge_and_raw_hook_listeners_both_fire(): void
    {
        $hookFired = false;
        $laravelFired = false;

        HookRegistry::on('tool.after', function (array $ctx) use (&$hookFired): void {
            $hookFired = true;
        });

        Event::listen('phpclaw.tool.after', function (array $ctx) use (&$laravelFired): void {
            $laravelFired = true;
        });

        HookRegistry::fire('tool.after', ['tool_name' => 'http_get']);

        $this->assertTrue($hookFired, 'Raw HookRegistry listener must still fire');
        $this->assertTrue($laravelFired, 'Bridged Laravel listener must fire via canonical');
    }

    public function test_uses_canonical_not_local_no_hookregistry_on_loop_in_adapter(): void
    {
        $source = (string) file_get_contents(
            __DIR__.'/../../../src/Events/HookEventBridge.php'
        );

        $this->assertStringNotContainsString(
            'HookRegistry::on(',
            $source,
            'Adapter HookEventBridge must not contain a HookRegistry::on() loop, delegation to canonical only',
        );

        $this->assertStringContainsString(
            'CoreHookEventBridge',
            $source,
            'Adapter HookEventBridge must delegate to PhpClaw\Hooks\HookEventBridge (alias CoreHookEventBridge)',
        );
    }
}

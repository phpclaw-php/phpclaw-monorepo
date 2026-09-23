<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit\Events;

use Illuminate\Support\Facades\Event;
use Orchestra\Testbench\TestCase;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Laravel\PhpClawServiceProvider;

final class HookEventBridgeDisabledTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('phpclaw.api_key', 'test-api-key');
        $app['config']->set('phpclaw.memory_driver', 'array');
        $app['config']->set('phpclaw.events.bridge', false);
    }

    protected function setUp(): void
    {
        parent::setUp();
        HookRegistry::reset();
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
        parent::tearDown();
    }

    public function test_bridge_disabled_means_no_phpclaw_event_on_laravel_dispatcher(): void
    {
        $captured = false;

        Event::listen('phpclaw.agent.after', function () use (&$captured): void {
            $captured = true;
        });

        HookRegistry::fire('agent.after', ['message' => 'hi']);

        $this->assertFalse(
            $captured,
            'When bridge is disabled, HookRegistry events must not reach Laravel listeners',
        );
    }
}

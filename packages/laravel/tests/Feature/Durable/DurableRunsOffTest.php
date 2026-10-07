<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Feature\Durable;

use Illuminate\Auth\GenericUser;
use Illuminate\Console\Scheduling\Schedule;
use Orchestra\Testbench\TestCase;
use PhpClaw\Laravel\PhpClawServiceProvider;

final class DurableRunsOffTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('phpclaw.api_key', 'test-key');
        $app['config']->set('phpclaw.api.enabled', true);
        $app['config']->set('phpclaw.api.prefix', 'phpclaw');
        $app['config']->set('phpclaw.api.middleware', ['api']);
    }

    public function test_with_durable_runs_off_there_are_no_run_routes_and_no_schedule(): void
    {
        $this->actingAs(new GenericUser(['id' => 7]))->getJson('/phpclaw/runs')->assertNotFound();

        $events = array_filter(
            $this->app->make(Schedule::class)->events(),
            static fn ($event): bool => str_contains((string) $event->command, 'phpclaw:runs'),
        );
        self::assertSame([], $events);
    }
}

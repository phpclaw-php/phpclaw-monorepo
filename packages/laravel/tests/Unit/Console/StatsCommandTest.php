<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit\Console;

use Orchestra\Testbench\TestCase;
use PhpClaw\Laravel\PhpClawServiceProvider;
use PhpClaw\Skills\SkillRegistry;

final class StatsCommandTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    protected function setUp(): void
    {
        SkillRegistry::reset();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        SkillRegistry::reset();
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('phpclaw.api_key', 'test-key');
        $app['config']->set('phpclaw.memory_driver', 'array');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    public function test_command_is_registered(): void
    {
        $this->artisan('phpclaw:stats')->assertSuccessful();
    }

    public function test_non_db_driver_prints_notice_not_error(): void
    {
        $this->app['config']->set('phpclaw.memory_driver', 'file');

        $this->artisan('phpclaw:stats')
            ->assertSuccessful()
            ->expectsOutputToContain('database memory driver');
    }

    public function test_non_db_driver_shows_current_driver(): void
    {
        $this->app['config']->set('phpclaw.memory_driver', 'cache');

        $this->artisan('phpclaw:stats')
            ->assertSuccessful()
            ->expectsOutputToContain('cache');
    }

    public function test_eloquent_driver_outputs_stats_header(): void
    {
        $this->app['config']->set('phpclaw.memory_driver', 'eloquent');

        $this->artisan('phpclaw:stats')
            ->assertSuccessful()
            ->expectsOutputToContain('Conversations:')
            ->expectsOutputToContain('Messages:')
            ->expectsOutputToContain('Active 24h:');
    }

    public function test_database_driver_outputs_stats_header(): void
    {
        $this->app['config']->set('phpclaw.memory_driver', 'database');

        $this->artisan('phpclaw:stats')
            ->assertSuccessful()
            ->expectsOutputToContain('Conversations:')
            ->expectsOutputToContain('Messages:')
            ->expectsOutputToContain('Active 24h:');
    }

    public function test_database_kv_and_conversation_drivers_are_accepted(): void
    {
        foreach (['database_kv', 'database_conversation'] as $driver) {
            $this->app['config']->set('phpclaw.memory_driver', $driver);

            $this->artisan('phpclaw:stats')
                ->assertSuccessful()
                ->expectsOutputToContain('Conversations:');
        }
    }

    public function test_eloquent_driver_gracefully_handles_missing_tables(): void
    {
        $this->app['config']->set('phpclaw.memory_driver', 'eloquent');

        $this->artisan('phpclaw:stats')
            ->assertSuccessful()
            ->expectsOutputToContain('Conversations:  0');
    }

    public function test_array_driver_prints_notice(): void
    {
        $this->app['config']->set('phpclaw.memory_driver', 'array');

        $this->artisan('phpclaw:stats')
            ->assertSuccessful()
            ->expectsOutputToContain('database memory driver');
    }
}

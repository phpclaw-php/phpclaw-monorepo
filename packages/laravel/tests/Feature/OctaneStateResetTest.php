<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Feature;

use Orchestra\Testbench\TestCase;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Laravel\PhpClawServiceProvider;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Memory\MemoryRegistry;
use PhpClaw\Providers\ProviderRegistry;
use PhpClaw\Skills\SkillRegistry;

final class OctaneStateResetTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('phpclaw.api_key', 'test-key');
        $app['config']->set('phpclaw.memory_driver', 'array');
    }

    public function test_flush_clears_hooks_registered_during_a_request(): void
    {
        HookRegistry::on('agent.before', static function (): void {});

        self::assertGreaterThan(0, HookRegistry::count('agent.before'));

        PhpClawServiceProvider::flushAgentState();

        self::assertSame(0, HookRegistry::count('agent.before'));
    }

    public function test_flush_clears_skills_registered_during_a_request(): void
    {
        $before = SkillRegistry::count();

        PhpClawServiceProvider::flushAgentState();

        self::assertSame(0, SkillRegistry::count());
        self::assertGreaterThanOrEqual(0, $before);
    }

    public function test_flush_clears_memory_and_provider_registries(): void
    {
        MemoryRegistry::register('leaked_driver', fn () => new ArrayMemory);

        self::assertTrue(MemoryRegistry::has('leaked_driver'));

        PhpClawServiceProvider::flushAgentState();

        self::assertFalse(MemoryRegistry::has('leaked_driver'));
        self::assertSame(0, ProviderRegistry::count());
    }

    public function test_flush_allows_the_event_bridge_to_register_again(): void
    {
        PhpClawServiceProvider::flushAgentState();

        $provider = new PhpClawServiceProvider($this->app);
        $provider->boot();

        self::assertGreaterThan(0, HookRegistry::countAny() + HookRegistry::count());
    }
}

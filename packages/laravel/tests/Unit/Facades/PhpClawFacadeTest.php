<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit\Facades;

use Orchestra\Testbench\TestCase;
use PhpClaw\Claw as PhpClaw;
use PhpClaw\Laravel\Facades\PhpClaw as PhpClawFacade;
use PhpClaw\Laravel\PhpClawServiceProvider;

final class PhpClawFacadeTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('phpclaw.api_key', 'test-api-key-for-facade-tests');
        $app['config']->set('phpclaw.memory_driver', 'array');
    }

    public function test_facade_accessor_resolves_the_container_binding(): void
    {
        $accessor = (new \ReflectionMethod(PhpClawFacade::class, 'getFacadeAccessor'));
        $accessor->setAccessible(true);

        self::assertSame(PhpClaw::class, $accessor->invoke(null));
        self::assertSame($this->app->make(PhpClaw::class), PhpClawFacade::getFacadeRoot());
    }

    public function test_facade_is_registered_in_container_as_a_shared_binding(): void
    {
        self::assertTrue($this->app->bound(PhpClaw::class));
        self::assertSame($this->app->make(PhpClaw::class), $this->app->make(PhpClaw::class));
    }

    public function test_facade_and_container_resolve_same_singleton(): void
    {
        $fromFacade = PhpClawFacade::getFacadeRoot();
        $fromContainer = $this->app->make(PhpClaw::class);

        $this->assertSame($fromFacade, $fromContainer);
    }

    public function test_facade_responds_to_public_api_methods(): void
    {
        $instance = PhpClawFacade::getFacadeRoot();

        $this->assertTrue(method_exists($instance, 'send'));
        $this->assertTrue(method_exists($instance, 'stream'));
        $this->assertTrue(method_exists($instance, 'conversation'));
        $this->assertTrue(method_exists($instance, 'sendInConversation'));
    }
}

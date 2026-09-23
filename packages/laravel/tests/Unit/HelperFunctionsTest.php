<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit;

use Orchestra\Testbench\TestCase;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Exceptions\AdapterException;
use PhpClaw\Laravel\PhpClawServiceProvider;

final class HelperFunctionsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
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

    public function test_phpclaw_with_a_null_message_returns_the_container_binding(): void
    {
        $instance = phpclaw(null, fresh: true);

        $this->assertInstanceOf(PhpClawInterface::class, $instance);
        $this->assertSame($this->app->make(PhpClawInterface::class), $instance);
    }

    public function test_phpclaw_resolves_from_container_when_interface_is_bound(): void
    {
        $this->assertTrue(app()->bound(PhpClawInterface::class));

        $instance = phpclaw(null, fresh: true);

        $this->assertInstanceOf(PhpClawInterface::class, $instance);
    }

    public function test_phpclaw_returns_same_instance_on_subsequent_calls(): void
    {
        $first = phpclaw(null, fresh: true);
        $second = phpclaw();

        $this->assertSame($first, $second);
    }

    public function test_phpclaw_fresh_true_returns_new_instance(): void
    {
        $cached = phpclaw();
        $sameCached = phpclaw();
        $rebuilt = phpclaw(null, fresh: true);

        $this->assertSame($cached, $sameCached, 'without fresh the container instance is reused');
        $this->assertNotSame($cached, $rebuilt, 'fresh: true must forget the instance and rebuild');
    }

    public function test_phpclaw_throws_when_the_container_has_no_binding(): void
    {
        $this->app->forgetInstance(PhpClawInterface::class);
        $this->app->offsetUnset(PhpClawInterface::class);

        $this->expectException(AdapterException::class);
        $this->expectExceptionMessage('not registered in the container');

        phpclaw();
    }
}

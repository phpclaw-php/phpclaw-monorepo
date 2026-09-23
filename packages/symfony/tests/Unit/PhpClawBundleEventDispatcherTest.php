<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit;

use PhpClaw\Symfony\PhpClawBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class PhpClawBundleEventDispatcherTest extends TestCase
{
    public function test_resolves_dispatcher_via_public_event_dispatcher_service(): void
    {
        $dispatcher = new EventDispatcher;
        $container = new Container;
        $container->set('event_dispatcher', $dispatcher);

        $this->assertSame($dispatcher, $this->invokeResolver($container));
    }

    public function test_falls_back_to_interface_when_service_id_absent(): void
    {
        $dispatcher = new EventDispatcher;
        $container = new Container;
        $container->set(EventDispatcherInterface::class, $dispatcher);

        $this->assertSame($dispatcher, $this->invokeResolver($container));
    }

    public function test_returns_null_when_no_dispatcher_available(): void
    {
        $this->assertNull($this->invokeResolver(new Container));
    }

    private function invokeResolver(ContainerInterface $container): ?EventDispatcherInterface
    {
        $bundle = new PhpClawBundle;

        $prop = new \ReflectionProperty($bundle, 'container');
        $prop->setValue($bundle, $container);

        $method = new \ReflectionMethod($bundle, 'eventDispatcher');

        return $method->invoke($bundle);
    }
}

<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit\DependencyInjection;

use Doctrine\DBAL\Connection;
use PhpClaw\Symfony\DependencyInjection\RemoveDoctrineServicesPass;
use PhpClaw\Symfony\Memory\DoctrineConversationMemory;
use PhpClaw\Symfony\Memory\DoctrineMemory;
use PhpClaw\Symfony\Memory\DoctrineRouterMemory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class RemoveDoctrineServicesPassTest extends TestCase
{
    private ContainerBuilder $container;

    private RemoveDoctrineServicesPass $pass;

    protected function setUp(): void
    {
        $this->container = new ContainerBuilder;
        $this->pass = new RemoveDoctrineServicesPass;
    }

    public function test_removes_doctrine_service_definitions_when_dbal_connection_absent(): void
    {
        $this->container->setDefinition(DoctrineMemory::class, new Definition(DoctrineMemory::class));
        $this->container->setDefinition(DoctrineConversationMemory::class, new Definition(DoctrineConversationMemory::class));
        $this->container->setDefinition(DoctrineRouterMemory::class, new Definition(DoctrineRouterMemory::class));

        $this->pass->process($this->container);

        $this->assertFalse($this->container->hasDefinition(DoctrineMemory::class));
        $this->assertFalse($this->container->hasDefinition(DoctrineConversationMemory::class));
        $this->assertFalse($this->container->hasDefinition(DoctrineRouterMemory::class));
    }

    public function test_removes_aliases_when_dbal_connection_absent(): void
    {
        $this->container->setAlias(DoctrineMemory::class, 'some.doctrine.memory.alias');

        $this->pass->process($this->container);

        $this->assertFalse($this->container->hasAlias(DoctrineMemory::class));
    }

    public function test_leaves_doctrine_services_when_dbal_connection_present(): void
    {
        $this->container->setDefinition(Connection::class, new Definition(Connection::class));
        $this->container->setDefinition(DoctrineMemory::class, new Definition(DoctrineMemory::class));
        $this->container->setDefinition(DoctrineConversationMemory::class, new Definition(DoctrineConversationMemory::class));
        $this->container->setDefinition(DoctrineRouterMemory::class, new Definition(DoctrineRouterMemory::class));

        $this->pass->process($this->container);

        $this->assertTrue($this->container->hasDefinition(DoctrineMemory::class));
        $this->assertTrue($this->container->hasDefinition(DoctrineConversationMemory::class));
        $this->assertTrue($this->container->hasDefinition(DoctrineRouterMemory::class));
    }

    public function test_no_error_when_doctrine_service_definitions_are_already_absent(): void
    {
        $this->assertFalse($this->container->hasDefinition(DoctrineMemory::class));
        $this->assertFalse($this->container->hasDefinition(DoctrineConversationMemory::class));
        $this->assertFalse($this->container->hasDefinition(DoctrineRouterMemory::class));

        $this->pass->process($this->container);

        $this->assertFalse($this->container->hasDefinition(DoctrineMemory::class));
        $this->assertFalse($this->container->hasDefinition(DoctrineConversationMemory::class));
        $this->assertFalse($this->container->hasDefinition(DoctrineRouterMemory::class));
    }

    public function test_leaves_unrelated_definitions_intact_when_dbal_absent(): void
    {
        $this->container->setDefinition('some.unrelated.service', new Definition(\stdClass::class));
        $this->container->setDefinition(DoctrineMemory::class, new Definition(DoctrineMemory::class));

        $this->pass->process($this->container);

        $this->assertTrue($this->container->hasDefinition('some.unrelated.service'));
    }
}

<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\DependencyInjection;

use Doctrine\DBAL\Connection;
use PhpClaw\Symfony\Memory\DoctrineConversationMemory;
use PhpClaw\Symfony\Memory\DoctrineMemory;
use PhpClaw\Symfony\Memory\DoctrineRouterMemory;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Removes Doctrine memory service definitions when the Doctrine DBAL service is not registered in the container.
 */
final class RemoveDoctrineServicesPass implements CompilerPassInterface
{
    /**
     * Removes Doctrine memory drivers from the container when Doctrine\DBAL\Connection service is absent.
     *
     * @param  ContainerBuilder  $container
     * @return void
     */
    public function process(ContainerBuilder $container): void
    {
        if ($container->has(Connection::class)) {
            return;
        }

        foreach ([DoctrineMemory::class, DoctrineConversationMemory::class, DoctrineRouterMemory::class] as $id) {
            if ($container->hasDefinition($id)) {
                $container->removeDefinition($id);
            }

            if ($container->hasAlias($id)) {
                $container->removeAlias($id);
            }
        }
    }
}

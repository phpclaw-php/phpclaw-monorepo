<?php

declare(strict_types=1);

namespace Drupal\phpclaw;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 * Compile-time service provider that wires tagged phpClaw extension services.
 */
final class PhpclawServiceProvider extends ServiceProviderBase
{
    /**
     * Alter the container at compile time to inject tagged service IDs.
     *
     * @param  ContainerBuilder  $container  The container builder being compiled.
     * @return void
     */
    public function alter(ContainerBuilder $container): void
    {
        if (! $container->hasDefinition('phpclaw.registrar')) {
            return;
        }

        $tags = [
            'phpclaw.tool',
            'phpclaw.provider',
            'phpclaw.memory_driver',
            'phpclaw.guard',
            'phpclaw.skill',
        ];

        foreach ($tags as $tag) {
            $param = 'phpclaw.tagged_services.'.str_replace('.', '_', $tag);
            $ids = array_keys($container->findTaggedServiceIds($tag));
            $container->setParameter($param, $ids);
        }

        $hookListeners = [];
        foreach ($container->findTaggedServiceIds('phpclaw.hook_listener') as $id => $tagList) {
            foreach ($tagList as $tagAttrs) {
                $event = (string) ($tagAttrs['event'] ?? '');
                if ($event === '') {
                    continue;
                }
                $priority = (int) ($tagAttrs['priority'] ?? 10);
                $hookListeners[] = [
                    'service_id' => $id,
                    'event' => $event,
                    'priority' => $priority,
                ];
            }
        }
        $container->setParameter('phpclaw.tagged_services.hook_listeners', $hookListeners);
    }
}

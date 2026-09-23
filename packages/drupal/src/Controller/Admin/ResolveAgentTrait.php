<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Controller\Admin;

use PhpClaw\Contracts\ClawInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a shared agent-resolution helper for phpClaw admin controllers.
 */
trait ResolveAgentTrait
{
    /**
     * Resolve an agent service without letting a misconfigured provider crash controller construction.
     *
     * @param  ContainerInterface  $container  The Drupal service container.
     * @param  string  $serviceId  The agent service ID to resolve.
     * @return ?ClawInterface
     */
    private static function resolveAgent(ContainerInterface $container, string $serviceId): ?ClawInterface
    {
        if (! $container->has($serviceId)) {
            return null;
        }

        try {
            return $container->get($serviceId);
        } catch (\Throwable) {
            return null;
        }
    }
}

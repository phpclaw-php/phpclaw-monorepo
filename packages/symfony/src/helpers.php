<?php

declare(strict_types=1);

use PhpClaw\Claw as PhpClaw;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Symfony\PhpClawBundle;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\KernelInterface;

if (! function_exists('phpclaw')) {
    /**
     * Resolves the phpClaw engine from the booted bundle container (optionally sending a message) via a static non-DI convenience cache that keeps re-checking the container while it has only the unconfigured fallback; DI-wired paths should inject ClawInterface instead.
     *
     * @param  ?string  $message
     * @param  bool  $fresh
     * @return mixed
     */
    function phpclaw(?string $message = null, bool $fresh = false): mixed
    {
        static $instance = null;
        static $fromContainer = false;

        if ($fresh || $instance === null || ! $fromContainer) {
            $container = PhpClawBundle::bootedContainer();

            if ($container === null) {
                global $kernel;

                if (isset($kernel) && $kernel instanceof KernelInterface) {
                    $container = $kernel->getContainer();
                }
            }

            $resolved = $container !== null ? phpclaw_resolve_from_container($container) : null;

            if ($resolved !== null) {
                $instance = $resolved;
                $fromContainer = true;
            } elseif ($fresh || $instance === null) {
                $instance = PhpClaw::builder()->build();
                $fromContainer = false;
            }
        }

        if ($message !== null) {
            return $instance->send($message);
        }

        return $instance;
    }
}

if (! function_exists('phpclaw_resolve_from_container')) {
    /**
     * Resolve the phpClaw engine from a container, preferring the interface alias.
     *
     * @param  ContainerInterface  $container
     * @return mixed
     */
    function phpclaw_resolve_from_container(ContainerInterface $container): mixed
    {
        if ($container->has(PhpClawInterface::class)) {
            return $container->get(PhpClawInterface::class);
        }

        if ($container->has(PhpClaw::class)) {
            return $container->get(PhpClaw::class);
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Component\Administrator\Extension;

use Joomla\CMS\Extension\BootableExtensionInterface;
use Joomla\CMS\Extension\MVCComponent;
use Psr\Container\ContainerInterface;

/**
 * Component bootstrap class for com_phpclaw.
 */
final class PhpclawComponent extends MVCComponent implements BootableExtensionInterface
{
    /**
     * Boot hook fired by Joomla when the component is dispatched.
     *
     * @param  ContainerInterface  $container  The application DI container.
     * @return void
     */
    public function boot(ContainerInterface $container): void {}
}

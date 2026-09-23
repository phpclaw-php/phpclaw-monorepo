<?php

declare(strict_types=1);

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use PhpClaw\Joomla\WebServices\Extension\PhpClaw;

/**
 * Joomla 4/5/6 service provider for the phpClaw web services plugin.
 */
return new class implements ServiceProviderInterface
{
    /**
     * Register the plugin with the Joomla DI container.
     *
     * @param  Container  $container  The Joomla DI container.
     * @return void
     */
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            static function (Container $container): PluginInterface {
                $plugin = new PhpClaw(
                    $container->get(DispatcherInterface::class),
                    (array) PluginHelper::getPlugin('webservices', 'phpclaw')
                );
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};

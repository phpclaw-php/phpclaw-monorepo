<?php

declare(strict_types=1);

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use PhpClaw\Joomla\Extension\PhpClawPlugin;

/**
 * Joomla 4/5/6 IoC service provider for the phpClaw system plugin.
 */
return new class implements ServiceProviderInterface
{
    /**
     * Register the phpClaw system plugin with the Joomla DI container.
     *
     * @param  Container  $container  The Joomla DI container.
     * @return void
     */
    public function register(Container $container): void
    {
        $candidates = [
            JPATH_ADMINISTRATOR.'/components/com_phpclaw/vendor/autoload.php',
            dirname(__DIR__).'/vendor/autoload.php',
        ];
        foreach ($candidates as $vendorAutoload) {
            if (is_file($vendorAutoload)) {
                require_once $vendorAutoload;
                break;
            }
        }

        $container->set(
            PluginInterface::class,
            static function (Container $c): PluginInterface {
                $dispatcher = $c->get(DispatcherInterface::class);
                $plugin = new PhpClawPlugin($dispatcher, []);
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};

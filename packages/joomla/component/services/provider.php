<?php

declare(strict_types=1);

use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Extension\Service\Provider\ComponentDispatcherFactory;
use Joomla\CMS\Extension\Service\Provider\MVCFactory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use PhpClaw\Joomla\Component\Administrator\Extension\PhpclawComponent;

/**
 * Joomla 4/5/6 service provider for the phpClaw admin component.
 */
return new class implements ServiceProviderInterface
{
    /**
     * Register the component's MVC factory, dispatcher factory, and component service.
     *
     * @param  Container  $container  The Joomla DI container.
     * @return void
     */
    public function register(Container $container): void
    {
        $vendorAutoload = JPATH_ADMINISTRATOR.'/components/com_phpclaw/vendor/autoload.php';

        if (file_exists($vendorAutoload)) {
            require_once $vendorAutoload;
        }

        $container->registerServiceProvider(new MVCFactory('\\PhpClaw\\Joomla\\Component'));
        $container->registerServiceProvider(new ComponentDispatcherFactory('\\PhpClaw\\Joomla\\Component'));

        $container->set(
            ComponentInterface::class,
            static function (Container $container): ComponentInterface {
                $component = new PhpclawComponent(
                    $container->get(ComponentDispatcherFactoryInterface::class)
                );
                $component->setMVCFactory($container->get(MVCFactoryInterface::class));

                return $component;
            }
        );
    }
};

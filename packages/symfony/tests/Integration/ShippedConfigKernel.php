<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Integration;

use PhpClaw\Symfony\PhpClawBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

final class ShippedConfigKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle,
            new PhpClawBundle,
        ];
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 2);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/phpclaw_symfony_shipped_config/cache/'.$this->environment.'_'.$this->fingerprint();
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/phpclaw_symfony_shipped_config/logs';
    }

    protected function getContainerClass(): string
    {
        return 'ShippedConfigKernelContainer'.ucfirst($this->environment).$this->fingerprint();
    }

    private function fingerprint(): string
    {
        $config = $this->getProjectDir().'/config/phpclaw.yaml';
        $seed = is_file($config) ? (string) md5_file($config) : 'missing';

        foreach ($_ENV as $name => $value) {
            if (str_starts_with((string) $name, 'PHPCLAW_')) {
                $seed .= '|'.$name.'='.(is_scalar($value) ? (string) $value : '');
            }
        }

        return substr(md5($seed), 0, 12);
    }

    private function configureContainer(ContainerConfigurator $container, LoaderInterface $_loader, ContainerBuilder $_builder): void
    {
        $container->extension('framework', [
            'secret' => 'shipped-config-test-secret',
            'test' => true,
            'http_method_override' => false,
        ]);

        $container->import($this->getProjectDir().'/config/phpclaw.yaml');
    }
}

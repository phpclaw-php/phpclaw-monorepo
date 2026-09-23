<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests;

use PhpClaw\Symfony\PhpClawBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle,
            new PhpClawBundle,
        ];
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/phpclaw_symfony_test/cache/'.$this->environment.'_'.$this->vendorFingerprint();
    }

    private function vendorFingerprint(): string
    {
        $installed = $this->getProjectDir().'/vendor/composer/installed.php';

        return is_file($installed) ? substr(md5_file($installed), 0, 12) : 'no-vendor';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/phpclaw_symfony_test/logs';
    }

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        $container->extension('framework', [
            'secret' => 'test-secret',
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
        ]);

        $container->extension('phpclaw', [
            'api_key' => getenv('ANTHROPIC_API_KEY') ?: 'sk-feature-test-key',
            'store_messages' => false,
            'max_iterations' => 5,
            'memory_driver' => 'file',
            'api' => ['enabled' => false],
        ]);
    }
}

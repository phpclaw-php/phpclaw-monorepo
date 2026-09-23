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

final class IntegrationKernel extends Kernel
{
    use MicroKernelTrait;

    private static array $phpClawConfig = [];

    public static function configure(array $phpClawConfig): void
    {
        self::$phpClawConfig = $phpClawConfig;
    }

    public function __construct(string $environment, bool $debug)
    {
        parent::__construct($environment, false);
    }

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle,
            new PhpClawBundle,
        ];
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/phpclaw_symfony_integration/cache/'.$this->environment.'_'.$this->configFingerprint().'_'.$this->vendorFingerprint();
    }

    protected function getContainerClass(): string
    {
        return 'IntegrationKernelContainer'.$this->configFingerprint();
    }

    private function configFingerprint(): string
    {
        return substr(md5(serialize(self::$phpClawConfig)), 0, 12);
    }

    private function vendorFingerprint(): string
    {
        $installed = $this->getProjectDir().'/vendor/composer/installed.php';

        return is_file($installed) ? substr(md5_file($installed), 0, 12) : 'no-vendor';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/phpclaw_symfony_integration/logs';
    }

    private function configureContainer(ContainerConfigurator $container, LoaderInterface $_loader, ContainerBuilder $_builder): void
    {
        $container->extension('framework', [
            'secret' => 'integration-test-secret',
            'test' => true,
            'http_method_override' => false,
        ]);

        $phpClawConfig = array_merge([
            'api_key' => 'sk-integration-test-key',
            'provider' => 'openai',
            'store_messages' => false,
            'max_iterations' => 3,
            'memory_driver' => 'file',
            'api' => ['enabled' => false],
        ], self::$phpClawConfig);

        $container->extension('phpclaw', $phpClawConfig);
    }
}

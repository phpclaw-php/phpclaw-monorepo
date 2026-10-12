<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Unit\Fixtures;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\DependencyInjection\ContainerInterface as DrupalContainerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\State\StateInterface;
use PhpClaw\Drupal\PhpClawRegistrar;
use PhpClaw\Drupal\Service\DrupalAgentContext;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Memory\MemoryRegistry;
use PhpClaw\Providers\ProviderRegistry;

trait BuildsAgentContext
{
    private array $stateValues = [];

    private function agentContext(array $settings = [], ?ScriptedProvider $provider = null, ?MemoryInterface $memory = null): DrupalAgentContext
    {
        $config = $this->createMock(ImmutableConfig::class);
        $config->method('get')->willReturnCallback(static fn (string $key) => ($settings + ['provider' => 'scripted', 'model' => 'scripted-model', 'store_messages' => true])[$key] ?? null);
        $configFactory = $this->createMock(ConfigFactoryInterface::class);
        $configFactory->method('get')->willReturn($config);

        $state = $this->createMock(StateInterface::class);
        $state->method('get')->willReturnCallback(fn (string $key, $default = null) => $this->stateValues[$key] ?? $default);
        $state->method('set')->willReturnCallback(function (string $key, $value): void {
            $this->stateValues[$key] = $value;
        });

        $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
        $moduleHandler->method('getModuleList')->willReturn([]);
        $moduleExtensionList = $this->createMock(ModuleExtensionList::class);
        $moduleExtensionList->method('getAllInstalledInfo')->willReturn([]);
        $queueWorkerManager = $this->createMock(QueueWorkerManagerInterface::class);
        $queueWorkerManager->method('getDefinitions')->willReturn([]);
        $time = $this->createMock(TimeInterface::class);
        $time->method('getRequestTime')->willReturn(time());
        $database = $this->createMock(Connection::class);

        $container = $this->createMock(DrupalContainerInterface::class);
        $container->method('hasParameter')->willReturn(false);
        $container->method('getParameter')->willReturn([]);
        $registrar = new PhpClawRegistrar($configFactory, $database, $this->createMock(CacheBackendInterface::class), $time, $container);
        $registrar->boot();

        $memory ??= new ArrayMemory;
        MemoryRegistry::register('database', static fn () => $memory);
        $scripted = $provider ?? new ScriptedProvider;
        ProviderRegistry::register('scripted', static fn () => $scripted);

        return new DrupalAgentContext(
            $configFactory,
            $registrar,
            $database,
            $this->createMock(EntityTypeManagerInterface::class),
            $moduleHandler,
            $moduleExtensionList,
            $state,
            $this->createMock(QueueFactory::class),
            $queueWorkerManager,
            $time,
        );
    }
}

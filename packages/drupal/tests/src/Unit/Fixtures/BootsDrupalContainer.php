<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Unit\Fixtures;

use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

trait BootsDrupalContainer
{
    protected function bootDrupalContainerWithUser(int $uid = 1, bool $allPermissions = true, array $services = []): void
    {
        $account = $this->createMock(AccountInterface::class);
        $account->method('id')->willReturn($uid);
        $account->method('hasPermission')->willReturn($allPermissions);

        $services['current_user'] = $account;

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(
            static fn (string $id): bool => isset($services[$id]),
        );
        $container->method('get')->willReturnCallback(
            static fn (string $id): object => $services[$id]
                ?? throw new \RuntimeException("Service '{$id}' not mocked."),
        );

        \Drupal::setContainer($container);
    }

    protected function tearDown(): void
    {
        if (class_exists(\Drupal::class)) {
            \Drupal::unsetContainer();
        }

        parent::tearDown();
    }
}

<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\State\StateInterface;
use PhpClaw\Drupal\PhpClawRegistrar;

/**
 * Immutable value object carrying all dependencies required by PhpClawServiceFactory.
 */
final class DrupalAgentContext
{
    /**
     * Bundle every Drupal dependency the service factory needs into one object.
     *
     * @param  ConfigFactoryInterface  $configFactory  Drupal config factory.
     * @param  PhpClawRegistrar  $registrar  phpClaw registry bootstrapper.
     * @param  Connection  $database  Drupal database connection.
     * @param  EntityTypeManagerInterface  $entityTypeManager  Drupal entity type manager.
     * @param  ModuleHandlerInterface  $moduleHandler  Drupal module handler.
     * @param  ModuleExtensionList  $moduleExtensionList  Drupal module extension list.
     * @param  StateInterface  $state  Drupal state service.
     * @param  QueueFactory  $queueFactory  Drupal queue factory.
     * @param  QueueWorkerManagerInterface  $queueWorkerManager  Drupal queue worker plugin manager.
     * @param  TimeInterface  $time  Drupal time service.
     * @return void
     */
    public function __construct(
        public readonly ConfigFactoryInterface $configFactory,
        public readonly PhpClawRegistrar $registrar,
        public readonly Connection $database,
        public readonly EntityTypeManagerInterface $entityTypeManager,
        public readonly ModuleHandlerInterface $moduleHandler,
        public readonly ModuleExtensionList $moduleExtensionList,
        public readonly StateInterface $state,
        public readonly QueueFactory $queueFactory,
        public readonly QueueWorkerManagerInterface $queueWorkerManager,
        public readonly TimeInterface $time,
    ) {}
}

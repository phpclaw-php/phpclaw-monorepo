<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\EventSubscriber;

use PhpClaw\Symfony\Console\ConsoleContext;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Marks the console context for an interactive command, never for a queue worker.
 */
final class ConsoleContextSubscriber implements EventSubscriberInterface
{
    public const WORKER_COMMANDS = [
        'messenger:consume',
        'messenger:failed:retry',
        'messenger:stats',
    ];

    /**
     * Bind the context this subscriber marks and the host app's extra worker command names.
     *
     * @param  ConsoleContext  $context  The context marked when an interactive command starts.
     * @param  string[]  $workerCommands  Additional command names to treat as workers.
     * @return void
     */
    public function __construct(
        private readonly ConsoleContext $context,
        private readonly array $workerCommands = [],
    ) {}

    /**
     * Subscribe to the console command event.
     *
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [ConsoleEvents::COMMAND => 'onCommand'];
    }

    /**
     * Mark the context unless the command is a queue worker, which must never inherit the
     * console exemption.
     *
     * @param  ConsoleCommandEvent  $event  The console command about to run.
     * @return void
     */
    public function onCommand(ConsoleCommandEvent $event): void
    {
        if ($this->isWorker($event->getCommand()?->getName())) {
            return;
        }

        $this->context->markConsole();
    }

    /**
     * Whether a command name names a queue worker entrypoint.
     *
     * @param  string|null  $name  The command name, or null when the command is unresolved.
     * @return bool
     */
    private function isWorker(?string $name): bool
    {
        if ($name === null) {
            return true;
        }

        $workers = array_merge(self::WORKER_COMMANDS, $this->workerCommands);

        return in_array($name, $workers, true);
    }
}

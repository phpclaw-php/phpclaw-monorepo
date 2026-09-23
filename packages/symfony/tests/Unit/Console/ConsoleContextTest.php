<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit\Console;

use PhpClaw\Symfony\Console\ConsoleContext;
use PhpClaw\Symfony\EventSubscriber\ConsoleContextSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

final class ConsoleContextTest extends TestCase
{
    public function test_defaults_false_marks_true_and_resets(): void
    {
        $context = new ConsoleContext;

        self::assertFalse($context->isConsole());

        $context->markConsole();
        self::assertTrue($context->isConsole());

        $context->reset();
        self::assertFalse($context->isConsole());
    }

    public function test_the_subscriber_listens_to_the_console_command_event(): void
    {
        self::assertSame(
            [ConsoleEvents::COMMAND => 'onCommand'],
            ConsoleContextSubscriber::getSubscribedEvents(),
        );
    }

    public function test_an_interactive_command_marks_console(): void
    {
        $context = new ConsoleContext;

        $this->fire($context, 'phpclaw');

        self::assertTrue($context->isConsole());
    }

    public function test_every_worker_command_leaves_the_context_unmarked(): void
    {
        self::assertSame(
            ['messenger:consume', 'messenger:failed:retry', 'messenger:stats'],
            ConsoleContextSubscriber::WORKER_COMMANDS,
        );

        foreach (ConsoleContextSubscriber::WORKER_COMMANDS as $name) {
            $context = new ConsoleContext;

            $this->fire($context, $name);

            self::assertFalse($context->isConsole(), $name.' must not mark console');
        }
    }

    public function test_a_host_app_worker_command_leaves_the_context_unmarked(): void
    {
        $context = new ConsoleContext;

        $this->fire($context, 'app:consume-agent-jobs', ['app:consume-agent-jobs']);

        self::assertFalse($context->isConsole());
    }

    public function test_an_unresolved_command_leaves_the_context_unmarked(): void
    {
        $context = new ConsoleContext;

        (new ConsoleContextSubscriber($context))->onCommand(
            new ConsoleCommandEvent(null, new ArrayInput([]), new NullOutput),
        );

        self::assertFalse($context->isConsole());
    }

    private function fire(ConsoleContext $context, string $name, array $workerCommands = []): void
    {
        (new ConsoleContextSubscriber($context, $workerCommands))->onCommand(
            new ConsoleCommandEvent(new Command($name), new ArrayInput([]), new NullOutput),
        );
    }
}

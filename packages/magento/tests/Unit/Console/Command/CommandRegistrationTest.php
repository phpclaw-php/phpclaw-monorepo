<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Console\Command;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;

final class CommandRegistrationTest extends TestCase
{
    private function registeredCommands(): array
    {
        $dom = new \DOMDocument;
        $dom->load(dirname(__DIR__, 4).'/etc/di.xml');
        $items = (new \DOMXPath($dom))->query('//type[@name="Magento\\Framework\\Console\\CommandListInterface"]//argument[@name="commands"]/item');

        $commands = [];
        foreach ($items as $item) {
            $commands[] = $this->build(trim($item->textContent));
        }

        return $commands;
    }

    private function build(string $class): Command
    {
        $arguments = [];
        foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            if ($parameter->isOptional() || ! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                break;
            }
            $dependency = new ReflectionClass($type->getName());
            $arguments[] = $dependency->isFinal() ? $dependency->newInstance() : $this->createMock($type->getName());
        }

        return new $class(...$arguments);
    }

    public function test_every_registered_command_builds_with_its_name(): void
    {
        $names = array_map(static fn (Command $command): string => (string) $command->getName(), $this->registeredCommands());

        self::assertSame(['phpclaw:run', 'phpclaw:chat', 'phpclaw:mcp-server'], $names);
    }

    public function test_every_registered_command_loads_into_a_console_application(): void
    {
        $application = new Application;

        foreach ($this->registeredCommands() as $command) {
            method_exists($application, 'addCommand') ? $application->addCommand($command) : $application->add($command);
            $help = $application->find((string) $command->getName())->getSynopsis();

            self::assertStringStartsWith((string) $command->getName(), $help);
        }
    }

    public function test_the_chat_and_run_commands_take_the_resume_options(): void
    {
        $commands = [];
        foreach ($this->registeredCommands() as $command) {
            $commands[(string) $command->getName()] = $command->getDefinition();
        }

        foreach (['conv-id', 'continue'] as $option) {
            self::assertTrue($commands['phpclaw:chat']->hasOption($option), $option);
            self::assertTrue($commands['phpclaw:run']->hasOption($option), $option);
        }
        self::assertTrue($commands['phpclaw:run']->hasOption('trace'));
        self::assertTrue($commands['phpclaw:run']->hasOption('stream'));
    }
}

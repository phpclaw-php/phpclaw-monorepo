<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Unit\Commands;

require_once __DIR__.'/../Fixtures/drush-commands-stub.php';

use PhpClaw\Drupal\Commands\Base\PhpClawDrushCommands;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Yaml\Yaml;

final class DrushSurfaceParityTest extends TestCase
{
    private const SHIPPED_COMMANDS = ['phpclaw:chat', 'phpclaw:mcp-server', 'phpclaw:run'];

    private const SHIPPED_ALIASES = ['pc', 'phpclaw-mcp'];

    private function services(): array
    {
        $parsed = Yaml::parseFile(dirname(__DIR__, 4).'/drush.services.yml');

        return array_filter(
            (array) ($parsed['services'] ?? []),
            static fn (array $definition): bool => in_array('drush.command', array_column($definition['tags'] ?? [], 'name'), true),
        );
    }

    private function commandAttributes(): array
    {
        $found = [];

        foreach ($this->services() as $definition) {
            foreach ((new ReflectionClass($definition['class']))->getMethods() as $method) {
                foreach ($method->getAttributes('Drush\Attributes\Command') as $attribute) {
                    $found[] = $attribute->getArguments();
                }
            }
        }

        return $found;
    }

    public function test_drush_services_wires_exactly_the_three_shipped_command_classes(): void
    {
        self::assertSame(
            [
                'phpclaw.commands' => 'PhpClaw\Drupal\Commands\PhpClawCommands',
                'phpclaw.chat_commands' => 'PhpClaw\Drupal\Commands\PhpClawChatCommands',
                'phpclaw.mcp_commands' => 'PhpClaw\Drupal\Commands\PhpClawMcpServerCommands',
            ],
            array_map(static fn (array $definition): string => $definition['class'], $this->services()),
        );
    }

    public function test_the_declared_command_set_is_exactly_the_three_shipped_commands(): void
    {
        $names = array_map(static fn (array $arguments): string => $arguments['name'], $this->commandAttributes());
        sort($names);

        self::assertSame(self::SHIPPED_COMMANDS, $names);
    }

    public function test_the_declared_alias_set_is_exactly_the_two_shipped_aliases(): void
    {
        $aliases = array_merge(...array_map(static fn (array $arguments): array => $arguments['aliases'] ?? [], $this->commandAttributes()));
        sort($aliases);

        self::assertSame(self::SHIPPED_ALIASES, $aliases);
    }

    public function test_every_drush_command_class_in_the_package_is_wired_into_drush(): void
    {
        $commandClasses = [];

        foreach (glob(dirname(__DIR__, 4).'/src/Commands/*.php') ?: [] as $file) {
            $class = 'PhpClaw\\Drupal\\Commands\\'.basename($file, '.php');

            if (is_subclass_of($class, PhpClawDrushCommands::class)) {
                $commandClasses[] = $class;
            }
        }

        $wired = array_values(array_map(static fn (array $definition): string => $definition['class'], $this->services()));
        sort($commandClasses);
        sort($wired);

        self::assertNotEmpty($commandClasses);
        self::assertSame($commandClasses, $wired, 'a Drush command class under src/Commands is not tagged drush.command');
    }

    public function test_each_command_class_declares_exactly_one_drush_command(): void
    {
        foreach ($this->services() as $id => $definition) {
            $count = 0;

            foreach ((new ReflectionClass($definition['class']))->getMethods() as $method) {
                $count += count($method->getAttributes('Drush\Attributes\Command'));
            }

            self::assertSame(1, $count, $id.' must declare exactly one Drush command');
        }
    }

    public function test_every_command_receives_the_agent_context(): void
    {
        foreach ($this->services() as $id => $definition) {
            self::assertSame(['@phpclaw.agent_context'], $definition['arguments'] ?? [], $id);
        }
    }
}

<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Unit\Commands;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class DrushSurfaceParityTest extends TestCase
{
    private const SHIPPED_COMMANDS = ['phpclaw:mcp-server', 'phpclaw:run'];

    private const SHIPPED_ALIASES = ['pc', 'phpclaw-mcp'];

    private function packageRoot(): string
    {
        return dirname(__DIR__, 4);
    }

    private function taggedCommandClasses(): array
    {
        $path = $this->packageRoot().'/drush.services.yml';
        self::assertFileExists($path);

        $parsed = Yaml::parse((string) file_get_contents($path));
        self::assertIsArray($parsed);

        $classes = [];

        foreach ($parsed['services'] ?? [] as $id => $definition) {
            $tags = array_column($definition['tags'] ?? [], 'name');

            if (in_array('drush.command', $tags, true)) {
                $classes[(string) $id] = (string) $definition['class'];
            }
        }

        return $classes;
    }

    private function sourceOf(string $class): string
    {
        $relative = str_replace('PhpClaw\\Drupal\\', '', $class);
        $path = $this->packageRoot().'/src/'.str_replace('\\', '/', $relative).'.php';
        self::assertFileExists($path, $class.' is wired in drush.services.yml but has no source file');

        return (string) file_get_contents($path);
    }

    private function attributeValues(string $attribute, string $key): array
    {
        $found = [];

        foreach ($this->taggedCommandClasses() as $class) {
            preg_match_all(
                '/#\[CLI\\\\'.$attribute.'\((.*?)\)\]/s',
                $this->sourceOf($class),
                $blocks,
            );

            foreach ($blocks[1] as $block) {
                if ($key === 'aliases') {
                    if (preg_match("/aliases:\s*\[(.*?)\]/s", $block, $m) === 1) {
                        preg_match_all("/'([^']+)'/", $m[1], $items);
                        $found = array_merge($found, $items[1]);
                    }

                    continue;
                }

                if (preg_match("/{$key}:\s*'([^']+)'/", $block, $m) === 1) {
                    $found[] = $m[1];
                }
            }
        }

        sort($found);

        return $found;
    }

    public function test_drush_services_wires_exactly_the_two_shipped_command_classes(): void
    {
        self::assertSame(
            [
                'phpclaw.commands' => 'PhpClaw\Drupal\Commands\PhpClawCommands',
                'phpclaw.mcp_commands' => 'PhpClaw\Drupal\Commands\PhpClawMcpServerCommands',
            ],
            $this->taggedCommandClasses(),
        );
    }

    public function test_the_declared_command_set_is_exactly_the_two_shipped_commands(): void
    {
        self::assertSame(self::SHIPPED_COMMANDS, $this->attributeValues('Command', 'name'));
    }

    public function test_the_declared_alias_set_is_exactly_the_two_shipped_aliases(): void
    {
        self::assertSame(self::SHIPPED_ALIASES, $this->attributeValues('Command', 'aliases'));
    }

    public function test_every_command_class_in_the_package_is_wired_into_drush(): void
    {
        $onDisk = glob($this->packageRoot().'/src/Commands/*.php');
        self::assertIsArray($onDisk);
        self::assertNotEmpty($onDisk);

        $wired = array_map(
            static fn (string $class): string => basename(str_replace('\\', '/', $class)).'.php',
            array_values($this->taggedCommandClasses()),
        );
        sort($wired);

        $files = array_map('basename', $onDisk);
        sort($files);

        self::assertSame($files, $wired, 'a class under src/Commands is not tagged drush.command');
    }

    public function test_each_command_class_declares_exactly_one_drush_command(): void
    {
        foreach ($this->taggedCommandClasses() as $id => $class) {
            self::assertSame(
                1,
                preg_match_all('/#\[CLI\\\\Command\(/', $this->sourceOf($class)),
                $id.' must declare exactly one Drush command',
            );
        }
    }

    public function test_both_commands_receive_the_agent_context(): void
    {
        $parsed = Yaml::parse((string) file_get_contents($this->packageRoot().'/drush.services.yml'));

        self::assertSame(['@phpclaw.agent_context'], $parsed['services']['phpclaw.commands']['arguments']);
        self::assertSame(['@phpclaw.agent_context'], $parsed['services']['phpclaw.mcp_commands']['arguments']);
    }
}

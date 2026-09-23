<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\Tools;

use PhpClaw\WordPress\Engine\EngineFactory;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class FlattenedCapabilityTest extends TestCase
{
    private const EXPECTED_CAPABILITY = 'phpclaw_use_chat';

    public function test_every_tool_declares_the_same_capability(): void
    {
        $values = array_values(array_unique(array_values($this->declaredCapabilities())));

        self::assertCount(
            1,
            $values,
            'tools must gate on a single capability, found: '.implode(', ', $values),
        );
    }

    public function test_the_declared_capability_is_the_chat_capability(): void
    {
        $declared = $this->declaredCapabilities();

        self::assertNotEmpty($declared, 'no tool declared a capability, the scan is broken');

        foreach ($declared as $file => $capability) {
            self::assertSame(self::EXPECTED_CAPABILITY, $capability, $file.' must gate on the chat capability');
        }
    }

    public function test_tool_building_takes_no_caller(): void
    {
        $parameters = (new ReflectionMethod(EngineFactory::class, 'buildTools'))->getParameters();

        $names = array_map(static fn ($parameter): string => $parameter->getName(), $parameters);

        foreach (['user', 'currentUser', 'userId', 'capability', 'capabilities', 'caller'] as $forbidden) {
            self::assertNotContains(
                $forbidden,
                $names,
                'buildTools must not receive a caller, or the advertised tool list becomes per user',
            );
        }
    }

    private function declaredCapabilities(): array
    {
        $found = [];

        foreach ($this->toolFiles() as $file) {
            $source = (string) file_get_contents($file);

            if (preg_match("/REQUIRED_CAPABILITY = '([^']+)'/", $source, $matches) === 1) {
                $found[basename($file)] = $matches[1];
            }
        }

        return $found;
    }

    private function toolFiles(): array
    {
        $files = [];

        foreach ([__DIR__.'/../../../src/Tools', __DIR__.'/../../../src/WooCommerce/Tools'] as $root) {
            foreach ((array) glob($root.'/*.php') as $file) {
                $files[] = (string) $file;
            }
        }

        return $files;
    }
}

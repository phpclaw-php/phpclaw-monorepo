<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit;

use PhpClaw\PrestaShop\Plugin;
use PhpClaw\PrestaShop\Tests\Helpers\FakeTokenDb;
use PHPUnit\Framework\TestCase;

final class PluginConsolePropagationTest extends TestCase
{
    private function consoleFlagOf(object $factory): bool
    {
        $r = new \ReflectionObject($factory);
        $p = $r->getProperty('isConsole');
        $p->setAccessible(true);

        return (bool) $p->getValue($factory);
    }

    private function factoryOf(Plugin $plugin): object
    {
        $r = new \ReflectionObject($plugin);
        $p = $r->getProperty('engineFactory');
        $p->setAccessible(true);

        return $p->getValue($plugin);
    }

    private function consolePlugin(): Plugin
    {
        $r = new \ReflectionClass(Plugin::class);
        $ctor = $r->getConstructor();
        $ctor->setAccessible(true);
        $plugin = $r->newInstanceWithoutConstructor();
        $ctor->invoke($plugin, new FakeTokenDb, 'ps_', 0, false, true);

        return $plugin;
    }

    public function test_a_console_plugin_starts_with_the_console_flag_set(): void
    {
        self::assertTrue($this->consoleFlagOf($this->factoryOf($this->consolePlugin())));
    }

    public function test_saving_settings_keeps_the_console_flag(): void
    {
        $plugin = $this->consolePlugin();
        $plugin->saveSettings(['provider' => 'ollama', 'model' => 'qwen2.5:7b']);

        self::assertTrue(
            $this->consoleFlagOf($this->factoryOf($plugin)),
            'A settings save must not silently demote a console session to a web session.',
        );
    }
}

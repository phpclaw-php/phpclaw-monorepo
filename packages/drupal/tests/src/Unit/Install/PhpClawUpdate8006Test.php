<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Unit\Install;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use PhpClaw\Drupal\Tests\Unit\Fixtures\BootsDrupalContainer;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../../../phpclaw.install';

final class PhpClawUpdate8006Test extends TestCase
{
    use BootsDrupalContainer;

    public function test_it_adds_both_tool_routing_settings_with_their_defaults(): void
    {
        [$saved, $message] = $this->runUpdate(['provider' => 'ollama']);

        self::assertSame(['max_tools_per_turn' => 0, 'always_include_tools' => []], $saved);
        self::assertSame('Added phpclaw.settings: max_tools_per_turn, always_include_tools.', $message);
    }

    public function test_it_keeps_values_that_are_already_set(): void
    {
        [$saved, $message] = $this->runUpdate(['max_tools_per_turn' => 3, 'always_include_tools' => ['drupal_cache']]);

        self::assertSame([], $saved);
        self::assertSame('Both tool routing settings are already present, nothing to do.', $message);
    }

    private function runUpdate(array $stored): array
    {
        $saved = [];
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturnCallback(static fn (string $key): mixed => $stored[$key] ?? null);
        $config->method('set')->willReturnCallback(function (string $key, mixed $value) use (&$saved, $config): Config {
            $saved[$key] = $value;

            return $config;
        });
        $config->expects($this->once())->method('save')->willReturnSelf();
        $factory = $this->createMock(ConfigFactoryInterface::class);
        $factory->method('getEditable')->with('phpclaw.settings')->willReturn($config);
        $this->bootDrupalContainerWithUser(services: ['config.factory' => $factory]);

        $message = phpclaw_update_8006();

        return [$saved, $message];
    }
}

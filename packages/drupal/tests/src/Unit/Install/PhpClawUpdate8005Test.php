<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Unit\Install;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use PhpClaw\Drupal\Tests\Unit\Fixtures\BootsDrupalContainer;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../../../phpclaw.install';

final class PhpClawUpdate8005Test extends TestCase
{
    use BootsDrupalContainer;

    public function test_it_adds_every_missing_agent_primitive_setting_with_its_default(): void
    {
        $stored = ['provider' => 'ollama'];
        $saved = $this->runUpdate($stored);

        self::assertSame([
            'fallback_provider' => '',
            'fallback_model' => '',
            'fallback_api_key' => '',
            'rate_limit_rpm' => 0,
            'response_cache' => false,
            'response_cache_ttl' => 3600,
            'max_token_budget' => 0,
        ], $saved);
    }

    public function test_it_keeps_values_that_are_already_set(): void
    {
        $saved = $this->runUpdate(['fallback_provider' => 'groq', 'rate_limit_rpm' => 30, 'response_cache' => true]);

        self::assertArrayNotHasKey('fallback_provider', $saved);
        self::assertArrayNotHasKey('rate_limit_rpm', $saved);
        self::assertArrayNotHasKey('response_cache', $saved);
        self::assertSame(3600, $saved['response_cache_ttl']);
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

        self::assertIsString(phpclaw_update_8005());

        return $saved;
    }
}

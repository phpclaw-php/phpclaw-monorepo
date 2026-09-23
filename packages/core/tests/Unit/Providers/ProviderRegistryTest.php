<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Providers;

use PhpClaw\ClawConfig;
use PhpClaw\Config\ProviderConfig;
use PhpClaw\Exceptions\AdapterException;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Providers\ProviderRegistry;
use PHPUnit\Framework\TestCase;

final class ProviderRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        ProviderRegistry::reset();
    }

    protected function tearDown(): void
    {
        ProviderRegistry::reset();
    }

    private function makeConfig(string $provider = 'custom', string $apiKey = 'test-key'): ClawConfig
    {
        return new ClawConfig(provider: new ProviderConfig(apiKey: $apiKey, provider: $provider));
    }

    private function makeStubProvider(): ProviderInterface
    {
        return new class implements ProviderInterface
        {
            public function send(array $messages, array $tools = []): array
            {
                return ['type' => 'text', 'text' => 'stub response', 'input_tokens' => 1, 'output_tokens' => 1];
            }

            public function stream(array $messages, callable $onToken): string
            {
                return '';
            }

            public function name(): string
            {
                return 'stub';
            }

            public function model(): string
            {
                return 'stub-model';
            }
        };
    }

    public function test_has_returns_false_when_empty(): void
    {
        $this->assertFalse(ProviderRegistry::has('anthropic'));
    }

    public function test_has_returns_false_for_unknown_name(): void
    {
        $this->assertFalse(ProviderRegistry::has('nonexistent'));
    }

    public function test_register_class_name_and_has_returns_true(): void
    {
        $stub = get_class($this->makeStubProvider());
        ProviderRegistry::register('stub', function ($config) {
            return $this->makeStubProvider();
        });
        $this->assertTrue(ProviderRegistry::has('stub'));
    }

    public function test_register_nonexistent_class_throws(): void
    {
        $this->expectException(AdapterException::class);
        $this->expectExceptionMessageMatches('/not found/');
        ProviderRegistry::register('bad', 'NonExistentClassName');
    }

    public function test_register_class_not_implementing_interface_throws(): void
    {
        $this->expectException(AdapterException::class);
        $this->expectExceptionMessageMatches('/must implement/');
        ProviderRegistry::register('bad', \stdClass::class);
    }

    public function test_register_is_case_insensitive(): void
    {
        ProviderRegistry::register('MyAI', fn ($c) => $this->makeStubProvider());
        $this->assertTrue(ProviderRegistry::has('myai'));
        $this->assertTrue(ProviderRegistry::has('MYAI'));
        $this->assertTrue(ProviderRegistry::has('MyAI'));
    }

    public function test_register_callable_factory_and_has_returns_true(): void
    {
        ProviderRegistry::register('custom', fn ($config) => $this->makeStubProvider());
        $this->assertTrue(ProviderRegistry::has('custom'));
    }

    public function test_factory_returning_non_provider_throws_at_build(): void
    {
        ProviderRegistry::register('bad', fn ($config) => new \stdClass);
        $this->expectException(AdapterException::class);
        $this->expectExceptionMessageMatches('/must return/');
        ProviderRegistry::build('bad', $this->makeConfig('bad'));
    }

    public function test_build_returns_provider_from_factory(): void
    {
        $stub = $this->makeStubProvider();
        ProviderRegistry::register('custom', fn ($config) => $stub);

        $result = ProviderRegistry::build('custom', $this->makeConfig());

        $this->assertInstanceOf(ProviderInterface::class, $result);
        $this->assertSame('stub', $result->name());
    }

    public function test_build_passes_config_to_factory(): void
    {
        $receivedConfig = null;
        ProviderRegistry::register('custom', function ($config) use (&$receivedConfig) {
            $receivedConfig = $config;

            return $this->makeStubProvider();
        });

        $config = $this->makeConfig('custom', 'my-api-key');
        ProviderRegistry::build('custom', $config);

        $this->assertSame('my-api-key', $receivedConfig->apiKey);
    }

    public function test_build_unknown_name_throws(): void
    {
        $this->expectException(AdapterException::class);
        $this->expectExceptionMessageMatches('/No provider registered/');
        ProviderRegistry::build('unknown', $this->makeConfig('unknown'));
    }

    public function test_registered_provider_overrides_builtin_name(): void
    {
        $stub = $this->makeStubProvider();
        ProviderRegistry::register('anthropic', fn ($config) => $stub);

        $config = new ClawConfig(provider: new ProviderConfig(apiKey: 'sk-ant-test', provider: 'anthropic'));
        $provider = $config->buildProvider();

        $this->assertSame('stub', $provider->name(), 'Custom registry provider must override the built-in');
    }

    public function test_auto_detect_still_works_when_registry_empty(): void
    {
        $_ENV['ANTHROPIC_API_KEY'] = 'sk-ant-test';

        try {
            $config = new ClawConfig;
            $provider = $config->buildProvider();
            $this->assertSame('anthropic', $provider->name());
        } finally {
            unset($_ENV['ANTHROPIC_API_KEY']);
        }
    }

    public function test_custom_provider_does_not_require_api_key(): void
    {
        ProviderRegistry::register('nokey', fn ($config) => $this->makeStubProvider());

        $config = new ClawConfig(provider: new ProviderConfig(apiKey: '', provider: 'nokey'));
        $provider = $config->buildProvider();

        $this->assertSame('stub', $provider->name());
    }

    public function test_names_returns_registered_names(): void
    {
        ProviderRegistry::register('alpha', fn ($c) => $this->makeStubProvider());
        ProviderRegistry::register('beta', fn ($c) => $this->makeStubProvider());

        $names = ProviderRegistry::names();

        $this->assertContains('alpha', $names);
        $this->assertContains('beta', $names);
    }

    public function test_reset_clears_all_providers(): void
    {
        ProviderRegistry::register('custom', fn ($c) => $this->makeStubProvider());
        ProviderRegistry::reset();

        $this->assertFalse(ProviderRegistry::has('custom'));
        $this->assertSame([], ProviderRegistry::names());
    }
}

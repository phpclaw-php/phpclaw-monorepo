<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Memory;

use PhpClaw\ClawConfig;
use PhpClaw\Config\ProviderConfig;
use PhpClaw\Exceptions\MemoryException;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Memory\FileMemory;
use PhpClaw\Memory\MemoryRegistry;
use PHPUnit\Framework\TestCase;

final class MemoryRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        MemoryRegistry::reset();
    }

    protected function tearDown(): void
    {
        MemoryRegistry::reset();
    }

    public function test_file_driver_is_registered_by_default(): void
    {
        $this->assertTrue(MemoryRegistry::has('file'));
    }

    public function test_array_driver_is_registered_by_default(): void
    {
        $this->assertTrue(MemoryRegistry::has('array'));
    }

    public function test_default_drivers_list(): void
    {
        $drivers = MemoryRegistry::drivers();

        $this->assertContains('file', $drivers);
        $this->assertContains('array', $drivers);
    }

    public function test_build_array_driver_returns_array_memory(): void
    {
        $driver = MemoryRegistry::build('array');

        $this->assertInstanceOf(ArrayMemory::class, $driver);
    }

    public function test_build_file_driver_returns_file_memory(): void
    {
        $driver = MemoryRegistry::build('file');

        $this->assertInstanceOf(FileMemory::class, $driver);
    }

    public function test_build_with_no_argument_returns_file_memory(): void
    {
        $driver = MemoryRegistry::build();

        $this->assertInstanceOf(FileMemory::class, $driver);
    }

    public function test_has_returns_false_for_unregistered_driver(): void
    {
        $this->assertFalse(MemoryRegistry::has('redis'));
    }

    public function test_has_is_case_insensitive(): void
    {
        $this->assertTrue(MemoryRegistry::has('FILE'));
        $this->assertTrue(MemoryRegistry::has('File'));
        $this->assertTrue(MemoryRegistry::has('ARRAY'));
    }

    public function test_register_class_name_and_has_returns_true(): void
    {
        MemoryRegistry::register('custom-array', ArrayMemory::class);

        $this->assertTrue(MemoryRegistry::has('custom-array'));
    }

    public function test_register_nonexistent_class_throws(): void
    {
        $this->expectException(MemoryException::class);
        $this->expectExceptionMessageMatches('/not found/');
        MemoryRegistry::register('bad', 'NonExistentMemoryClass');
    }

    public function test_register_class_not_implementing_interface_throws(): void
    {
        $this->expectException(MemoryException::class);
        $this->expectExceptionMessageMatches('/must implement/');
        MemoryRegistry::register('bad', \stdClass::class);
    }

    public function test_register_is_case_insensitive(): void
    {
        MemoryRegistry::register('MyDB', fn () => new ArrayMemory);

        $this->assertTrue(MemoryRegistry::has('mydb'));
        $this->assertTrue(MemoryRegistry::has('MYDB'));
        $this->assertTrue(MemoryRegistry::has('MyDB'));
    }

    public function test_register_factory_callable_and_has_returns_true(): void
    {
        MemoryRegistry::register('custom', fn () => new ArrayMemory);

        $this->assertTrue(MemoryRegistry::has('custom'));
    }

    public function test_factory_returning_non_memory_throws_at_build(): void
    {
        MemoryRegistry::register('bad', fn () => new \stdClass);

        $this->expectException(MemoryException::class);
        $this->expectExceptionMessageMatches('/must return/');
        MemoryRegistry::build('bad');
    }

    public function test_build_returns_driver_from_factory(): void
    {
        $stub = new ArrayMemory;
        MemoryRegistry::register('custom', fn () => $stub);

        $result = MemoryRegistry::build('custom');

        $this->assertInstanceOf(MemoryInterface::class, $result);
        $this->assertSame($stub, $result);
    }

    public function test_build_unknown_driver_throws(): void
    {
        $this->expectException(MemoryException::class);
        $this->expectExceptionMessageMatches('/No memory driver registered/');
        MemoryRegistry::build('unknown');
    }

    public function test_build_unknown_driver_lists_available(): void
    {
        $this->expectException(MemoryException::class);
        $this->expectExceptionMessageMatches('/Available:/');
        MemoryRegistry::build('unknown');
    }

    public function test_build_by_class_name_returns_new_instance(): void
    {
        MemoryRegistry::register('arr', ArrayMemory::class);

        $result = MemoryRegistry::build('arr');

        $this->assertInstanceOf(ArrayMemory::class, $result);
    }

    public function test_build_each_call_returns_fresh_instance_from_factory(): void
    {
        MemoryRegistry::register('fresh', fn () => new ArrayMemory);

        $a = MemoryRegistry::build('fresh');
        $b = MemoryRegistry::build('fresh');

        $this->assertNotSame($a, $b);
    }

    public function test_reset_restores_built_in_drivers(): void
    {
        MemoryRegistry::register('custom', fn () => new ArrayMemory);
        MemoryRegistry::reset();

        $this->assertFalse(MemoryRegistry::has('custom'));
        $this->assertTrue(MemoryRegistry::has('file'));
        $this->assertTrue(MemoryRegistry::has('array'));
    }

    public function test_reset_does_not_wipe_to_empty(): void
    {
        MemoryRegistry::reset();

        $drivers = MemoryRegistry::drivers();

        $this->assertNotEmpty($drivers);
    }

    public function test_drivers_returns_all_registered_names(): void
    {
        MemoryRegistry::register('alpha', fn () => new ArrayMemory);
        MemoryRegistry::register('beta', fn () => new ArrayMemory);

        $drivers = MemoryRegistry::drivers();

        $this->assertContains('alpha', $drivers);
        $this->assertContains('beta', $drivers);
    }

    public function test_config_build_memory_returns_file_memory_by_default(): void
    {
        $config = new ClawConfig(provider: new ProviderConfig(apiKey: 'test-key', provider: 'anthropic'));
        $driver = $config->buildMemory();

        $this->assertInstanceOf(FileMemory::class, $driver);
    }

    public function test_config_build_memory_returns_array_memory(): void
    {
        $config = new ClawConfig(provider: new ProviderConfig(apiKey: 'test-key', provider: 'anthropic'));
        $driver = $config->buildMemory('array');

        $this->assertInstanceOf(ArrayMemory::class, $driver);
    }

    public function test_config_build_memory_throws_for_unknown_driver(): void
    {
        $config = new ClawConfig(provider: new ProviderConfig(apiKey: 'test-key', provider: 'anthropic'));

        $this->expectException(MemoryException::class);
        $config->buildMemory('nonexistent');
    }

    public function test_custom_driver_registered_by_adapter_is_buildable(): void
    {
        $customDriver = new class implements MemoryInterface
        {
            public function get(string $key, string $namespace = 'default'): mixed
            {
                return null;
            }

            public function set(string $key, mixed $value, string $namespace = 'default', ?int $ttl = null): void {}

            public function forget(string $key, string $namespace = 'default'): void {}

            public function flush(string $namespace = 'default'): void {}

            public function all(string $namespace = 'default'): array
            {
                return [];
            }

            public function has(string $key, string $namespace = 'default'): bool
            {
                return false;
            }
        };

        MemoryRegistry::register('eloquent', fn () => $customDriver);

        $result = MemoryRegistry::build('eloquent');

        $this->assertInstanceOf(MemoryInterface::class, $result);
        $this->assertSame($customDriver, $result);
    }

    public function test_custom_driver_overrides_built_in_name(): void
    {
        $custom = new ArrayMemory;
        MemoryRegistry::register('file', fn () => $custom);

        $result = MemoryRegistry::build('file');

        $this->assertSame($custom, $result);
    }
}

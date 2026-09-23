<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Integration;

use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Memory\FileMemory;

final class MemoryPersistenceIntegrationTest extends IntegrationTestCase
{
    public function test_file_memory_driver_is_resolvable(): void
    {
        IntegrationKernel::configure(['memory_driver' => 'file']);

        $kernel = self::bootKernel();
        $container = $kernel->getContainer();

        $memory = $container->get(MemoryInterface::class);

        $this->assertInstanceOf(MemoryInterface::class, $memory);
    }

    public function test_file_memory_driver_is_not_doctrine_when_driver_is_file(): void
    {
        IntegrationKernel::configure(['memory_driver' => 'file', 'store_messages' => false]);

        $kernel = self::bootKernel();
        $container = $kernel->getContainer();

        $memory = $container->get(MemoryInterface::class);

        $this->assertInstanceOf(FileMemory::class, $memory);
    }

    public function test_file_memory_persists_a_value(): void
    {
        $tmpDir = sys_get_temp_dir().'/phpclaw_mem_test_'.uniqid();
        mkdir($tmpDir, 0777, true);

        IntegrationKernel::configure(['memory_driver' => 'file', 'workspace_root' => $tmpDir]);

        $kernel = self::bootKernel();
        $container = $kernel->getContainer();

        /** @var MemoryInterface $memory */
        $memory = $container->get(MemoryInterface::class);

        $testKey = 'integration_test_key_'.uniqid();
        $memory->set($testKey, ['ping' => 'pong'], 'test_ns');

        $value = $memory->get($testKey, 'test_ns');
        $this->assertSame(['ping' => 'pong'], $value, 'File memory must persist and retrieve a value.');

        $memory->forget($testKey, 'test_ns');
        $this->assertNull($memory->get($testKey, 'test_ns'));
    }

    public function test_memory_driver_switch_to_array_is_resolvable(): void
    {
        IntegrationKernel::configure(['memory_driver' => 'array']);

        $kernel = self::bootKernel();
        $container = $kernel->getContainer();

        $memory = $container->get(MemoryInterface::class);

        $this->assertInstanceOf(MemoryInterface::class, $memory);
    }
}

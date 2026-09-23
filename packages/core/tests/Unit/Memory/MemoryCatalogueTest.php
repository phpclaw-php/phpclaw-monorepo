<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Memory;

use PhpClaw\AutoDiscovery\ComposerExtras;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Memory\MemoryCatalogue;
use PhpClaw\Memory\MemoryRegistry;
use PhpClaw\Memory\RedisMemory;
use PHPUnit\Framework\TestCase;

final class MemoryCatalogueTest extends TestCase
{
    protected function setUp(): void
    {
        MemoryCatalogue::reset();
        ComposerExtras::reset();
    }

    protected function tearDown(): void
    {
        MemoryCatalogue::reset();
        ComposerExtras::reset();
    }

    public function test_built_in_redis_present(): void
    {
        $all = MemoryCatalogue::all();
        $this->assertArrayHasKey('redis', $all);
        $this->assertSame('Redis', $all['redis']['label']);
        $this->assertSame(RedisMemory::class, $all['redis']['class']);
    }

    public function test_find_returns_built_in(): void
    {
        $entry = MemoryCatalogue::find('redis');
        $this->assertNotNull($entry);
        $this->assertSame(RedisMemory::class, $entry['class']);
    }

    public function test_keys_includes_built_in(): void
    {
        $this->assertContains('redis', MemoryCatalogue::keys());
    }

    public function test_register_adds_custom_entry(): void
    {
        MemoryCatalogue::register('test-mem', 'Test Memory', RedisMemory::class);
        $this->assertNotNull(MemoryCatalogue::find('test-mem'));
    }

    public function test_register_accepts_optional_factory(): void
    {
        MemoryCatalogue::register('with-factory', 'Factory Memory', RedisMemory::class, 'Some\\FactoryClass::create');
        $entry = MemoryCatalogue::find('with-factory');
        $this->assertSame('Some\\FactoryClass::create', $entry['factory']);
    }

    public function test_boot_registers_valid_entry(): void
    {
        ComposerExtras::withTestPayload([
            'alice/phpclaw-memory-mongo' => [
                'memory' => [
                    'mongo' => ['label' => 'MongoDB', 'class' => RedisMemory::class],
                ],
            ],
        ]);

        MemoryCatalogue::boot();

        $this->assertNotNull(MemoryCatalogue::find('mongo'));
        $this->assertSame('MongoDB', MemoryCatalogue::find('mongo')['label']);
    }

    public function test_boot_skips_missing_label(): void
    {
        ComposerExtras::withTestPayload([
            'bad/pkg' => ['memory' => ['ghost' => ['class' => RedisMemory::class]]],
        ]);
        MemoryCatalogue::boot();
        $this->assertNull(MemoryCatalogue::find('ghost'));
    }

    public function test_boot_skips_nonexistent_class(): void
    {
        ComposerExtras::withTestPayload([
            'bad/pkg' => ['memory' => ['ghost' => ['label' => 'X', 'class' => 'Does\\Not\\Exist']]],
        ]);
        MemoryCatalogue::boot();
        $this->assertNull(MemoryCatalogue::find('ghost'));
    }

    public function test_boot_skips_class_not_implementing_memory_interface(): void
    {
        ComposerExtras::withTestPayload([
            'bad/pkg' => ['memory' => ['bad' => ['label' => 'Bad', 'class' => \stdClass::class]]],
        ]);
        MemoryCatalogue::boot();
        $this->assertNull(MemoryCatalogue::find('bad'));
    }

    public function test_reset_clears_customs_keeps_builtin(): void
    {
        MemoryCatalogue::register('temp', 'Temp', RedisMemory::class);
        MemoryCatalogue::reset();
        $this->assertNull(MemoryCatalogue::find('temp'));
        $this->assertNotNull(MemoryCatalogue::find('redis'));
    }

    public function test_activate_all_registers_every_entry_into_memory_registry(): void
    {
        MemoryRegistry::reset();

        MemoryCatalogue::register('test-mem-1', 'Test 1', RedisMemory::class);

        MemoryCatalogue::activateAll();

        $this->assertTrue(MemoryRegistry::has('redis'));
        $this->assertTrue(MemoryRegistry::has('test-mem-1'));

        MemoryRegistry::reset();
    }

    public function test_activate_all_invokes_factory_when_provided(): void
    {
        MemoryRegistry::reset();

        $factoryCalled = false;
        $factoryConfig = null;

        $factory = function (array $config) use (&$factoryCalled, &$factoryConfig): ArrayMemory {
            $factoryCalled = true;
            $factoryConfig = $config;

            return new ArrayMemory;
        };

        MemoryCatalogue::register('factory-mem', 'Factory Memory', RedisMemory::class, $factory);
        MemoryCatalogue::activateAll(['factory-mem' => ['url' => 'redis://x']]);

        $driver = MemoryRegistry::build('factory-mem');

        $this->assertTrue($factoryCalled);
        $this->assertSame(['url' => 'redis://x'], $factoryConfig);
        $this->assertInstanceOf(ArrayMemory::class, $driver);

        MemoryRegistry::reset();
    }
}

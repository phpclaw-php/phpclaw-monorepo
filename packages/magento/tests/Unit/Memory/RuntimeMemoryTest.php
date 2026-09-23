<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Memory;

use PhpClaw\Magento\Factory\PhpClawFactoryInterface;
use PhpClaw\Magento\Memory\RuntimeMemory;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class RuntimeMemoryTest extends TestCase
{
    private PhpClawFactoryInterface&MockObject $factory;

    private MemoryInterface&MockObject $innerMemory;

    private RuntimeMemory $memory;

    protected function setUp(): void
    {
        $this->factory = $this->createMock(PhpClawFactoryInterface::class);
        $this->innerMemory = $this->createMock(MemoryInterface::class);

        $this->factory->method('resolveMemory')->willReturn($this->innerMemory);

        $this->memory = new RuntimeMemory($this->factory);
    }

    public function test_get_delegates_to_resolved_memory(): void
    {
        $this->innerMemory->method('get')->with('key', 'ns')->willReturn('value');

        self::assertSame('value', $this->memory->get('key', 'ns'));
    }

    public function test_get_uses_default_namespace(): void
    {
        $this->innerMemory->method('get')->with('key', 'default')->willReturn('v');

        self::assertSame('v', $this->memory->get('key'));
    }

    public function test_set_delegates_to_resolved_memory(): void
    {
        $this->innerMemory->expects(self::once())
            ->method('set')
            ->with('key', 'val', 'ns', 60);

        $this->memory->set('key', 'val', 'ns', 60);
    }

    public function test_set_uses_default_namespace_and_no_ttl(): void
    {
        $this->innerMemory->expects(self::once())
            ->method('set')
            ->with('key', 'val', 'default', null);

        $this->memory->set('key', 'val');
    }

    public function test_forget_delegates_to_resolved_memory(): void
    {
        $this->innerMemory->expects(self::once())
            ->method('forget')
            ->with('key', 'ns');

        $this->memory->forget('key', 'ns');
    }

    public function test_flush_delegates_to_resolved_memory(): void
    {
        $this->innerMemory->expects(self::once())
            ->method('flush')
            ->with('ns');

        $this->memory->flush('ns');
    }

    public function test_all_delegates_to_resolved_memory(): void
    {
        $this->innerMemory->method('all')->with('ns')->willReturn(['k' => 'v']);

        self::assertSame(['k' => 'v'], $this->memory->all('ns'));
    }

    public function test_has_delegates_to_resolved_memory(): void
    {
        $this->innerMemory->method('has')->with('key', 'ns')->willReturn(true);

        self::assertTrue($this->memory->has('key', 'ns'));
    }

    public function test_has_returns_false_when_inner_returns_false(): void
    {
        $this->innerMemory->method('has')->willReturn(false);

        self::assertFalse($this->memory->has('missing', 'ns'));
    }

    public function test_each_call_resolves_memory_fresh_from_factory(): void
    {
        $this->factory->expects(self::exactly(2))
            ->method('resolveMemory')
            ->willReturn($this->innerMemory);

        $this->innerMemory->method('get')->willReturn(null);

        $this->memory->get('a');
        $this->memory->get('b');
    }
}

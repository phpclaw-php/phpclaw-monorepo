<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Memory;

use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Memory\PrivacyAwareMemory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class PrivacyAwareMemoryTest extends TestCase
{
    private MemoryInterface&MockObject $inner;

    protected function setUp(): void
    {
        $this->inner = $this->createMock(MemoryInterface::class);
    }

    public function test_get_delegates_to_inner(): void
    {
        $this->inner->method('get')->with('key', 'ns')->willReturn('value');

        $memory = new PrivacyAwareMemory($this->inner, true);

        self::assertSame('value', $memory->get('key', 'ns'));
    }

    public function test_has_delegates_to_inner(): void
    {
        $this->inner->method('has')->with('key', 'ns')->willReturn(true);

        $memory = new PrivacyAwareMemory($this->inner, true);

        self::assertTrue($memory->has('key', 'ns'));
    }

    public function test_all_delegates_to_inner(): void
    {
        $this->inner->method('all')->with('ns')->willReturn(['k' => 'v']);

        $memory = new PrivacyAwareMemory($this->inner, true);

        self::assertSame(['k' => 'v'], $memory->all('ns'));
    }

    public function test_forget_delegates_to_inner(): void
    {
        $this->inner->expects(self::once())->method('forget')->with('key', 'ns');

        $memory = new PrivacyAwareMemory($this->inner, true);
        $memory->forget('key', 'ns');
    }

    public function test_flush_delegates_to_inner(): void
    {
        $this->inner->expects(self::once())->method('flush')->with('ns');

        $memory = new PrivacyAwareMemory($this->inner, true);
        $memory->flush('ns');
    }

    public function test_set_passes_through_when_store_messages_true(): void
    {
        $data = ['id' => 'abc', 'history' => [['role' => 'user', 'content' => 'hello']], 'title' => 'hello'];

        $this->inner->expects(self::once())
            ->method('set')
            ->with('abc', $data, 'conversations', null);

        $memory = new PrivacyAwareMemory($this->inner, true);
        $memory->set('abc', $data, 'conversations');
    }

    public function test_set_is_noop_when_store_messages_false(): void
    {
        $this->inner->expects(self::never())->method('set');

        $memory = new PrivacyAwareMemory($this->inner, false);
        $memory->set('abc', ['history' => [], 'title' => 'x'], 'conversations');
    }

    public function test_set_is_noop_when_store_messages_false_any_namespace(): void
    {
        $this->inner->expects(self::never())->method('set');

        $memory = new PrivacyAwareMemory($this->inner, false);
        $memory->set('key', 'value', 'default');
    }

    public function test_set_passes_ttl_to_inner(): void
    {
        $this->inner->expects(self::once())
            ->method('set')
            ->with('key', 'value', 'default', 300);

        $memory = new PrivacyAwareMemory($this->inner, true);
        $memory->set('key', 'value', 'default', 300);
    }

    public function test_store_messages_returns_configured_value(): void
    {
        self::assertTrue((new PrivacyAwareMemory($this->inner, true))->storeMessages());
        self::assertFalse((new PrivacyAwareMemory($this->inner, false))->storeMessages());
    }

    public function test_inner_returns_wrapped_driver(): void
    {
        $memory = new PrivacyAwareMemory($this->inner, true);

        self::assertSame($this->inner, $memory->inner());
    }
}

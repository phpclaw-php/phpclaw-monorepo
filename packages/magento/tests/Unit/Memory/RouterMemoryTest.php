<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Memory;

use PhpClaw\Magento\Memory\RouterMemory;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class RouterMemoryTest extends TestCase
{
    private MemoryInterface&MockObject $conversationMemory;

    private MemoryInterface&MockObject $resourceMemory;

    private RouterMemory $router;

    protected function setUp(): void
    {
        $this->conversationMemory = $this->createMock(MemoryInterface::class);
        $this->resourceMemory = $this->createMock(MemoryInterface::class);
        $this->router = new RouterMemory($this->conversationMemory, $this->resourceMemory);
    }

    public function test_get_routes_conversations_to_conversation_memory(): void
    {
        $history = [['role' => 'user', 'content' => 'Hi']];

        $this->conversationMemory->expects(self::once())->method('get')
            ->with('conv01', 'conversations')
            ->willReturn($history);

        $this->resourceMemory->expects(self::never())->method('get');

        self::assertSame($history, $this->router->get('conv01', 'conversations'));
    }

    public function test_set_routes_conversations_to_conversation_memory(): void
    {
        $messages = [['role' => 'user', 'content' => 'Hello']];

        $this->conversationMemory->expects(self::once())->method('set')
            ->with('conv01', $messages, 'conversations', null);

        $this->resourceMemory->expects(self::never())->method('set');

        $this->router->set('conv01', $messages, 'conversations');
    }

    public function test_forget_routes_conversations_to_conversation_memory(): void
    {
        $this->conversationMemory->expects(self::once())->method('forget')
            ->with('conv01', 'conversations');

        $this->resourceMemory->expects(self::never())->method('forget');

        $this->router->forget('conv01', 'conversations');
    }

    public function test_flush_routes_conversations_to_conversation_memory(): void
    {
        $this->conversationMemory->expects(self::once())->method('flush')
            ->with('conversations');

        $this->resourceMemory->expects(self::never())->method('flush');

        $this->router->flush('conversations');
    }

    public function test_all_routes_conversations_to_conversation_memory(): void
    {
        $this->conversationMemory->expects(self::once())->method('all')
            ->with('conversations')
            ->willReturn([]);

        $this->resourceMemory->expects(self::never())->method('all');

        $this->router->all('conversations');
    }

    public function test_has_routes_conversations_to_conversation_memory(): void
    {
        $this->conversationMemory->expects(self::once())->method('has')
            ->with('conv01', 'conversations')
            ->willReturn(true);

        $this->resourceMemory->expects(self::never())->method('has');

        self::assertTrue($this->router->has('conv01', 'conversations'));
    }

    public function test_get_routes_default_namespace_to_resource_memory(): void
    {
        $this->resourceMemory->expects(self::once())->method('get')
            ->with('key', 'default')
            ->willReturn('value');

        $this->conversationMemory->expects(self::never())->method('get');

        self::assertSame('value', $this->router->get('key', 'default'));
    }

    public function test_set_routes_custom_namespace_to_resource_memory(): void
    {
        $this->resourceMemory->expects(self::once())->method('set')
            ->with('key', 'val', 'cache_ns', null);

        $this->conversationMemory->expects(self::never())->method('set');

        $this->router->set('key', 'val', 'cache_ns');
    }

    public function test_forget_routes_other_namespace_to_resource_memory(): void
    {
        $this->resourceMemory->expects(self::once())->method('forget')
            ->with('key', 'jobs');

        $this->conversationMemory->expects(self::never())->method('forget');

        $this->router->forget('key', 'jobs');
    }

    public function test_flush_routes_other_namespace_to_resource_memory(): void
    {
        $this->resourceMemory->expects(self::once())->method('flush')
            ->with('phpclaw_jobs');

        $this->conversationMemory->expects(self::never())->method('flush');

        $this->router->flush('phpclaw_jobs');
    }

    public function test_all_routes_default_to_resource_memory(): void
    {
        $this->resourceMemory->expects(self::once())->method('all')
            ->with('default')
            ->willReturn(['key' => 'val']);

        $this->conversationMemory->expects(self::never())->method('all');

        $result = $this->router->all('default');
        self::assertSame(['key' => 'val'], $result);
    }

    public function test_has_routes_default_to_resource_memory(): void
    {
        $this->resourceMemory->expects(self::once())->method('has')
            ->with('key', 'default')
            ->willReturn(false);

        $this->conversationMemory->expects(self::never())->method('has');

        self::assertFalse($this->router->has('key', 'default'));
    }
}

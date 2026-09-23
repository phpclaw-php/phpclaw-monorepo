<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Unit\Memory;

use PhpClaw\Joomla\Component\Administrator\Memory\JoomlaDbRouterMemory;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PHPUnit\Framework\TestCase;

final class JoomlaDbRouterMemoryTest extends TestCase
{
    public function test_class_implements_memory_interface(): void
    {
        $this->assertTrue(
            is_a(JoomlaDbRouterMemory::class, MemoryInterface::class, true),
        );
    }

    public function test_class_is_final(): void
    {
        $ref = new \ReflectionClass(JoomlaDbRouterMemory::class);
        $this->assertTrue($ref->isFinal());
    }

    public function test_get_routes_conversations_namespace_to_conversation_driver(): void
    {
        $conversations = $this->createMock(MemoryInterface::class);
        $generic = $this->createMock(MemoryInterface::class);

        $conversations->expects($this->once())
            ->method('get')
            ->with('conv-123', 'conversations')
            ->willReturn(['id' => 'conv-123', 'history' => []]);

        $generic->expects($this->never())->method('get');

        $router = new JoomlaDbRouterMemory($conversations, $generic);
        $result = $router->get('conv-123', 'conversations');

        $this->assertSame(['id' => 'conv-123', 'history' => []], $result);
    }

    public function test_get_routes_default_namespace_to_generic_driver(): void
    {
        $conversations = $this->createMock(MemoryInterface::class);
        $generic = $this->createMock(MemoryInterface::class);

        $conversations->expects($this->never())->method('get');

        $generic->expects($this->once())
            ->method('get')
            ->with('my-key', 'default')
            ->willReturn('some-value');

        $router = new JoomlaDbRouterMemory($conversations, $generic);
        $result = $router->get('my-key', 'default');

        $this->assertSame('some-value', $result);
    }

    public function test_set_routes_conversations_namespace_to_conversation_driver(): void
    {
        $conversations = $this->createMock(MemoryInterface::class);
        $generic = $this->createMock(MemoryInterface::class);

        $conversations->expects($this->once())
            ->method('set')
            ->with('conv-456', ['history' => []], 'conversations', null);

        $generic->expects($this->never())->method('set');

        $router = new JoomlaDbRouterMemory($conversations, $generic);
        $router->set('conv-456', ['history' => []], 'conversations');
    }

    public function test_set_routes_other_namespace_to_generic_driver(): void
    {
        $conversations = $this->createMock(MemoryInterface::class);
        $generic = $this->createMock(MemoryInterface::class);

        $conversations->expects($this->never())->method('set');

        $generic->expects($this->once())
            ->method('set')
            ->with('job-1', ['status' => 'pending'], 'phpclaw_jobs', 3600);

        $router = new JoomlaDbRouterMemory($conversations, $generic);
        $router->set('job-1', ['status' => 'pending'], 'phpclaw_jobs', 3600);
    }

    public function test_forget_routes_by_namespace(): void
    {
        $conversations = $this->createMock(MemoryInterface::class);
        $generic = $this->createMock(MemoryInterface::class);

        $conversations->expects($this->once())->method('forget')->with('conv-1', 'conversations');
        $generic->expects($this->once())->method('forget')->with('key-1', 'default');

        $router = new JoomlaDbRouterMemory($conversations, $generic);
        $router->forget('conv-1', 'conversations');
        $router->forget('key-1', 'default');
    }

    public function test_flush_routes_by_namespace(): void
    {
        $conversations = $this->createMock(MemoryInterface::class);
        $generic = $this->createMock(MemoryInterface::class);

        $conversations->expects($this->once())->method('flush')->with('conversations');
        $generic->expects($this->once())->method('flush')->with('default');

        $router = new JoomlaDbRouterMemory($conversations, $generic);
        $router->flush('conversations');
        $router->flush('default');
    }

    public function test_all_routes_by_namespace(): void
    {
        $conversations = $this->createMock(MemoryInterface::class);
        $generic = $this->createMock(MemoryInterface::class);

        $conversations->expects($this->once())
            ->method('all')
            ->with('conversations')
            ->willReturn(['conv-1' => ['title' => 'Test']]);

        $generic->expects($this->once())
            ->method('all')
            ->with('phpclaw_jobs')
            ->willReturn(['job-1' => ['status' => 'done']]);

        $router = new JoomlaDbRouterMemory($conversations, $generic);

        $this->assertSame(['conv-1' => ['title' => 'Test']], $router->all('conversations'));
        $this->assertSame(['job-1' => ['status' => 'done']], $router->all('phpclaw_jobs'));
    }

    public function test_has_routes_by_namespace(): void
    {
        $conversations = $this->createMock(MemoryInterface::class);
        $generic = $this->createMock(MemoryInterface::class);

        $conversations->expects($this->once())->method('has')->with('conv-1', 'conversations')->willReturn(true);
        $generic->expects($this->once())->method('has')->with('key-1', 'default')->willReturn(false);

        $router = new JoomlaDbRouterMemory($conversations, $generic);

        $this->assertTrue($router->has('conv-1', 'conversations'));
        $this->assertFalse($router->has('key-1', 'default'));
    }

    public function test_routes_to_conversations_predicate(): void
    {
        $router = new JoomlaDbRouterMemory(
            $this->createMock(MemoryInterface::class),
            $this->createMock(MemoryInterface::class),
        );

        $this->assertTrue($router->routesToConversations('conversations'));
        $this->assertFalse($router->routesToConversations('default'));
        $this->assertFalse($router->routesToConversations('phpclaw_jobs'));
    }
}

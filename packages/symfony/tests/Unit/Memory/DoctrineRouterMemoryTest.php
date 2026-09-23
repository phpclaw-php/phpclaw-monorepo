<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit\Memory;

use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Symfony\Memory\DoctrineRouterMemory;
use PHPUnit\Framework\TestCase;

final class DoctrineRouterMemoryTest extends TestCase
{
    public function test_conversations_namespace_routes_to_conversation_driver(): void
    {
        $conv = $this->createMock(MemoryInterface::class);
        $kv = $this->createMock(MemoryInterface::class);

        $conv->expects(self::once())->method('get')->with('c1', 'conversations')->willReturn(['id' => 'c1']);

        $router = new DoctrineRouterMemory($conv, $kv);
        self::assertSame(['id' => 'c1'], $router->get('c1', 'conversations'));
    }

    public function test_non_conversations_namespace_routes_to_kv_driver(): void
    {
        $conv = $this->createMock(MemoryInterface::class);
        $kv = $this->createMock(MemoryInterface::class);

        $kv->expects(self::once())->method('get')->with('foo', 'default')->willReturn('bar');

        $router = new DoctrineRouterMemory($conv, $kv);
        self::assertSame('bar', $router->get('foo', 'default'));
    }

    public function test_set_routes_by_namespace(): void
    {
        $conv = $this->createMock(MemoryInterface::class);
        $kv = $this->createMock(MemoryInterface::class);

        $conv->expects(self::once())->method('set')->with('c1', ['id' => 'c1'], 'conversations', null);
        $kv->expects(self::once())->method('set')->with('k1', 'v1', 'default', 60);

        $router = new DoctrineRouterMemory($conv, $kv);
        $router->set('c1', ['id' => 'c1'], 'conversations');
        $router->set('k1', 'v1', 'default', 60);
    }

    public function test_forget_routes_by_namespace(): void
    {
        $conv = $this->createMock(MemoryInterface::class);
        $kv = $this->createMock(MemoryInterface::class);

        $conv->expects(self::once())->method('forget')->with('c1', 'conversations');
        $kv->expects(self::once())->method('forget')->with('k1', 'default');

        $router = new DoctrineRouterMemory($conv, $kv);
        $router->forget('c1', 'conversations');
        $router->forget('k1', 'default');
    }

    public function test_all_and_has_route_by_namespace(): void
    {
        $conv = $this->createMock(MemoryInterface::class);
        $kv = $this->createMock(MemoryInterface::class);

        $conv->expects(self::once())->method('all')->with('conversations')->willReturn(['c1' => []]);
        $kv->expects(self::once())->method('has')->with('k1', 'default')->willReturn(true);

        $router = new DoctrineRouterMemory($conv, $kv);

        self::assertSame(['c1' => []], $router->all('conversations'));
        self::assertTrue($router->has('k1', 'default'));
    }

    public function test_flush_routes_conversations_to_conversation_driver(): void
    {
        $conv = $this->createMock(MemoryInterface::class);
        $kv = $this->createMock(MemoryInterface::class);

        $conv->expects(self::once())->method('flush')->with('conversations');
        $kv->expects(self::never())->method('flush');

        $router = new DoctrineRouterMemory($conv, $kv);
        $router->flush('conversations');
    }

    public function test_flush_routes_default_to_kv_driver(): void
    {
        $conv = $this->createMock(MemoryInterface::class);
        $kv = $this->createMock(MemoryInterface::class);

        $kv->expects(self::once())->method('flush')->with('default');
        $conv->expects(self::never())->method('flush');

        $router = new DoctrineRouterMemory($conv, $kv);
        $router->flush('default');
    }
}

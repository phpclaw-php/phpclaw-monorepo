<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Memory;

use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Memory\RouterMemory;
use PHPUnit\Framework\TestCase;

final class RouterMemoryTest extends TestCase
{
    public function test_implements_memory_interface(): void
    {
        $router = new RouterMemory(new ArrayMemory);
        $this->assertInstanceOf(MemoryInterface::class, $router);
    }

    public function test_default_driver_used_when_no_routes(): void
    {
        $default = new ArrayMemory;
        $router = new RouterMemory($default);

        $router->set('k', 'v');

        $this->assertSame('v', $default->get('k'));
    }

    public function test_routed_namespace_uses_matching_driver(): void
    {
        $default = new ArrayMemory;
        $conversations = new ArrayMemory;
        $router = new RouterMemory($default, ['conversations' => $conversations]);

        $router->set('msg1', 'hello', 'conversations');

        $this->assertSame('hello', $conversations->get('msg1', 'conversations'));
        $this->assertNull($default->get('msg1', 'conversations'));
    }

    public function test_unrouted_namespace_falls_back_to_default(): void
    {
        $default = new ArrayMemory;
        $conversations = new ArrayMemory;
        $router = new RouterMemory($default, ['conversations' => $conversations]);

        $router->set('config-key', 'config-value', 'settings');

        $this->assertSame('config-value', $default->get('config-key', 'settings'));
        $this->assertNull($conversations->get('config-key', 'settings'));
    }

    public function test_get_routes_correctly(): void
    {
        $default = new ArrayMemory;
        $conversations = new ArrayMemory;
        $router = new RouterMemory($default, ['conversations' => $conversations]);

        $conversations->set('msg1', 'hello', 'conversations');
        $default->set('cfg', 'val', 'default');

        $this->assertSame('hello', $router->get('msg1', 'conversations'));
        $this->assertSame('val', $router->get('cfg'));
    }

    public function test_forget_routes_correctly(): void
    {
        $default = new ArrayMemory;
        $conversations = new ArrayMemory;
        $router = new RouterMemory($default, ['conversations' => $conversations]);

        $conversations->set('msg1', 'hello', 'conversations');
        $router->forget('msg1', 'conversations');

        $this->assertNull($conversations->get('msg1', 'conversations'));
    }

    public function test_flush_routes_to_namespace_driver_only(): void
    {
        $default = new ArrayMemory;
        $conversations = new ArrayMemory;
        $router = new RouterMemory($default, ['conversations' => $conversations]);

        $default->set('k1', 'v1', 'default');
        $conversations->set('msg1', 'hello', 'conversations');

        $router->flush('conversations');

        $this->assertSame([], $conversations->all('conversations'));
        $this->assertSame(['k1' => 'v1'], $default->all('default'));
    }

    public function test_all_routes_to_namespace_driver(): void
    {
        $default = new ArrayMemory;
        $conversations = new ArrayMemory;
        $router = new RouterMemory($default, ['conversations' => $conversations]);

        $conversations->set('a', 1, 'conversations');
        $conversations->set('b', 2, 'conversations');

        $this->assertSame(['a' => 1, 'b' => 2], $router->all('conversations'));
        $this->assertSame([], $router->all('settings'));
    }

    public function test_has_routes_correctly(): void
    {
        $default = new ArrayMemory;
        $conversations = new ArrayMemory;
        $router = new RouterMemory($default, ['conversations' => $conversations]);

        $conversations->set('exists', 'yes', 'conversations');

        $this->assertTrue($router->has('exists', 'conversations'));
        $this->assertFalse($router->has('exists', 'default'));
    }

    public function test_driver_for_returns_matched_driver(): void
    {
        $default = new ArrayMemory;
        $conversations = new ArrayMemory;
        $router = new RouterMemory($default, ['conversations' => $conversations]);

        $this->assertSame($conversations, $router->driverFor('conversations'));
        $this->assertSame($default, $router->driverFor('anything-else'));
    }

    public function test_routes_accessor_returns_only_explicit_routes(): void
    {
        $default = new ArrayMemory;
        $conversations = new ArrayMemory;
        $messages = new ArrayMemory;
        $router = new RouterMemory($default, [
            'conversations' => $conversations,
            'messages' => $messages,
        ]);

        $this->assertSame(
            ['conversations' => $conversations, 'messages' => $messages],
            $router->routes(),
        );
    }

    public function test_default_driver_accessor(): void
    {
        $default = new ArrayMemory;
        $router = new RouterMemory($default);

        $this->assertSame($default, $router->defaultDriver());
    }

    public function test_set_with_ttl_routes_correctly(): void
    {
        $default = new ArrayMemory;
        $hot = new ArrayMemory;
        $router = new RouterMemory($default, ['cache' => $hot]);

        $router->set('expiring', 'soon', 'cache', 60);

        $this->assertSame('soon', $hot->get('expiring', 'cache'));
    }
}
